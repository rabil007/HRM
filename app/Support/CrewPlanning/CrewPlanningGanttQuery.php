<?php

namespace App\Support\CrewPlanning;

use App\Models\CrewPlanningAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class CrewPlanningGanttQuery
{
    /**
     * Gantt rows derived from planned assignments in range, grouped by vessel.
     *
     * @return list<array{
     *     vessel_id: int,
     *     vessel_name: string,
     *     ranks: list<array{
     *         row_key: string,
     *         rank_id: int,
     *         rank_name: string,
     *         required_count: int
     *     }>
     * }>
     */
    public static function rows(
        int $companyId,
        string $from,
        string $to,
        ?int $vesselId = null,
        ?int $rankId = null,
    ): array {
        $assignments = self::assignmentsInRange($companyId, $from, $to, $vesselId, $rankId, [
            'vessel:id,name',
            'rank:id,name',
        ]);

        $grouped = [];

        foreach ($assignments->groupBy(fn (CrewPlanningAssignment $assignment) => "vessel:{$assignment->vessel_id}|rank:{$assignment->rank_id}") as $rowKey => $rowAssignments) {
            /** @var CrewPlanningAssignment $first */
            $first = $rowAssignments->first();
            $vessel = $first->vessel;
            $rank = $first->rank;

            if ($vessel === null || $rank === null) {
                continue;
            }

            $vId = $vessel->id;

            if (! isset($grouped[$vId])) {
                $grouped[$vId] = [
                    'vessel_id' => $vId,
                    'vessel_name' => $vessel->name,
                    'ranks' => [],
                ];
            }

            $grouped[$vId]['ranks'][] = [
                'row_key' => $rowKey,
                'rank_id' => $rank->id,
                'rank_name' => $rank->name,
                'required_count' => $rowAssignments->count(),
            ];
        }

        return array_values($grouped);
    }

    /**
     * Gantt bars from planned assignments overlapping the date range.
     *
     * @return list<array<string, mixed>>
     */
    public static function bars(
        int $companyId,
        string $from,
        string $to,
        ?int $vesselId = null,
        ?int $rankId = null,
    ): array {
        return self::assignmentsInRange($companyId, $from, $to, $vesselId, $rankId, [
            'employee:id,name',
            'rank:id,name',
            'vessel:id,name',
            'relievedAssignment.employee:id,name',
        ])
            ->map(function (CrewPlanningAssignment $assignment) use ($to) {
                $joinDate = $assignment->planned_join_date->toDateString();
                $leaveDate = $assignment->planned_leave_date?->toDateString();
                $isOpenEnded = $leaveDate === null;
                $displayEnd = $leaveDate ?? $to;

                return [
                    'id' => $assignment->id,
                    'row_key' => "vessel:{$assignment->vessel_id}|rank:{$assignment->rank_id}",
                    'employee_id' => $assignment->employee_id,
                    'employee_name' => $assignment->employee?->name ?? 'Vacant',
                    'start' => $joinDate,
                    'end' => $displayEnd,
                    'planned_join_date' => $joinDate,
                    'planned_leave_date' => $leaveDate,
                    'is_open_ended' => $isOpenEnded,
                    'total_days' => CrewPlanningAssignmentDuration::inclusiveDays($joinDate, $displayEnd),
                    'rank_name' => $assignment->rank?->name,
                    'vessel_name' => $assignment->vessel?->name,
                    'notes' => $assignment->notes,
                    'crew_assignment_id' => $assignment->crew_assignment_id,
                    'relieves_crew_assignment_id' => $assignment->relieves_crew_assignment_id,
                    'relieves_employee_name' => $assignment->relievedAssignment?->employee?->name,
                    'is_assigned' => $assignment->crew_assignment_id !== null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Tree data: vessels and ranks with planned crew in range.
     *
     * @return list<array{
     *     vessel_id: int,
     *     vessel_name: string,
     *     ranks: list<array{
     *         rank_id: int,
     *         rank_name: string,
     *         required_count: int,
     *         crew: list<array{
     *             employee_id: int|null,
     *             employee_name: string,
     *             is_assigned: bool
     *         }>
     *     }>
     * }>
     */
    public static function tree(
        int $companyId,
        string $from,
        string $to,
        ?int $vesselId = null,
        ?int $rankId = null,
    ): array {
        $assignments = self::assignmentsInRange($companyId, $from, $to, $vesselId, $rankId, [
            'vessel:id,name',
            'rank:id,name',
            'employee:id,name',
            'relievedAssignment.employee:id,name',
        ]);

        $grouped = [];

        foreach ($assignments->groupBy(fn (CrewPlanningAssignment $assignment) => "vessel:{$assignment->vessel_id}|rank:{$assignment->rank_id}") as $rowAssignments) {
            /** @var CrewPlanningAssignment $first */
            $first = $rowAssignments->first();
            $vessel = $first->vessel;
            $rank = $first->rank;

            if ($vessel === null || $rank === null) {
                continue;
            }

            $vId = $vessel->id;

            if (! isset($grouped[$vId])) {
                $grouped[$vId] = [
                    'vessel_id' => $vId,
                    'vessel_name' => $vessel->name,
                    'ranks' => [],
                ];
            }

            $grouped[$vId]['ranks'][] = [
                'rank_id' => $rank->id,
                'rank_name' => $rank->name,
                'required_count' => $rowAssignments->count(),
                'crew' => $rowAssignments
                    ->map(fn (CrewPlanningAssignment $assignment): array => [
                        'employee_id' => $assignment->employee_id,
                        'employee_name' => $assignment->employee?->name ?? 'Vacant',
                        'is_assigned' => $assignment->crew_assignment_id !== null,
                        'relieves_employee_name' => $assignment->relievedAssignment?->employee?->name,
                    ])
                    ->values()
                    ->all(),
            ];
        }

        return array_values($grouped);
    }

    /**
     * @param  list<string>  $with
     * @return Collection<int, CrewPlanningAssignment>
     */
    private static function assignmentsInRange(
        int $companyId,
        string $from,
        string $to,
        ?int $vesselId,
        ?int $rankId,
        array $with,
    ): Collection {
        return CrewPlanningAssignment::query()
            ->where('company_id', $companyId)
            ->whereNotNull('vessel_id')
            ->whereNotNull('rank_id')
            ->where('planned_join_date', '<=', $to)
            ->where(function (Builder $query) use ($from): void {
                $query->where('planned_leave_date', '>=', $from)
                    ->orWhereNull('planned_leave_date');
            })
            ->when($vesselId !== null, fn (Builder $query) => $query->where('vessel_id', $vesselId))
            ->when($rankId !== null, fn (Builder $query) => $query->where('rank_id', $rankId))
            ->with($with)
            ->orderBy('vessel_id')
            ->orderBy('rank_id')
            ->orderBy('planned_join_date')
            ->get();
    }
}
