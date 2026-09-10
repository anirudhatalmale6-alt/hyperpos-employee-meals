<?php

namespace EmployeeMeals\Support;

use EmployeeMeals\Models\Employee;
use EmployeeMeals\Models\Fingerprint;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Bridge to the Python minutiae extractor and matcher.
 *
 * Why Python at all: the DigitalPersona JavaScript SDK only CAPTURES. Comparing
 * two prints is FingerJet, a Windows native library from the paid SDK, so on a
 * Linux web host the comparison has to be ours. See resources/python/fpmatch.py.
 *
 * Extraction runs once per captured sample and the result is stored, so a scan
 * at the till is only the cheap half of the work.
 */
class FingerprintMatcher
{
    private const EXTRACT_TIMEOUT = 30;
    private const MATCH_TIMEOUT = 60;

    /**
     * Can this server actually run the matcher?
     *
     * Called by the enrolment screen so the failure names itself instead of
     * surfacing as "nothing happened" - shared hosting often disables
     * proc_open, and NumPy/OpenCV are frequently absent.
     *
     * @return array{ok: bool, binary: ?string, detail: string}
     */
    public function diagnostics(): array
    {
        if (! function_exists('proc_open')) {
            return [
                'ok' => false,
                'binary' => null,
                'detail' => 'proc_open is disabled on this server, so no external program can be run.',
            ];
        }

        $tried = [];

        foreach ($this->candidateBinaries() as $binary) {
            $tried[] = $binary;

            try {
                $result = $this->python(20)->run([
                    $binary, '-c', 'import cv2, numpy; print(cv2.__version__, numpy.__version__)',
                ]);
            } catch (Throwable $e) {
                continue;
            }

            if ($result->successful()) {
                return [
                    'ok' => true,
                    'binary' => $binary,
                    'detail' => 'OpenCV and NumPy found at '.$binary.' ('.trim($result->output()).').',
                ];
            }
        }

        return [
            'ok' => false,
            'binary' => null,
            // Name what was tried. "Not found" on its own leaves an operator
            // with nowhere to go; the list plus the setting to change is
            // something they can act on or hand to their host.
            'detail' => 'No Python with OpenCV and NumPy was found. Fingerprints can still be '
                .'enrolled and stored; identification needs this before it will work. '
                .'Tried: '.implode(', ', $tried).'. '
                .($this->pythonPath() !== null
                    ? 'Package folder: '.$this->pythonPath().'. '
                    : 'No package folder is configured. On shared hosting the packages usually '
                      .'live in a home directory rather than system wide - install them with '
                      .'pip install --target and set EMPLOYEE_MEALS_PYTHONPATH to that folder. ')
                .'The interpreter itself can be set with EMPLOYEE_MEALS_PYTHON.',
        ];
    }

    /**
     * A process runner carrying PYTHONPATH when the packages are not system wide.
     *
     * ⚠️ Without this the interpreter is found but `import cv2` fails, and the
     * whole thing reports "no Python found" while Python is sitting right
     * there. That is precisely what happened on this client's second server.
     */
    private function python(int $timeout)
    {
        $process = Process::timeout($timeout);
        $path = $this->pythonPath();

        return $path === null ? $process : $process->env(['PYTHONPATH' => $path]);
    }

    private function pythonPath(): ?string
    {
        $configured = config('plugin.employee-meals.meals.python_path');

        return is_string($configured) && trim($configured) !== '' ? trim($configured) : null;
    }

    /**
     * Where to look for Python, configured path first.
     *
     * @return list<string>
     */
    private function candidateBinaries(): array
    {
        $configured = config('plugin.employee-meals.meals.python_binary');
        $candidates = (array) config('plugin.employee-meals.meals.python_candidates', []);

        if (is_string($configured) && trim($configured) !== '') {
            array_unshift($candidates, trim($configured));
        }

        return array_values(array_unique(array_filter($candidates, 'is_string')));
    }

    /**
     * Turn a captured PNG into a stored template.
     *
     * @return array{count: int, usable: bool, minutiae: array<int, array<string, mixed>>}
     */
    public function extract(string $pngBytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'fp_').'.png';
        file_put_contents($path, $pngBytes);

        try {
            return $this->run(['extract', $path], self::EXTRACT_TIMEOUT);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Identification, not verification: search the population and say who this
     * is. Nobody types a name first - the operator just puts a finger down.
     *
     * @return array{
     *     decision: string, employee_id: ?int, score: int, runner_up: int,
     *     margin: int, accept_threshold: int
     * }
     */
    public function identify(array $probeTemplate, ?int $companyId = null): array
    {
        $candidates = $this->candidates($companyId);

        if ($candidates === []) {
            return [
                'decision' => 'reject',
                'employee_id' => null,
                'score' => 0,
                'runner_up' => 0,
                'margin' => 0,
                'accept_threshold' => 0,
            ];
        }

        $probePath = tempnam(sys_get_temp_dir(), 'fp_probe_').'.json';
        $candPath = tempnam(sys_get_temp_dir(), 'fp_cand_').'.json';
        file_put_contents($probePath, json_encode($probeTemplate));
        file_put_contents($candPath, json_encode($candidates));

        try {
            $out = $this->run(['match', $probePath, $candPath], self::MATCH_TIMEOUT);
        } finally {
            @unlink($probePath);
            @unlink($candPath);
        }

        return [
            'decision' => (string) ($out['decision'] ?? 'reject'),
            'employee_id' => $out['best']['id'] ?? null,
            'score' => (int) ($out['best']['score'] ?? 0),
            'runner_up' => (int) ($out['runner_up'] ?? 0),
            'margin' => (int) ($out['margin'] ?? 0),
            'accept_threshold' => (int) ($out['accept_threshold'] ?? 0),
        ];
    }

    /**
     * Every stored sample, grouped by employee.
     *
     * An enrolment is several touches of several fingers, and the probe is
     * matched against all of them with the BEST score counting - averaging
     * punishes one crooked press.
     *
     * @return list<array{id: int, samples: list<array<string, mixed>>}>
     */
    private function candidates(?int $companyId): array
    {
        $query = Fingerprint::query()
            ->join('em_employees', 'em_employees.id', '=', 'em_fingerprints.em_employee_id')
            ->where('em_employees.is_active', true)
            ->whereNull('em_employees.deleted_at')
            ->whereNotNull('em_fingerprints.template')
            ->when($companyId, fn ($q) => $q->where('em_employees.em_company_id', $companyId))
            ->select(['em_fingerprints.em_employee_id', 'em_fingerprints.template']);

        $grouped = [];
        foreach ($query->cursor() as $row) {
            $template = json_decode((string) $row->template, true);
            if (! is_array($template)) {
                continue;
            }
            $grouped[(int) $row->em_employee_id][] = $template;
        }

        return array_map(
            fn (int $id, array $samples) => ['id' => $id, 'samples' => $samples],
            array_keys($grouped),
            $grouped
        );
    }

    /** @return array<string, mixed> */
    private function run(array $args, int $timeout): array
    {
        $diagnostics = $this->diagnostics();
        if (! $diagnostics['ok']) {
            throw new RuntimeException($diagnostics['detail']);
        }

        $script = dirname(__DIR__, 2).'/resources/python/fpmatch.py';

        $result = $this->python($timeout)->run(
            array_merge([$diagnostics['binary'], $script], $args)
        );

        if (! $result->successful()) {
            throw new RuntimeException(
                'The fingerprint matcher failed: '.trim($result->errorOutput() ?: $result->output())
            );
        }

        // ⚠️ Anything a library prints to stdout lands in front of the JSON and
        // breaks the decode - take the last line, which is ours.
        $lines = array_values(array_filter(explode("\n", trim($result->output()))));
        $decoded = json_decode((string) end($lines), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('The fingerprint matcher returned something unreadable.');
        }

        return $decoded;
    }
}
