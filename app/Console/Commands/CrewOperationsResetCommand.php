<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Support\CrewOperations\Reset\CrewOperationsResetAnalyzer;
use App\Support\CrewOperations\Reset\CrewOperationsResetEnvironmentGuard;
use App\Support\CrewOperations\Reset\CrewOperationsResetExecutor;
use App\Support\CrewOperations\Reset\CrewOperationsResetPlan;
use Illuminate\Console\Command;
use Throwable;

class CrewOperationsResetCommand extends Command
{
    protected $signature = 'crew:reset
                            {--dry-run : Preview deletion counts without modifying data}
                            {--company= : Limit reset to a single company ID}';

    protected $description = 'Reset Crew Operations transactional data in local development (destructive)';

    public function handle(
        CrewOperationsResetEnvironmentGuard $environmentGuard,
        CrewOperationsResetAnalyzer $analyzer,
        CrewOperationsResetExecutor $executor,
    ): int {
        try {
            $environmentGuard->assertMayRun();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $companyOption = $this->option('company');
        $companyId = filled($companyOption) ? (int) $companyOption : null;

        if ($companyId !== null && ! Company::query()->whereKey($companyId)->exists()) {
            $this->error("Company [{$companyId}] was not found.");

            return self::FAILURE;
        }

        $plan = $analyzer->analyze($companyId);
        $this->renderPlan($plan, $dryRun);

        if ($plan->isBlocked()) {
            $this->error('Reset aborted due to blocking references. Resolve the issues above and re-run with --dry-run.');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->info('Dry run complete. No data was modified.');

            return self::SUCCESS;
        }

        $phrase = (string) config('crew_operations_reset.confirmation_phrase');
        $confirmed = $this->ask("Type `{$phrase}` to permanently delete the rows listed above");

        if ($confirmed !== $phrase) {
            $this->warn('Confirmation phrase did not match. Reset cancelled.');

            return self::FAILURE;
        }

        $this->warn('Deleting Crew Operations data…');

        try {
            $executor->execute($plan);
        } catch (Throwable $exception) {
            $this->error('Reset failed: '.$exception->getMessage());
            $this->line('The database transaction was rolled back. No partial cleanup was committed.');

            return self::FAILURE;
        }

        $this->info('Crew Operations reset completed successfully.');
        $this->line('External side effects (emails, webhooks, queued jobs) are not rolled back by the database transaction.');

        return self::SUCCESS;
    }

    private function renderPlan(CrewOperationsResetPlan $plan, bool $dryRun): void
    {
        $connection = config('database.connections.'.config('database.default'));
        $database = (string) ($connection['database'] ?? '');
        $host = (string) ($connection['host'] ?? 'sqlite');

        $this->line('Environment: '.app()->environment());
        $this->line("Database: {$database} @ {$host}");
        $this->line('Mode: '.($dryRun ? 'DRY RUN (read-only)' : 'LIVE DELETE'));
        $this->line('Company scope: '.($plan->companyId !== null ? (string) $plan->companyId : 'all companies'));
        $this->newLine();

        $this->table(
            ['Entity', 'Delete', 'Preserve', 'Notes'],
            array_map(
                fn (array $row): array => [
                    $row['entity'],
                    (string) $row['delete'],
                    $row['preserve'] > 0 ? (string) $row['preserve'] : '—',
                    $row['notes'] !== '' ? $row['notes'] : '—',
                ],
                $plan->rows,
            ),
        );

        if ($plan->sequenceRows !== []) {
            $this->newLine();
            $this->line('Assignment numbering after reset:');
            $this->table(
                ['Company', 'Year', 'Current sequence', 'Next assignment no.'],
                array_map(
                    fn (array $row): array => [
                        $row['company_id'] > 0
                            ? "{$row['company_name']} (#{$row['company_id']})"
                            : $row['company_name'],
                        (string) $row['year'],
                        (string) $row['current_last_number'],
                        $row['next_assignment_no'],
                    ],
                    $plan->sequenceRows,
                ),
            );
        }

        if ($plan->blockingIssues !== []) {
            $this->newLine();
            $this->error('Blocking references:');
            foreach ($plan->blockingIssues as $issue) {
                $this->line(" - {$issue}");
            }
        }

        if ($plan->warnings !== []) {
            $this->newLine();
            $this->warn('Warnings:');
            foreach ($plan->warnings as $warning) {
                $this->line(" - {$warning}");
            }
        }

        $this->newLine();
        $this->line('Total rows slated for deletion: '.$plan->totalDeletes());
    }
}
