<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Support\CrewPlanning\RetireSoftDeletedPlannedAssignments;
use App\Support\CrewPlanning\SoftDeletedPlannedRetirementCandidate;
use App\Support\CrewPlanning\SoftDeletedPlannedRetirementReport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('crew-planning:retire-soft-deleted-planned
                            {--company= : Limit retirement to a specific company ID}
                            {--assignment= : Limit retirement to a specific CrewAssignment ID}
                            {--apply : Apply the retirement (default is dry-run with zero writes)}')]
#[Description('Phase 4 companion: retire soft-deleted CrewAssignment(status=planned) tombstones to Cancelled (dry-run by default)')]
class RetireSoftDeletedPlannedCrewAssignmentsCommand extends Command
{
    public function handle(RetireSoftDeletedPlannedAssignments $retirer): int
    {
        $companyOption = $this->option('company');
        $assignmentOption = $this->option('assignment');
        $apply = (bool) $this->option('apply');

        if ($companyOption === null || $companyOption === '') {
            $this->error('Specify --company=ID. Refusing to scan every company implicitly.');

            return self::FAILURE;
        }

        $companyId = (int) $companyOption;
        if ($companyId <= 0 || ! Company::query()->whereKey($companyId)->exists()) {
            $this->error(sprintf('Company #%d was not found.', $companyId));

            return self::FAILURE;
        }

        $assignmentId = $assignmentOption !== null && $assignmentOption !== ''
            ? (int) $assignmentOption
            : null;

        $modeLabel = $apply ? 'APPLY' : 'DRY-RUN';
        $this->info(sprintf(
            'Phase 4 soft-deleted Planned tombstone retirement [%s] (version %s)',
            $modeLabel,
            RetireSoftDeletedPlannedAssignments::VERSION,
        ));

        $report = $apply
            ? $retirer->apply([$companyId], $assignmentId)
            : $retirer->inspect([$companyId], $assignmentId);

        $this->renderCandidates($report);
        $this->renderSummary($report);

        if ($report->abortedDueToBlockers) {
            $this->error('Apply aborted: blocked soft-deleted Planned tombstones exist. Resolve blockers, then re-run --apply.');

            return self::FAILURE;
        }

        if ($apply && $report->failed() > 0) {
            $this->error('Apply finished with failures. Review failed rows above.');

            return self::FAILURE;
        }

        if ($apply && $report->remainingSoftDeletedPlannedCount > 0) {
            $this->error(sprintf(
                'Retirement is NOT complete: Remaining soft-deleted CrewAssignment(status=planned): %d',
                $report->remainingSoftDeletedPlannedCount,
            ));

            return self::FAILURE;
        }

        if ($apply && $report->remainingSoftDeletedPlannedCount === 0 && $report->failed() === 0) {
            $this->info('Verification: Remaining soft-deleted CrewAssignment(status=planned): 0 for the selected scope.');
            $this->info('Soft-deleted Planned vocabulary retired. CrewAssignmentStatus::Planned remains until Phase 5.');
        } elseif (! $apply) {
            $this->info('Dry-run complete: zero database writes were performed.');
            $this->line('Review the inventory, then re-run with --apply to retire safe tombstones.');
            $this->line('This command never creates CrewPlanningAssignment and never restores deleted rows.');
        }

        return self::SUCCESS;
    }

    private function renderCandidates(SoftDeletedPlannedRetirementReport $report): void
    {
        if ($report->candidates === []) {
            $this->info('No soft-deleted Planned CrewAssignments found in scope.');

            return;
        }

        $rows = array_map(function (SoftDeletedPlannedRetirementCandidate $candidate): array {
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
                $candidate->deletedAt ?? '',
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
            'Deleted At',
            'Status',
            'Disposition',
            'Blockers / Failure',
        ], $rows);
    }

    private function renderSummary(SoftDeletedPlannedRetirementReport $report): void
    {
        $summary = $report->summary();

        $this->newLine();
        $this->info('Summary');
        $this->line(sprintf('Scanned: %d', $summary['scanned']));
        $this->line(sprintf('Convertible: %d', $summary['convertible']));
        $this->line(sprintf('Blocked: %d', $summary['blocked']));
        $this->line(sprintf('Retired: %d', $summary['retired']));
        $this->line(sprintf('Failed: %d', $summary['failed']));
        $this->line(sprintf(
            'Remaining soft-deleted CrewAssignment(status=planned): %d',
            $summary['remaining_soft_deleted_planned'],
        ));
    }
}
