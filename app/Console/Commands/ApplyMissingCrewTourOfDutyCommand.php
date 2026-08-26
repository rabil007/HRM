<?php

namespace App\Console\Commands;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Support\CrewMovements\ApplyMissingCrewTourOfDuty;
use Illuminate\Console\Command;

class ApplyMissingCrewTourOfDutyCommand extends Command
{
    protected $signature = 'crew:apply-missing-tour-of-duty
                            {--company= : Limit repair to a specific company ID}
                            {--assignment= : Limit repair to a specific Assignment ID}
                            {--dry-run : Perform a dry run without modifying records}';

    protected $description = 'Apply missing Tour of Duty snapshot to active P4 crew assignments whose rank has a configured tour';

    public function handle(ApplyMissingCrewTourOfDuty $action): int
    {
        $companyOption = $this->option('company');
        $assignmentOption = $this->option('assignment');
        $dryRun = (bool) $this->option('dry-run');

        $query = CrewAssignment::query()
            ->where('status', CrewAssignmentStatus::Active)
            ->whereNull('tour_of_duty_days')
            ->whereHas('currentPhase', function ($q): void {
                $q->where('phase_code', CrewPhaseCode::OnVessel)
                    ->where('status', CrewPhaseStatus::Active)
                    ->whereNotNull('actual_start_at');
            })
            ->whereNotNull('rank_id')
            ->with(['company', 'employee', 'rank', 'currentPhase', 'phases'])
            ->orderBy('company_id')
            ->orderBy('id');

        if ($companyOption !== null && $companyOption !== '') {
            $query->where('company_id', (int) $companyOption);
        }

        if ($assignmentOption !== null && $assignmentOption !== '') {
            $query->whereKey((int) $assignmentOption);
        }

        $candidates = $query->get();
        $eligibleRows = [];
        $repairedCount = 0;

        foreach ($candidates as $assignment) {
            $inspection = $action->inspect($assignment);

            if ($inspection === null || ! $inspection['is_eligible']) {
                continue;
            }

            $tz = $inspection['timezone'];
            $actualJoinStr = $inspection['actual_join_at']?->copy()->timezone($tz)->toDateString();
            $existingSignoffStr = $inspection['existing_planned_signoff_at']?->copy()->timezone($tz)->toDateString() ?? 'None';
            $calcSignoffStr = $inspection['calculated_planned_signoff_at']?->copy()->timezone($tz)->toDateString();

            $actionDescription = $inspection['will_set_planned_signoff']
                ? sprintf('Apply Tour (%dd) & set Sign-Off to %s', $inspection['tour_of_duty_days'], $calcSignoffStr)
                : sprintf('Apply Tour (%dd) & preserve existing Sign-Off (%s)', $inspection['tour_of_duty_days'], $existingSignoffStr);

            $eligibleRows[] = [
                'assignment' => sprintf('%s (#%d)', $assignment->assignment_no, $assignment->id),
                'employee' => $assignment->employee?->name ?? ('#'.$assignment->employee_id),
                'rank' => $assignment->rank?->name ?? ('#'.$assignment->rank_id),
                'actual_join' => $actualJoinStr,
                'rank_tour' => $inspection['tour_of_duty_days'].' days',
                'existing_signoff' => $existingSignoffStr,
                'calculated_signoff' => $calcSignoffStr,
                'action' => $actionDescription,
                'model' => $assignment,
            ];
        }

        if ($eligibleRows === []) {
            $this->info('No eligible active P4 assignments with missing Tour of Duty found.');

            return self::SUCCESS;
        }

        $tableHeaders = [
            'Assignment',
            'Employee',
            'Rank',
            'Actual Join',
            'Current Rank Tour',
            'Existing Planned Sign-Off',
            'Calculated Planned Sign-Off',
            $dryRun ? 'Action that would be taken' : 'Action taken',
        ];

        $tableData = array_map(function (array $row): array {
            return [
                $row['assignment'],
                $row['employee'],
                $row['rank'],
                $row['actual_join'],
                $row['rank_tour'],
                $row['existing_signoff'],
                $row['calculated_signoff'],
                $row['action'],
            ];
        }, $eligibleRows);

        $this->table($tableHeaders, $tableData);

        if ($dryRun) {
            $this->info(sprintf(
                'Dry-run mode: Found %d eligible assignment(s). No mutations performed.',
                count($eligibleRows),
            ));

            return self::SUCCESS;
        }

        foreach ($eligibleRows as $row) {
            /** @var CrewAssignment $assignment */
            $assignment = $row['model'];
            $result = $action->handle((int) $assignment->company_id, (int) $assignment->id, null);

            if ($result !== null) {
                $repairedCount++;
            }
        }

        $this->info(sprintf(
            'Successfully applied missing Tour of Duty to %d assignment(s).',
            $repairedCount,
        ));

        return self::SUCCESS;
    }
}
