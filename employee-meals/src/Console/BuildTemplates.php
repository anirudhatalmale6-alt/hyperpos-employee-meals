<?php

namespace EmployeeMeals\Console;

use EmployeeMeals\Models\Fingerprint;
use EmployeeMeals\Support\FingerprintMatcher;
use Illuminate\Console\Command;
use Throwable;

/**
 * Turn stored fingerprint images into searchable templates.
 *
 * Prints are always kept at enrolment, even on a server that cannot compare
 * them - the reader has done its work and the image is the valuable part.
 * This command fills in the templates afterwards, so a canteen can enrol its
 * staff today and have identification start working the moment a matcher is
 * available, with nobody pressing a finger twice.
 *
 * Safe to re-run: it only touches rows whose template is still missing.
 */
class BuildTemplates extends Command
{
    protected $signature = 'employee-meals:build-templates
                            {--limit=0 : Stop after this many, 0 for all}
                            {--force : Rebuild templates that already exist}';

    protected $description = 'Build fingerprint templates from stored images (run after a matcher becomes available)';

    public function handle(FingerprintMatcher $matcher): int
    {
        $diagnostics = $matcher->diagnostics();

        $this->line('Matching engine: '.$diagnostics['detail']);
        if (! $diagnostics['ok']) {
            $this->error('Nothing to do - this server still cannot compare fingerprints.');

            return self::FAILURE;
        }

        $query = Fingerprint::query()->whereNotNull('image');
        if (! $this->option('force')) {
            $query->whereNull('template');
        }

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('Every stored print already has a template.');

            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        $this->info($limit > 0 ? "Building up to {$limit} of {$total}…" : "Building {$total}…");

        $built = 0;
        $faint = 0;
        $failed = 0;

        foreach ($query->cursor() as $print) {
            if ($limit > 0 && $built + $faint + $failed >= $limit) {
                break;
            }

            try {
                $template = $matcher->extract((string) $print->image);
            } catch (Throwable $e) {
                $failed++;
                $this->warn("  print #{$print->id}: {$e->getMessage()}");
                continue;
            }

            // A faint print is recorded as processed but left unsearchable
            // rather than stored as a template that would match everybody.
            if (! ($template['usable'] ?? false)) {
                $faint++;
                $print->forceFill(['minutiae_count' => (int) ($template['count'] ?? 0)])->save();
                continue;
            }

            $print->forceFill([
                'template' => json_encode($template),
                'minutiae_count' => (int) $template['count'],
            ])->save();
            $built++;
        }

        $this->newLine();
        $this->info("Searchable now: {$built}");
        if ($faint > 0) {
            $this->warn("Too faint to use, ask those people to press again: {$faint}");
        }
        if ($failed > 0) {
            $this->error("Could not be read: {$failed}");
        }

        return self::SUCCESS;
    }
}
