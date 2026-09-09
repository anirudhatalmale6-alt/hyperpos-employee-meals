<?php

namespace EmployeeMeals\Http\Controllers;

use EmployeeMeals\Models\Employee;
use EmployeeMeals\Models\Fingerprint;
use EmployeeMeals\Support\FingerprintMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * Card and fingerprint enrolment - the client's spec section 3.
 *
 * "Search/select employee, show photo/details, enrol credentials … That's
 * essentially it. We don't need a massive biometric administration system."
 */
class FingerprintController extends \App\Http\Controllers\Controller
{
    /**
     * The browser SDK files, served from the plugin rather than the docroot.
     *
     * ⚠️ The route names carry NO .js extension on purpose. A Laravel front
     * controller commonly excludes anything ending in .js from the rewrite, so
     * a perfectly registered route like /sdk/dp.devices.min.js still 404s -
     * miserable to debug, and the fix is here rather than in the host's
     * .htaccess, which must not be widened.
     */
    private const SDK_FILES = [
        'websdk' => 'dp.websdk.js',
        'core' => 'dp.core.min.js',
        'devices' => 'dp.devices.min.js',
    ];

    public function __construct(private readonly FingerprintMatcher $matcher) {}

    public function index(Request $request): View
    {
        $this->allow('employee-meals.credentials.manage');

        $employee = null;
        if ($request->filled('employee')) {
            $employee = Employee::query()
                ->with(['company', 'department', 'fingerprints'])
                ->find($request->integer('employee'));
        }

        return view('employee-meals::fingerprints.enrol', [
            'employee' => $employee,
            'fingers' => Fingerprint::FINGERS,
            'enrolled' => $employee ? $this->enrolmentState($employee) : [],
            'matcher' => $this->matcher->diagnostics(),
            'q' => (string) $request->query('q', ''),
            'matches' => $request->filled('q')
                ? Employee::query()->with('department')->search($request->query('q'))->limit(10)->get()
                : collect(),
        ]);
    }

    /**
     * A bare page that drives the reader with no framework at all.
     *
     * The same reader works on another of our sites, so this isolates whether
     * the fault is in how the enrolment screen drives the SDK or between the
     * browser and the reader service.
     */
    public function selftest(): View
    {
        $this->allow('employee-meals.credentials.manage');

        return view('employee-meals::fingerprints.selftest');
    }

    /** Serve one SDK file. */
    public function sdk(string $name): BinaryFileResponse
    {
        abort_unless(isset(self::SDK_FILES[$name]), 404);

        $path = dirname(__DIR__, 3).'/resources/sdk/'.self::SDK_FILES[$name];
        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * Store one captured touch.
     *
     * The sample arrives as the SDK produced it. Two traps, both of which fail
     * late and confusingly rather than at the point of the mistake:
     *
     *  ⚠️ The payload is base64URL ('-' and '_', no padding). base64_decode()
     *     does NOT error on those - it quietly returns corrupt bytes, and a
     *     perfectly good capture then fails much later as "could not decode".
     *
     *  ⚠️ A faint capture is refused here rather than stored. A template with
     *     a handful of minutiae matches everybody, and that shows up months
     *     later as "it lets anyone in".
     */
    public function store(Request $request, Employee $employee): JsonResponse
    {
        $this->allow('employee-meals.credentials.manage');

        $data = $request->validate([
            'finger' => ['required', Rule::in(Fingerprint::FINGERS)],
            'sample' => ['required', 'string'],
        ]);

        $png = $this->decodeSample($data['sample']);
        if ($png === null || ! str_starts_with($png, "\x89PNG")) {
            return response()->json([
                'ok' => false,
                'message' => __('employee-meals::meals.fingerprints.errors.not_an_image'),
            ], 422);
        }

        /*
         * ⚠️ A print is STORED whether or not this server can compare prints.
         *
         * The matcher needs Python with OpenCV, which shared hosting often does
         * not have. An earlier version refused the whole enrolment in that case
         * - while the screen said, correctly, that prints could still be
         * enrolled and only identification needed the matcher. The message and
         * the behaviour disagreed, and the message was the one that was right:
         * the reader had already done its job and handed over a perfectly good
         * image, and throwing it away helped nobody.
         *
         * So the image is always kept. The template is extracted when it can
         * be, and backfilled later by `employee-meals:build-templates` once a
         * matcher exists. The screen says plainly which prints are searchable.
         */
        $template = null;
        $pendingReason = null;

        try {
            $template = $this->matcher->extract($png);
        } catch (Throwable $e) {
            $pendingReason = $e->getMessage();
        }

        // Only judge quality when we could actually measure it. A faint print
        // is still refused - a template with a handful of points matches
        // everybody, and that surfaces months later as "it lets anyone in".
        if ($template !== null && ! ($template['usable'] ?? false)) {
            return response()->json([
                'ok' => false,
                'message' => __('employee-meals::meals.fingerprints.errors.too_faint', [
                    'count' => (int) ($template['count'] ?? 0),
                ]),
            ], 422);
        }

        $next = (int) Fingerprint::query()
            ->where('em_employee_id', $employee->id)
            ->where('finger', $data['finger'])
            ->max('sample_no');

        Fingerprint::query()->create([
            'em_employee_id' => $employee->id,
            'finger' => $data['finger'],
            'sample_no' => $next + 1,
            'template' => $template !== null ? json_encode($template) : null,
            'image' => $png,
            'quality' => null,
            'minutiae_count' => $template !== null ? (int) $template['count'] : null,
            'enrolled_at' => Carbon::now(),
        ]);

        return response()->json([
            'ok' => true,
            'finger' => $data['finger'],
            'samples' => $next + 1,
            'minutiae' => $template !== null ? (int) $template['count'] : null,
            'searchable' => $template !== null,
            'pending_reason' => $pendingReason,
            'message' => $template !== null
                ? __('employee-meals::meals.fingerprints.stored_searchable', ['count' => (int) $template['count']])
                : __('employee-meals::meals.fingerprints.stored_pending'),
            'state' => $this->enrolmentState($employee->fresh()),
        ]);
    }

    /**
     * Save the access card scanned on this screen.
     *
     * A card reader types like a keyboard, so the operator just scans into the
     * box and saves. The uniqueness rule is the same one the employee form
     * enforces: a card belongs to exactly one person.
     */
    public function card(Request $request, Employee $employee): JsonResponse
    {
        $this->allow('employee-meals.credentials.manage');

        $data = $request->validate([
            'card_number' => [
                'nullable', 'string', 'max:64',
                Rule::unique('em_employees', 'card_number')
                    ->whereNull('deleted_at')
                    ->ignore($employee->id),
            ],
        ]);

        $employee->card_number = $data['card_number'] !== '' ? $data['card_number'] : null;
        $employee->save();

        return response()->json(['ok' => true, 'card_number' => $employee->card_number]);
    }

    /** Drop every stored sample for one finger, so it can be re-enrolled. */
    public function destroy(Request $request, Employee $employee, string $finger): JsonResponse
    {
        $this->allow('employee-meals.credentials.manage');
        abort_unless(in_array($finger, Fingerprint::FINGERS, true), 404);

        Fingerprint::query()
            ->where('em_employee_id', $employee->id)
            ->where('finger', $finger)
            ->delete();

        return response()->json(['ok' => true, 'state' => $this->enrolmentState($employee->fresh())]);
    }

    /**
     * Put a finger down and see who it finds - the same 1-to-many search the
     * till will do, exposed here so enrolment can be proved on the spot.
     */
    public function test(Request $request): JsonResponse
    {
        $this->allow('employee-meals.credentials.manage');

        $data = $request->validate(['sample' => ['required', 'string']]);
        $png = $this->decodeSample($data['sample']);

        if ($png === null || ! str_starts_with($png, "\x89PNG")) {
            return response()->json([
                'ok' => false,
                'message' => __('employee-meals::meals.fingerprints.errors.not_an_image'),
            ], 422);
        }

        try {
            $probe = $this->matcher->extract($png);
            $result = $this->matcher->identify($probe);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        $employee = $result['employee_id']
            ? Employee::query()->with('department')->find($result['employee_id'])
            : null;

        return response()->json([
            'ok' => true,
            'decision' => $result['decision'],
            'score' => $result['score'],
            // the runner-up is the number that says whether the reader is
            // really telling two people apart
            'runner_up' => $result['runner_up'],
            'threshold' => $result['accept_threshold'],
            'employee' => $employee && $result['decision'] === 'accept' ? [
                'id' => $employee->id,
                'employee_no' => $employee->employee_no,
                'name' => $employee->full_name,
                'department' => $employee->department?->name,
            ] : null,
        ]);
    }

    /**
     * Stored and searchable are different things, and the screen must not
     * conflate them: a print with no template is safely on file but cannot be
     * found by a finger at the till until a matcher exists.
     *
     * @return array<string, array{enrolled: bool, samples: int, searchable: int}>
     */
    private function enrolmentState(Employee $employee): array
    {
        $rows = Fingerprint::query()
            ->where('em_employee_id', $employee->id)
            ->selectRaw('finger, COUNT(*) as n, SUM(template IS NOT NULL) as searchable')
            ->groupBy('finger')
            ->get()
            ->keyBy('finger');

        $state = [];
        foreach (Fingerprint::FINGERS as $finger) {
            $row = $rows->get($finger);
            $n = (int) ($row->n ?? 0);
            $state[$finger] = [
                'enrolled' => $n > 0,
                'samples' => $n,
                'searchable' => (int) ($row->searchable ?? 0),
            ];
        }

        return $state;
    }

    /** base64url in, raw bytes out - or null if it was not decodable at all. */
    private function decodeSample(string $sample): ?string
    {
        if (str_contains($sample, ',')) {           // strip a data: URI prefix
            $sample = substr($sample, strpos($sample, ',') + 1);
        }

        $normalised = strtr(trim($sample), '-_', '+/');
        $padding = strlen($normalised) % 4;
        if ($padding) {
            $normalised .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($normalised, true);

        return $decoded === false ? null : $decoded;
    }

    private function allow(string $permission): void
    {
        $user = auth()->user();

        if ($user === null || (! $user->is_super_admin && ! $user->hasPermission($permission))) {
            abort(403);
        }
    }
}
