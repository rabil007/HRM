<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Support\MasterData\PrepareRankPositionConsolidation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('master-data:prepare-rank-position-consolidation {--apply : Persist mappings and backfills (default is dry-run)} {--company= : Limit to a company ID}')]
#[Description('Prepare tenant-aware Rank→Position mappings and nullable position_id backfills without cutting over Crew Operations')]
class PrepareRankPositionConsolidationCommand extends Command
{
    public function handle(PrepareRankPositionConsolidation $consolidation): int
    {
        $companyOption = $this->option('company');
        $companyId = null;

        if (is_string($companyOption) && $companyOption !== '') {
            if (! ctype_digit($companyOption)) {
                $this->error('The --company option must be a numeric company ID.');

                return self::FAILURE;
            }

            $companyId = (int) $companyOption;

            if (Company::query()->whereKey($companyId)->doesntExist()) {
                $this->error("Company [{$companyId}] was not found.");

                return self::FAILURE;
            }
        }

        $apply = (bool) $this->option('apply');

        $this->info($apply
            ? 'Applying Rank→Position consolidation (idempotent writes enabled).'
            : 'Dry run: reporting only. No database writes will be performed.');

        try {
            $reports = $consolidation->run($companyId, $apply);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($reports === []) {
            $this->info('No companies found.');

            return self::SUCCESS;
        }

        foreach ($reports as $report) {
            $this->renderCompanyReport($report);
        }

        if (! $apply) {
            $this->newLine();
            $this->line('Dry run complete. Re-run with --apply to persist changes.');
        }

        $hasIntegrityFailures = collect($reports)->contains(
            fn (array $report): bool => ($report['integrity_failures'] ?? []) !== [],
        );

        return $hasIntegrityFailures ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderCompanyReport(array $report): void
    {
        $this->newLine();
        $this->info("Company: {$report['company_name']} (#{$report['company_id']})");
        $this->line("Active ranks considered: {$report['active_ranks_considered']}");
        $this->line("Ranks considered (including referenced inactive/deleted): {$report['ranks_considered']}");
        $this->line("Existing exact Position matches: {$report['existing_exact_matches']}");
        $this->line("New Positions required: {$report['new_positions_required']}");
        $this->line("Mappings created: {$report['mappings_created']}");
        $this->line("Mappings already present: {$report['mappings_already_present']}");
        $this->newLine();
        $this->line("Employees backfilled: {$report['employees_backfilled']}");
        $this->line("Employees already consistent: {$report['employees_already_consistent']}");
        $this->line("Employee conflicts requiring review: {$report['employee_conflicts']}");
        $this->newLine();
        $this->line("Crew Assignments backfilled: {$report['crew_assignments_backfilled']}");
        $this->line("Crew Planning rows backfilled: {$report['crew_planning_backfilled']}");
        $this->line("Sea Service rows backfilled: {$report['sea_services_backfilled']}");
        $this->line("Vessel Manning rows backfilled: {$report['vessel_manning_backfilled']}");
        $this->line("Document Rank requirements copied to Position requirements: {$report['document_rank_requirements_copied']}");
        $this->newLine();
        $this->line("Ambiguous normalized matches: {$report['ambiguous_normalized_matches']}");
        $this->line("TOD conflicts: {$report['tod_conflicts']}");
        $this->line("Near-duplicate candidates (not merged): {$report['near_duplicate_count']}");
        $this->line("Unmapped references: {$report['unmapped_references']}");

        $this->renderDetails('Employee conflicts', $report['employee_conflict_details'] ?? []);
        $this->renderDetails('Ambiguous matches', $report['ambiguous_matches'] ?? []);
        $this->renderDetails('TOD conflicts', $report['tod_conflict_details'] ?? []);
        $this->renderDetails('Near duplicates', $report['near_duplicates'] ?? []);
        $this->renderDetails('Unmapped references', $report['unmapped_reference_details'] ?? []);
        $this->renderDetails('Other conflicts', $report['conflict_details'] ?? []);
        $this->renderDetails('Integrity failures', $report['integrity_failures'] ?? []);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function renderDetails(string $title, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->newLine();
        $this->warn($title.':');

        foreach (array_slice($rows, 0, 50) as $row) {
            $this->line('  - '.json_encode($row, JSON_UNESCAPED_UNICODE));
        }

        if (count($rows) > 50) {
            $this->line('  … '.(count($rows) - 50).' more');
        }
    }
}
