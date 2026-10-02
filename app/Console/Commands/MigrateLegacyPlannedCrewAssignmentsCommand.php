<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Support\CrewPlanning\LegacyPlannedMigrationCandidate;
use App\Support\CrewPlanning\LegacyPlannedMigrationReport;
use App\Support\CrewPlanning\MigrateLegacyPlannedAssignments;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('crew-planning:migrate-legacy-planned
                            {--company= : Limit migration to a specific company ID}
                            {--all-companies : Process every company that has legacy Planned assignments}
                            {--assignment= : Limit migration to a specific CrewAssignment ID}
                            {--apply : Apply the migration (default is dry-run with zero writes)}')]
#[Description('Phase 4: migrate legacy CrewAssignment(status=planned) records into CrewPlanningAssignment (dry-run by default)')]
class MigrateLegacyPlannedCrewAssignmentsCommand extends Command
{
    public function handle(MigrateLegacyPlannedAssignments $migrator): int
    {
        $companyOption = $this->option('company');
        $allCompanies = (bool) $this->option('all-companies');
        $assignmentOption = $this->option('assignment');
        $apply = (bool) $this->option('apply');

        if (! $allCompanies && ($companyOption === null || $companyOption === '')) {
            $this->error('Specify --company=ID or --all-companies. Refusing to scan every company implicitly.');

            return self::FAILURE;
        }

        if ($allCompanies && $companyOption !== null && $companyOption !== '') {
            $this->error('Use either --company or --all-companies, not both.');

            return self::FAILURE;
        }

        $companyIds = null;
        if (! $allCompanies) {
            $companyId = (int) $companyOption;
            if ($companyId <= 0 || ! Company::query()->whereKey($companyId)->exists()) {
                $this->error(sprintf('Company #%d was not found.', $companyId));

                return self::FAILURE;
            }
            $companyIds = [$companyId];
        }

        $assignmentId = $assignmentOption !== null && $assignmentOption !== ''
            ? (int) $assignmentOption
            : null;

        $modeLabel = $apply ? 'APPLY' : 'DRY-RUN';
        $this->info(sprintf(
            'Phase 4 legacy Planned → Crew Planning migration [%s] (version %s)',
            $modeLabel,
            MigrateLegacyPlannedAssignments::VERSION,
        ));

        $report = $apply
            ? $migrator->apply($companyIds, $assignmentId)
            : $migrator->inspect($companyIds, $assignmentId);

        $this->renderCandidates($report);
        $this->renderSummary($report);

        if ($report->abortedDueToBlockers) {
            $this->error('Apply aborted: blocked legacy Planned records exist. Resolve blockers, then re-run --apply.');

            return self::FAILURE;
        }

        if ($apply && $report->failed() > 0) {
            $this->error('Apply finished with failures. Review failed rows above.');

            return self::FAILURE;
        }

        if ($apply && $report->remainingPlannedCount > 0) {
            $this->error(sprintf(
                'Migration is NOT complete: Remaining CrewAssignment(status=planned): %d',
                $report->remainingPlannedCount,
            ));
            $this->line('Blocked or out-of-scope Planned rows still exist for the selected companies.');

            return self::FAILURE;
        }

        if ($apply && $report->isComplete()) {
            $this->info('Verification: Remaining CrewAssignment(status=planned): 0');
            $this->info('Phase 4 migration complete for the selected scope. CrewAssignmentStatus::Planned remains until Phase 5.');
        } elseif (! $apply) {
            $this->info('Dry-run complete: zero database writes were performed.');
            $this->line('Review the inventory, then re-run with --apply to migrate.');
        }

        return self::SUCCESS;
    }

    private function renderCandidates(LegacyPlannedMigrationReport $report): void
    {
        if ($report->candidates === []) {
            $this->info('No legacy Planned CrewAssignments found in scope.');

            return;
        }

        $rows = array_map(function (LegacyPlannedMigrationCandidate $candidate): array {
            $blockerText = $candidate->blockers !== []
                ? implode(', ', $candidate->blockers)
                : ($candidate->failureReason ?? '');

            return [
                (string) $candidate->assignmentId,
                $candidate->assignmentNo,
                (string) $candidate->companyId,
                $candidate->employeeName ?? (string) ($candidate->employeeId ?? ''),
                $candidate->vesselName ?? (string) ($candidate->vesselId ?? ''),
                $candidate->positionName ?? (string) ($candidate->positionId ?? ''),
                $candidate->plannedArrivalDate ?? '',
                $candidate->plannedJoinDate ?? '',
                $candidate->plannedLeaveDate ?? '',
                $candidate->relievesCrewAssignmentId !== null ? (string) $candidate->relievesCrewAssignmentId : '',
                $candidate->planningAssignmentId !== null ? (string) $candidate->planningAssignmentId : '',
                $candidate->migrationStatus,
                $candidate->disposition,
                $blockerText,
            ];
        }, $report->candidates);

        $this->table([
            'ID',
            'Assignment No',
            'Company',
            'Employee',
            'Vessel',
            'Position',
            'Arrival',
            'Join',
            'Sign-Off',
            'Relieves',
            'Planning ID',
            'Status',
            'Disposition',
            'Blockers / Failure',
        ], $rows);
    }

    private function renderSummary(LegacyPlannedMigrationReport $report): void
    {
        $summary = $report->summary();

        $this->newLine();
        $this->info('Summary');
        $this->line(sprintf('Scanned: %d', $summary['scanned']));
        $this->line(sprintf('Convertible: %d', $summary['convertible']));
        $this->line(sprintf('Already represented: %d', $summary['already_represented']));
        $this->line(sprintf('Blocked: %d', $summary['blocked']));
        $this->line(sprintf('Migrated: %d', $summary['migrated']));
        $this->line(sprintf('Failed: %d', $summary['failed']));
        $this->line(sprintf('Named CrewPlanningAssignments created: %d', $summary['named_planning_created']));
        $this->line(sprintf('Existing Planning rows reused: %d', $summary['existing_planning_reused']));
        $this->line(sprintf('Legacy Planned records retired: %d', $summary['legacy_planned_retired']));
        $this->line(sprintf('Remaining CrewAssignment(status=planned): %d', $summary['remaining_planned']));
    }
}
