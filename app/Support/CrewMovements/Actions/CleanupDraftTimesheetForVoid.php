<?php

namespace App\Support\CrewMovements\Actions;

use App\Enums\CrewTimesheetPreparationStatus;
use App\Enums\PayrollPeriodStatus;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetPreparationLine;
use App\Models\CrewTimesheetSegment;
use App\Support\Payroll\SyncCrewTimesheetParentFromSegments;
use Illuminate\Support\Collection;

/**
 * Removes assignment-linked Draft timesheet data during privileged void.
 *
 * Only touches segments and draft/returned/superseded preparation lines for the
 * selected assignment. Parent timesheet financial adjustments are preserved.
 */
final class CleanupDraftTimesheetForVoid
{
    public function __construct(
        private readonly SyncCrewTimesheetParentFromSegments $syncParentFromSegments,
    ) {}

    /**
     * @param  list<int>  $assignmentIds
     * @return array{
     *     segments_deleted: int,
     *     preparation_lines_deleted: int,
     *     timesheets_recalculated: int,
     *     period_ids: list<int>,
     *     counts_by_assignment: array<int, array{segments_deleted: int, preparation_lines_deleted: int}>
     * }
     */
    public function handle(int $companyId, array $assignmentIds): array
    {
        $assignmentIds = array_values(array_unique(array_map('intval', $assignmentIds)));

        if ($assignmentIds === []) {
            return [
                'segments_deleted' => 0,
                'preparation_lines_deleted' => 0,
                'timesheets_recalculated' => 0,
                'period_ids' => [],
                'counts_by_assignment' => [],
            ];
        }

        $segments = CrewTimesheetSegment::query()
            ->where('crew_timesheet_segments.company_id', $companyId)
            ->whereIn('crew_timesheet_segments.crew_assignment_id', $assignmentIds)
            ->whereHas('timesheet.period', function ($query) use ($companyId): void {
                $query->where('company_id', $companyId)
                    ->where('status', PayrollPeriodStatus::Draft);
            })
            ->orderBy('crew_timesheet_segments.id')
            ->lockForUpdate()
            ->get();

        $countsByAssignment = [];
        $timesheetIds = [];
        $periodIds = [];

        foreach ($segments as $segment) {
            $assignmentId = (int) $segment->crew_assignment_id;
            $countsByAssignment[$assignmentId] ??= [
                'segments_deleted' => 0,
                'preparation_lines_deleted' => 0,
            ];
            $countsByAssignment[$assignmentId]['segments_deleted']++;
            $timesheetIds[(int) $segment->crew_timesheet_id] = true;
            $segment->delete();
        }

        $prepLines = CrewTimesheetPreparationLine::query()
            ->where('crew_timesheet_preparation_lines.company_id', $companyId)
            ->whereIn('crew_timesheet_preparation_lines.crew_assignment_id', $assignmentIds)
            ->whereHas('preparation', function ($query) use ($companyId): void {
                $query->where('company_id', $companyId)
                    ->whereIn('status', [
                        CrewTimesheetPreparationStatus::Draft,
                        CrewTimesheetPreparationStatus::Returned,
                        CrewTimesheetPreparationStatus::Superseded,
                    ]);
            })
            ->orderBy('crew_timesheet_preparation_lines.id')
            ->lockForUpdate()
            ->get();

        foreach ($prepLines as $line) {
            $assignmentId = (int) $line->crew_assignment_id;
            $countsByAssignment[$assignmentId] ??= [
                'segments_deleted' => 0,
                'preparation_lines_deleted' => 0,
            ];
            $countsByAssignment[$assignmentId]['preparation_lines_deleted']++;
            $line->delete();
        }

        $recalculated = 0;

        if ($timesheetIds !== []) {
            /** @var Collection<int, CrewTimesheet> $timesheets */
            $timesheets = CrewTimesheet::query()
                ->where('company_id', $companyId)
                ->whereIn('id', array_keys($timesheetIds))
                ->with(['period', 'segments'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($timesheets as $timesheet) {
                if ($timesheet->period !== null) {
                    $periodIds[(int) $timesheet->period_id] = true;
                }

                $this->syncParentFromSegments->handle($timesheet, $timesheet->period);
                $recalculated++;
            }
        }

        return [
            'segments_deleted' => $segments->count(),
            'preparation_lines_deleted' => $prepLines->count(),
            'timesheets_recalculated' => $recalculated,
            'period_ids' => array_map('intval', array_keys($periodIds)),
            'counts_by_assignment' => $countsByAssignment,
        ];
    }
}
