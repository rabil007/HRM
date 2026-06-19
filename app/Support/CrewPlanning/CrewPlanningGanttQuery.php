<?php

namespace App\Support\CrewPlanning;

use App\Models\CrewPlanningAssignment;
use App\Models\VesselManning;

final class CrewPlanningGanttQuery
{
    /**
     * Gantt rows derived from VesselManning, grouped by vessel.
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
    public static function rows(int $companyId, ?int $vesselId = null, ?int $rankId = null): array
    {
        $query = VesselManning::query()
            ->where('company_id', $companyId)
            ->with([
                'vessel:id,name,is_active',
                'rank:id,name',
            ])
            ->when($vesselId !== null, fn ($q) => $q->where('vessel_id', $vesselId))
            ->when($rankId !== null, fn ($q) => $q->where('rank_id', $rankId))
            ->orderBy('vessel_id')
            ->orderBy('rank_id');

        $grouped = [];

        foreach ($query->get() as $manning) {
            $vessel = $manning->vessel;
            $rank = $manning->rank;

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
                'row_key' => "vessel:{$vId}|rank:{$rank->id}",
                'rank_id' => $rank->id,
                'rank_name' => $rank->name,
                'required_count' => $manning->required_count,
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
        return CrewPlanningAssignment::query()
            ->where('company_id', $companyId)
            ->whereNotNull('vessel_id')
            ->whereNotNull('rank_id')
            ->where('planned_join_date', '<=', $to)
            ->where('planned_leave_date', '>=', $from)
            ->when($vesselId !== null, fn ($q) => $q->where('vessel_id', $vesselId))
            ->when($rankId !== null, fn ($q) => $q->where('rank_id', $rankId))
            ->with([
                'employee:id,name',
                'rank:id,name',
                'vessel:id,name',
            ])
            ->orderBy('vessel_id')
            ->orderBy('rank_id')
            ->orderBy('planned_join_date')
            ->get()
            ->map(fn (CrewPlanningAssignment $assignment) => [
                'id' => $assignment->id,
                'row_key' => "vessel:{$assignment->vessel_id}|rank:{$assignment->rank_id}",
                'employee_id' => $assignment->employee_id,
                'employee_name' => $assignment->employee?->name ?? 'Vacant',
                'start' => $assignment->planned_join_date->toDateString(),
                'end' => $assignment->planned_leave_date->toDateString(),
                'planned_join_date' => $assignment->planned_join_date->toDateString(),
                'planned_leave_date' => $assignment->planned_leave_date->toDateString(),
                'rank_name' => $assignment->rank?->name,
                'vessel_name' => $assignment->vessel?->name,
                'notes' => $assignment->notes,
            ])
            ->values()
            ->all();
    }

    /**
     * Tree data: vessels with manning ranks and planned crew in range.
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
     *             employee_name: string
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
        $manning = VesselManning::query()
            ->where('company_id', $companyId)
            ->when($vesselId !== null, fn ($q) => $q->where('vessel_id', $vesselId))
            ->when($rankId !== null, fn ($q) => $q->where('rank_id', $rankId))
            ->with([
                'vessel:id,name',
                'rank:id,name',
            ])
            ->orderBy('vessel_id')
            ->orderBy('rank_id')
            ->get();

        $assignments = CrewPlanningAssignment::query()
            ->where('company_id', $companyId)
            ->whereNotNull('vessel_id')
            ->whereNotNull('rank_id')
            ->where('planned_join_date', '<=', $to)
            ->where('planned_leave_date', '>=', $from)
            ->when($vesselId !== null, fn ($q) => $q->where('vessel_id', $vesselId))
            ->when($rankId !== null, fn ($q) => $q->where('rank_id', $rankId))
            ->with(['employee:id,name'])
            ->get()
            ->groupBy(fn (CrewPlanningAssignment $assignment) => "vessel:{$assignment->vessel_id}|rank:{$assignment->rank_id}");

        $grouped = [];

        foreach ($manning as $row) {
            $vessel = $row->vessel;
            $rank = $row->rank;

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

            $rowKey = "vessel:{$vId}|rank:{$rank->id}";

            $plannedCrew = ($assignments->get($rowKey, collect()))
                ->map(fn (CrewPlanningAssignment $assignment): array => [
                    'employee_id' => $assignment->employee_id,
                    'employee_name' => $assignment->employee?->name ?? 'Vacant',
                ])
                ->values()
                ->all();

            $grouped[$vId]['ranks'][] = [
                'rank_id' => $rank->id,
                'rank_name' => $rank->name,
                'required_count' => $row->required_count,
                'crew' => $plannedCrew,
            ];
        }

        return array_values($grouped);
    }
}
