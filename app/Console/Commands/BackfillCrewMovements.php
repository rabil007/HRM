<?php

namespace App\Console\Commands;

use App\Models\EmployeeDeployment;
use App\Support\CrewMovements\LegacyDeploymentBackfillService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class BackfillCrewMovements extends Command
{
    protected $signature = 'crew-movements:backfill
        {--commit : Persist assignments and phases (default is dry run)}
        {--company= : Limit to a company ID}
        {--deployment= : Process a single deployment ID}
        {--limit= : Maximum deployments to process}
        {--report= : Write a JSON report to this path}';

    protected $description = 'Backfill CrewAssignment records from EmployeeDeployment (dry-run by default)';

    public function handle(LegacyDeploymentBackfillService $backfill): int
    {
        $commit = (bool) $this->option('commit');
        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;
        $deploymentId = $this->option('deployment') !== null ? (int) $this->option('deployment') : null;
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $reportPath = $this->option('report');

        if (! $commit) {
            $this->warn('DRY RUN — no database writes will be performed. Pass --commit to persist.');
        } else {
            $this->info('COMMIT MODE — assignments and phases will be written.');
        }

        $query = EmployeeDeployment::query()->orderBy('id');

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        if ($deploymentId !== null) {
            $query->whereKey($deploymentId);
        }

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        $summary = [
            'scanned' => 0,
            'eligible' => 0,
            'created' => 0,
            'already_migrated' => 0,
            'skipped' => 0,
            'conflicts' => 0,
            'failed' => 0,
        ];

        $rows = [];

        $process = function (EmployeeDeployment $deployment) use ($backfill, $commit, &$summary, &$rows): void {
            $summary['scanned']++;

            try {
                $result = $backfill->process($deployment, $commit);
            } catch (\Throwable $e) {
                $summary['failed']++;
                $rows[] = [
                    'deployment_id' => $deployment->id,
                    'company_id' => $deployment->company_id,
                    'employee_id' => $deployment->employee_id,
                    'result' => 'failed',
                    'assignment_id' => null,
                    'assignment_no' => null,
                    'phases' => [],
                    'reason' => $e->getMessage(),
                ];

                $this->error("Deployment #{$deployment->id} failed: {$e->getMessage()}");

                return;
            }

            $rows[] = $result;

            match ($result['result']) {
                'eligible' => $summary['eligible']++,
                'created' => $summary['created']++,
                'already_migrated' => $summary['already_migrated']++,
                'conflict' => $summary['conflicts']++,
                'skipped' => $summary['skipped']++,
                default => $summary['failed']++,
            };
        };

        if ($limit !== null && $limit > 0) {
            $query->get()->each($process);
        } else {
            $query->chunkById(100, function ($deployments) use ($process): void {
                foreach ($deployments as $deployment) {
                    $process($deployment);
                }
            });
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            collect($summary)->map(fn ($count, $key) => [$key, $count])->values()->all(),
        );

        if (is_string($reportPath) && $reportPath !== '') {
            File::ensureDirectoryExists(dirname($reportPath));
            File::put($reportPath, json_encode([
                'mode' => $commit ? 'commit' : 'dry_run',
                'summary' => $summary,
                'rows' => $rows,
            ], JSON_PRETTY_PRINT));
            $this->info("Report written to {$reportPath}");
        }

        return self::SUCCESS;
    }
}
