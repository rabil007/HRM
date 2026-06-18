<?php

namespace App\Support\CrewPlanning;

use App\Models\CrewPlanningAssignment;
use App\Models\EmployeeDeployment;
use App\Models\Vessel;
use App\Models\VesselManning;
use Carbon\CarbonImmutable;

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
     * Gantt bars combining EmployeeDeployment records (confirmed) and
     * CrewPlanningAssignment records (draft) overlapping the date range.
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
        $today = CarbonImmutable::today();

        $deployments = EmployeeDeployment::query()
            ->where('company_id', $companyId)
            ->whereNotNull('joined_date')
            ->whereNotNull('vessel_id')
            ->whereNotNull('rank_id')
            ->where('joined_date', '<=', $to)
            ->where(fn ($q) => $q
                ->whereNull('disembarked_date')
                ->orWhere('disembarked_date', '>=', $from)
            )
            ->when($vesselId !== null, fn ($q) => $q->where('vessel_id', $vesselId))
            ->when($rankId !== null, fn ($q) => $q->where('rank_id', $rankId))
            ->with([
                'employee:id,name,nationality_id',
                'employee.nationalityRef:id,name',
                'rank:id,name',
                'vessel:id,name',
            ])
            ->orderBy('vessel_id')
            ->orderBy('rank_id')
            ->orderBy('joined_date')
            ->get();

        $deploymentBars = $deployments
            ->map(function (EmployeeDeployment $dep) use ($today): ?array {
                $joinedDate = $dep->joined_date;
                $disembarkedDate = $dep->disembarked_date;

                if ($joinedDate === null) {
                    return null;
                }

                $status = match (true) {
                    $disembarkedDate !== null && $disembarkedDate->lt($today) => 'past',
                    $joinedDate->gt($today) => 'future',
                    default => 'active',
                };

                return [
                    'id' => $dep->id,
                    'source' => 'deployment',
                    'row_key' => "vessel:{$dep->vessel_id}|rank:{$dep->rank_id}",
                    'employee_id' => $dep->employee_id,
                    'employee_name' => $dep->employee?->name ?? '',
                    'nationality' => $dep->employee?->nationalityRef?->name,
                    'start' => $joinedDate->toDateString(),
                    'end' => $disembarkedDate?->toDateString() ?? $today->toDateString(),
                    'status' => $status,
                    'rank_name' => $dep->rank?->name,
                    'vessel_name' => $dep->vessel?->name,
                    'joined_date' => $joinedDate->toDateString(),
                    'disembarked_date' => $disembarkedDate?->toDateString(),
                    'notes' => null,
                ];
            })
            ->filter()
            ->values()
            ->all();

        $assignments = CrewPlanningAssignment::query()
            ->where('company_id', $companyId)
            ->where('status', 'draft')
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
            ->get();

        $assignmentBars = $assignments
            ->map(fn (CrewPlanningAssignment $asgn) => [
                'id' => $asgn->id,
                'source' => 'assignment',
                'row_key' => "vessel:{$asgn->vessel_id}|rank:{$asgn->rank_id}",
                'employee_id' => $asgn->employee_id,
                'employee_name' => $asgn->employee?->name ?? 'Vacant',
                'nationality' => null,
                'start' => $asgn->planned_join_date->toDateString(),
                'end' => $asgn->planned_leave_date->toDateString(),
                'status' => 'draft',
                'rank_name' => $asgn->rank?->name,
                'vessel_name' => $asgn->vessel?->name,
                'joined_date' => $asgn->planned_join_date->toDateString(),
                'disembarked_date' => $asgn->planned_leave_date->toDateString(),
                'notes' => $asgn->notes,
                'assignment_status' => $asgn->status,
            ])
            ->values()
            ->all();

        return array_merge($deploymentBars, $assignmentBars);
    }

    /**
     * Tree data: vessels with their manning ranks and currently deployed/assigned crew.
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
     *             status: string
     *         }>
     *     }>
     * }>
     */
    public static function tree(int $companyId, string $from, string $to): array
    {
        $today = CarbonImmutable::today();

        $manning = VesselManning::query()
            ->where('company_id', $companyId)
            ->with([
                'vessel:id,name',
                'rank:id,name',
            ])
            ->orderBy('vessel_id')
            ->orderBy('rank_id')
            ->get();

        $deployments = EmployeeDeployment::query()
            ->where('company_id', $companyId)
            ->whereNotNull('joined_date')
            ->whereNotNull('vessel_id')
            ->whereNotNull('rank_id')
            ->where('joined_date', '<=', $to)
            ->where(fn ($q) => $q
                ->whereNull('disembarked_date')
                ->orWhere('disembarked_date', '>=', $from)
            )
            ->with(['employee:id,name'])
            ->get()
            ->groupBy(fn (EmployeeDeployment $dep) => "vessel:{$dep->vessel_id}|rank:{$dep->rank_id}");

        $assignments = CrewPlanningAssignment::query()
            ->where('company_id', $companyId)
            ->where('status', 'draft')
            ->whereNotNull('vessel_id')
            ->whereNotNull('rank_id')
            ->where('planned_join_date', '<=', $to)
            ->where('planned_leave_date', '>=', $from)
            ->with(['employee:id,name'])
            ->get()
            ->groupBy(fn (CrewPlanningAssignment $a) => "vessel:{$a->vessel_id}|rank:{$a->rank_id}");

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

            $deps = $deployments->get($rowKey, collect());
            $depCrew = $deps->map(function (EmployeeDeployment $dep) use ($today): array {
                $disembarkedDate = $dep->disembarked_date;
                $joinedDate = $dep->joined_date;

                $status = match (true) {
                    $disembarkedDate !== null && $disembarkedDate->lt($today) => 'past',
                    $joinedDate !== null && $joinedDate->gt($today) => 'future',
                    default => 'active',
                };

                return [
                    'employee_id' => $dep->employee_id,
                    'employee_name' => $dep->employee?->name ?? '',
                    'status' => $status,
                    'source' => 'deployment',
                ];
            });

            $asgns = $assignments->get($rowKey, collect());
            $asgCrew = $asgns->map(fn (CrewPlanningAssignment $a): array => [
                'employee_id' => $a->employee_id,
                'employee_name' => $a->employee?->name ?? 'Vacant',
                'status' => 'draft',
                'source' => 'assignment',
            ]);

            $grouped[$vId]['ranks'][] = [
                'rank_id' => $rank->id,
                'rank_name' => $rank->name,
                'required_count' => $row->required_count,
                'crew' => $depCrew->merge($asgCrew)->values()->all(),
            ];
        }

        return array_values($grouped);
    }
}
