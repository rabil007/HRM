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
     * When `$projectionPositions` is provided (Vessel Manning / projection catalog),
     * those vessel/rank positions are merged so configured ranks appear even with
     * zero Planning bars. `required_count` then comes from Vessel Manning.
     *
     * @param  list<array{
     *     row_key: string,
     *     vessel_id: int,
     *     vessel_name: string,
     *     rank_id: int,
     *     rank_name: string,
     *     required_count: int
     * }>|null  $projectionPositions
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
        ?array $projectionPositions = null,
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

            $grouped[$vId]['ranks'][$rowKey] = [
                'row_key' => $rowKey,
                'rank_id' => $rank->id,
                'rank_name' => $rank->name,
                'required_count' => $rowAssignments->count(),
            ];
        }

        if ($projectionPositions === null) {
            $result = [];

            foreach ($grouped as $vesselGroup) {
                $vesselGroup['ranks'] = array_values($vesselGroup['ranks']);
                $result[] = $vesselGroup;
            }

            return $result;
        }

        foreach ($projectionPositions as $position) {
            $vId = (int) $position['vessel_id'];
            $rowKey = (string) $position['row_key'];

            if (! isset($grouped[$vId])) {
                $grouped[$vId] = [
                    'vessel_id' => $vId,
                    'vessel_name' => (string) $position['vessel_name'],
                    'ranks' => [],
                ];
            }

            $grouped[$vId]['ranks'][$rowKey] = [
                'row_key' => $rowKey,
                'rank_id' => (int) $position['rank_id'],
                'rank_name' => (string) $position['rank_name'],
                'required_count' => (int) $position['required_count'],
            ];
        }

        $result = [];

        foreach ($grouped as $vesselGroup) {
            $ranks = array_values($vesselGroup['ranks']);
            usort(
                $ranks,
                fn (array $left, array $right): int => strcasecmp($left['rank_name'], $right['rank_name']),
            );
            $vesselGroup['ranks'] = $ranks;
            $result[] = $vesselGroup;
        }

        usort(
            $result,
            fn (array $left, array $right): int => strcasecmp($left['vessel_name'], $right['vessel_name']),
        );

        return $result;
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
            'relievedAssignment.employee:id,name,employee_no',
            'relievedAssignment.vessel:id,name',
            'relievedAssignment.rank:id,name',
        ])
            ->map(function (CrewPlanningAssignment $assignment) use ($to) {
                $joinDate = $assignment->planned_join_date->toDateString();
                $leaveDate = $assignment->planned_leave_date?->toDateString();
                $isOpenEnded = $leaveDate === null;
                $displayEnd = $leaveDate ?? $to;
                $planningKind = self::planningKind($assignment);

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
                    'relieves_assignment_no' => $assignment->relievedAssignment?->assignment_no,
                    'relieves_vessel_name' => $assignment->relievedAssignment?->vessel?->name,
                    'relieves_rank_name' => $assignment->relievedAssignment?->rank?->name,
                    'relieves_planned_signoff_at' => $assignment->relievedAssignment?->planned_signoff_at?->toDateString(),
                    'is_assigned' => $assignment->crew_assignment_id !== null,
                    'planning_kind' => $planningKind,
                    'planning_kind_label' => self::planningKindLabel($planningKind),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return 'vacant_slot'|'planned'|'planned_relief'|'assignment_created'
     */
    private static function planningKind(CrewPlanningAssignment $assignment): string
    {
        if ($assignment->crew_assignment_id !== null) {
            return 'assignment_created';
        }

        if ($assignment->relieves_crew_assignment_id !== null) {
            return 'planned_relief';
        }

        if ($assignment->employee_id === null) {
            return 'vacant_slot';
        }

        return 'planned';
    }

    private static function planningKindLabel(string $kind): string
    {
        return match ($kind) {
            'vacant_slot' => 'Vacant Slot',
            'planned_relief' => 'Relief Planned',
            'assignment_created' => 'Crew Assigned',
            default => 'Planned Crew',
        };
    }

    /**
     * Tree data: vessels and ranks with planned crew in range.
     *
     * When `$projectionPositions` is provided (same catalog as {@see rows()}),
     * Vessel Manning / projection positions are merged by vessel_id + rank_id so
     * configured ranks appear even with zero Planning crew. Existing Planning
     * crew stays attached; projection-only ranks use `crew: []`. `required_count`
     * comes from Vessel Manning when the position exists in projection.
     *
     * @param  list<array{
     *     row_key: string,
     *     vessel_id: int,
     *     vessel_name: string,
     *     rank_id: int,
     *     rank_name: string,
     *     required_count: int
     * }>|null  $projectionPositions
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
        ?array $projectionPositions = null,
    ): array {
        $assignments = self::assignmentsInRange($companyId, $from, $to, $vesselId, $rankId, [
            'vessel:id,name',
            'rank:id,name',
            'employee:id,name',
            'relievedAssignment.employee:id,name',
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

            $grouped[$vId]['ranks'][$rowKey] = [
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

        if ($projectionPositions === null) {
            $result = [];

            foreach ($grouped as $vesselGroup) {
                $vesselGroup['ranks'] = array_values($vesselGroup['ranks']);
                $result[] = $vesselGroup;
            }

            return $result;
        }

        foreach ($projectionPositions as $position) {
            $vId = (int) $position['vessel_id'];
            $rowKey = (string) $position['row_key'];

            if (! isset($grouped[$vId])) {
                $grouped[$vId] = [
                    'vessel_id' => $vId,
                    'vessel_name' => (string) $position['vessel_name'],
                    'ranks' => [],
                ];
            }

            if (isset($grouped[$vId]['ranks'][$rowKey])) {
                $grouped[$vId]['ranks'][$rowKey]['required_count'] = (int) $position['required_count'];
            } else {
                $grouped[$vId]['ranks'][$rowKey] = [
                    'rank_id' => (int) $position['rank_id'],
                    'rank_name' => (string) $position['rank_name'],
                    'required_count' => (int) $position['required_count'],
                    'crew' => [],
                ];
            }
        }

        $result = [];

        foreach ($grouped as $vesselGroup) {
            $ranks = array_values($vesselGroup['ranks']);
            usort(
                $ranks,
                fn (array $left, array $right): int => strcasecmp($left['rank_name'], $right['rank_name']),
            );
            $vesselGroup['ranks'] = $ranks;
            $result[] = $vesselGroup;
        }

        usort(
            $result,
            fn (array $left, array $right): int => strcasecmp($left['vessel_name'], $right['vessel_name']),
        );

        return $result;
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
