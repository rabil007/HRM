<?php

namespace App\Support\CrewPlanning;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\User;
use App\Support\Employees\ActiveEmployeeConstraint;
use App\Support\Employees\EmployeeVisibilityScope;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class CrewPlanningGanttQuery
{
    /**
     * Gantt rows derived from planned and active assignments in range, grouped by vessel.
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
        ?User $user = null,
    ): array {
        $items = self::allPlanningItems($companyId, $from, $to, $vesselId, $rankId, $user);

        $grouped = [];

        foreach ($items->groupBy(fn (array $item) => $item['row_key']) as $rowKey => $rowItems) {
            /** @var array<string, mixed> $first */
            $first = $rowItems->first();
            $vId = (int) $first['vessel_id'];

            if (! isset($grouped[$vId])) {
                $grouped[$vId] = [
                    'vessel_id' => $vId,
                    'vessel_name' => (string) $first['vessel_name'],
                    'ranks' => [],
                ];
            }

            $grouped[$vId]['ranks'][$rowKey] = [
                'row_key' => $rowKey,
                'rank_id' => (int) $first['rank_id'],
                'rank_name' => (string) $first['rank_name'],
                'required_count' => $rowItems->count(),
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
                    'row_key' => $rowKey,
                    'rank_id' => (int) $position['rank_id'],
                    'rank_name' => (string) $position['rank_name'],
                    'required_count' => (int) $position['required_count'],
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
     * Gantt bars from planned and active assignments overlapping the date range.
     *
     * @return list<array<string, mixed>>
     */
    public static function bars(
        int $companyId,
        string $from,
        string $to,
        ?int $vesselId = null,
        ?int $rankId = null,
        ?User $user = null,
    ): array {
        return self::allPlanningItems($companyId, $from, $to, $vesselId, $rankId, $user)
            ->map(function (array $item) use ($to): array {
                $displayEnd = $item['leave_date'] ?? $to;
                $joinDate = $item['join_date'];
                $leaveDate = $item['leave_date'];

                return [
                    'id' => $item['id'],
                    'row_key' => $item['row_key'],
                    'employee_id' => $item['employee_id'],
                    'employee_name' => $item['employee_name'],
                    'start' => $joinDate,
                    'end' => $displayEnd,
                    'planned_join_date' => $joinDate,
                    'planned_leave_date' => $leaveDate,
                    'is_open_ended' => $leaveDate === null,
                    'total_days' => $joinDate ? CrewPlanningAssignmentDuration::inclusiveDays($joinDate, $displayEnd) : 0,
                    'rank_name' => $item['rank_name'],
                    'vessel_name' => $item['vessel_name'],
                    'notes' => $item['notes'],
                    'crew_assignment_id' => $item['crew_assignment_id'],
                    'assignment_no' => $item['assignment_no'],
                    'status' => $item['status'],
                    'relieves_crew_assignment_id' => $item['relieves_crew_assignment_id'],
                    'relieves_employee_name' => $item['relieves_employee_name'],
                    'relieves_assignment_no' => $item['relieves_assignment_no'],
                    'relieves_vessel_name' => $item['relieves_vessel_name'],
                    'relieves_rank_name' => $item['relieves_rank_name'],
                    'relieves_planned_signoff_at' => $item['relieves_planned_signoff_at'],
                    'is_assigned' => $item['is_assigned'],
                    'planning_kind' => $item['planning_kind'],
                    'planning_kind_label' => $item['planning_kind_label'],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Tree data: vessels and ranks with planned crew in range.
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
     *             is_assigned: bool,
     *             relieves_employee_name: string|null
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
        ?User $user = null,
    ): array {
        $items = self::allPlanningItems($companyId, $from, $to, $vesselId, $rankId, $user);

        $grouped = [];

        foreach ($items->groupBy(fn (array $item) => $item['row_key']) as $rowKey => $rowItems) {
            /** @var array<string, mixed> $first */
            $first = $rowItems->first();
            $vId = (int) $first['vessel_id'];

            if (! isset($grouped[$vId])) {
                $grouped[$vId] = [
                    'vessel_id' => $vId,
                    'vessel_name' => (string) $first['vessel_name'],
                    'ranks' => [],
                ];
            }

            $grouped[$vId]['ranks'][$rowKey] = [
                'rank_id' => (int) $first['rank_id'],
                'rank_name' => (string) $first['rank_name'],
                'required_count' => $rowItems->count(),
                'crew' => $rowItems
                    ->map(fn (array $item): array => [
                        'employee_id' => $item['employee_id'],
                        'employee_name' => $item['employee_name'],
                        'is_assigned' => $item['is_assigned'],
                        'relieves_employee_name' => $item['relieves_employee_name'],
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
     * Unified collection of normalized planning items from CrewAssignment (authoritative)
     * and any unlinked CrewPlanningAssignment rows.
     *
     * @return Collection<int, array{
     *     id: int,
     *     row_key: string,
     *     vessel_id: int,
     *     vessel_name: string,
     *     rank_id: int,
     *     rank_name: string,
     *     employee_id: int|null,
     *     employee_name: string,
     *     join_date: string|null,
     *     leave_date: string|null,
     *     notes: string|null,
     *     crew_assignment_id: int|null,
     *     assignment_no: string|null,
     *     status: string,
     *     relieves_crew_assignment_id: int|null,
     *     relieves_employee_name: string|null,
     *     relieves_assignment_no: string|null,
     *     relieves_vessel_name: string|null,
     *     relieves_rank_name: string|null,
     *     relieves_planned_signoff_at: string|null,
     *     is_assigned: bool,
     *     planning_kind: string,
     *     planning_kind_label: string
     * }>
     */
    private static function allPlanningItems(
        int $companyId,
        string $from,
        string $to,
        ?int $vesselId,
        ?int $rankId,
        ?User $user = null,
    ): Collection {
        $timezone = CompanyTimezone::forCompanyId($companyId);
        $fromTimestamp = CarbonImmutable::parse($from, $timezone)->startOfDay();
        $toTimestamp = CarbonImmutable::parse($to, $timezone)->endOfDay();

        // 1. Authoritative: CrewAssignment records (Planned and Active)
        $assignmentQuery = CrewAssignment::query()
            ->where('company_id', $companyId)
            ->whereNotNull('vessel_id')
            ->whereNotNull('rank_id')
            ->whereIn('status', [CrewAssignmentStatus::Planned, CrewAssignmentStatus::Active])
            ->where(function (Builder $q) use ($fromTimestamp, $toTimestamp): void {
                $q->where(function (Builder $planned) use ($fromTimestamp, $toTimestamp): void {
                    $planned->where('status', CrewAssignmentStatus::Planned)
                        ->where('planned_join_at', '<=', $toTimestamp)
                        ->where(function (Builder $dates) use ($fromTimestamp): void {
                            $dates->where('planned_signoff_at', '>=', $fromTimestamp)
                                ->orWhereNull('planned_signoff_at');
                        });
                })
                    ->orWhere(function (Builder $active) use ($fromTimestamp, $toTimestamp): void {
                        $active->where('status', CrewAssignmentStatus::Active)
                            ->where(function (Builder $starts) use ($toTimestamp): void {
                                $starts->where('started_at', '<=', $toTimestamp)
                                    ->orWhere('planned_join_at', '<=', $toTimestamp);
                            })
                            ->where(function (Builder $dates) use ($fromTimestamp): void {
                                $dates->where('planned_signoff_at', '>=', $fromTimestamp)
                                    ->orWhereNull('planned_signoff_at');
                            });
                    });
            })
            ->where(function (Builder $query) use ($companyId): void {
                $query->whereNull('employee_id')
                    ->orWhere(function (Builder $operational) use ($companyId): void {
                        ActiveEmployeeConstraint::whereHas($operational, $companyId);
                    });
            })
            ->when($vesselId !== null, fn (Builder $query) => $query->where('vessel_id', $vesselId))
            ->when($rankId !== null, fn (Builder $query) => $query->where('rank_id', $rankId));

        if ($user !== null) {
            $allowedIds = EmployeeVisibilityScope::allowedDepartmentIds($user, $companyId);

            if ($allowedIds === []) {
                $assignmentQuery->whereNull('employee_id');
            } elseif ($allowedIds !== null) {
                $assignmentQuery->where(function (Builder $visibilityQuery) use ($companyId, $allowedIds): void {
                    $visibilityQuery->whereNull('employee_id')
                        ->orWhereHas('employee', function (Builder $employeeQuery) use ($companyId, $allowedIds): void {
                            $employeeQuery
                                ->where('employees.company_id', $companyId)
                                ->whereIn('employees.department_id', $allowedIds);
                        });
                });
            }
        }

        $assignmentItems = $assignmentQuery
            ->with([
                'employee:id,name,employee_no,status,department_id,user_id',
                'rank:id,name',
                'vessel:id,name',
                'relievedAssignment.employee:id,name,employee_no',
                'relievedAssignment.vessel:id,name',
                'relievedAssignment.rank:id,name',
                'currentPhase',
                'phases',
            ])
            ->orderBy('vessel_id')
            ->orderBy('rank_id')
            ->orderBy('planned_join_at')
            ->get()
            ->map(function (CrewAssignment $assignment) use ($user, $companyId, $timezone): array {
                $onVesselPhase = $assignment->phases
                    ->filter(fn ($p) => $p->phase_code === CrewPhaseCode::OnVessel)
                    ->sortByDesc('sequence')
                    ->first();

                $joinDate = ($onVesselPhase?->actual_start_at
                    ?? $assignment->planned_join_at
                    ?? $assignment->started_at)?->copy()->timezone($timezone)->toDateString();
                $leaveDate = ($onVesselPhase?->actual_end_at
                    ?? $assignment->planned_signoff_at)?->copy()->timezone($timezone)->toDateString();

                $planningKind = self::planningKind($assignment);

                $relievedEmployee = $assignment->relievedAssignment?->employee;
                $canSeeRelievedEmployee = $relievedEmployee === null
                    || $user === null
                    || EmployeeVisibilityScope::canAccess($user, $relievedEmployee, $companyId);

                return [
                    'id' => $assignment->id,
                    'row_key' => "vessel:{$assignment->vessel_id}|rank:{$assignment->rank_id}",
                    'vessel_id' => (int) $assignment->vessel_id,
                    'vessel_name' => $assignment->vessel?->name ?? 'Unassigned Vessel',
                    'rank_id' => (int) $assignment->rank_id,
                    'rank_name' => $assignment->rank?->name ?? 'Unassigned Rank',
                    'employee_id' => $assignment->employee_id,
                    'employee_name' => $assignment->employee?->name ?? 'Vacant',
                    'join_date' => $joinDate,
                    'leave_date' => $leaveDate,
                    'notes' => $assignment->remarks,
                    'crew_assignment_id' => $assignment->id,
                    'assignment_no' => $assignment->assignment_no,
                    'status' => $assignment->status->value,
                    'relieves_crew_assignment_id' => $canSeeRelievedEmployee ? $assignment->relieves_crew_assignment_id : null,
                    'relieves_employee_name' => $canSeeRelievedEmployee ? $assignment->relievedAssignment?->employee?->name : null,
                    'relieves_assignment_no' => $canSeeRelievedEmployee ? $assignment->relievedAssignment?->assignment_no : null,
                    'relieves_vessel_name' => $canSeeRelievedEmployee ? $assignment->relievedAssignment?->vessel?->name : null,
                    'relieves_rank_name' => $canSeeRelievedEmployee ? $assignment->relievedAssignment?->rank?->name : null,
                    'relieves_planned_signoff_at' => $canSeeRelievedEmployee ? $assignment->relievedAssignment?->planned_signoff_at?->copy()->timezone($timezone)->toDateString() : null,
                    'is_assigned' => $assignment->status === CrewAssignmentStatus::Active,
                    'planning_kind' => $planningKind,
                    'planning_kind_label' => self::planningKindLabel($planningKind),
                ];
            });

        // 2. Legacy / unlinked CrewPlanningAssignment records
        $planQuery = CrewPlanningAssignment::query()
            ->where('company_id', $companyId)
            ->whereNull('crew_assignment_id')
            ->whereNotNull('vessel_id')
            ->whereNotNull('rank_id')
            ->where('planned_join_date', '<=', $to)
            ->where(function (Builder $query) use ($from): void {
                $query->where('planned_leave_date', '>=', $from)
                    ->orWhereNull('planned_leave_date');
            })
            ->where(function (Builder $query) use ($companyId): void {
                $today = CarbonImmutable::now(CompanyTimezone::forCompanyId($companyId))->toDateString();

                $query->whereNull('employee_id')
                    ->orWhere(function (Builder $historical) use ($today): void {
                        $historical->whereNotNull('planned_leave_date')
                            ->where('planned_leave_date', '<', $today);
                    })
                    ->orWhere(function (Builder $operational) use ($companyId): void {
                        ActiveEmployeeConstraint::whereHas($operational, $companyId);
                    });
            })
            ->when($vesselId !== null, fn (Builder $query) => $query->where('vessel_id', $vesselId))
            ->when($rankId !== null, fn (Builder $query) => $query->where('rank_id', $rankId));

        if ($user !== null) {
            $allowedIds = EmployeeVisibilityScope::allowedDepartmentIds($user, $companyId);

            if ($allowedIds === []) {
                $planQuery->whereNull('employee_id');
            } elseif ($allowedIds !== null) {
                $planQuery->where(function (Builder $visibilityQuery) use ($companyId, $allowedIds): void {
                    $visibilityQuery->whereNull('employee_id')
                        ->orWhereHas('employee', function (Builder $employeeQuery) use ($companyId, $allowedIds): void {
                            $employeeQuery
                                ->where('employees.company_id', $companyId)
                                ->whereIn('employees.department_id', $allowedIds);
                        });
                });
            }
        }

        $planItems = $planQuery
            ->with([
                'vessel:id,name',
                'rank:id,name',
                'employee:id,name,employee_no,status,department_id,user_id',
                'relievedAssignment.employee:id,name,employee_no',
                'relievedAssignment.vessel:id,name',
                'relievedAssignment.rank:id,name',
            ])
            ->orderBy('vessel_id')
            ->orderBy('rank_id')
            ->orderBy('planned_join_date')
            ->get()
            ->map(function (CrewPlanningAssignment $plan) use ($user, $companyId, $timezone): array {
                $joinDate = $plan->planned_join_date?->toDateString();
                $leaveDate = $plan->planned_leave_date?->toDateString();

                $kind = $plan->employee_id === null
                    ? 'vacant_slot'
                    : ($plan->relieves_crew_assignment_id !== null ? 'planned_relief' : 'planned');

                $relievedEmployee = $plan->relievedAssignment?->employee;
                $canSeeRelievedEmployee = $relievedEmployee === null
                    || $user === null
                    || EmployeeVisibilityScope::canAccess($user, $relievedEmployee, $companyId);

                return [
                    'id' => $plan->id,
                    'row_key' => "vessel:{$plan->vessel_id}|rank:{$plan->rank_id}",
                    'vessel_id' => (int) $plan->vessel_id,
                    'vessel_name' => $plan->vessel?->name ?? 'Unassigned Vessel',
                    'rank_id' => (int) $plan->rank_id,
                    'rank_name' => $plan->rank?->name ?? 'Unassigned Rank',
                    'employee_id' => $plan->employee_id,
                    'employee_name' => $plan->employee?->name ?? 'Vacant',
                    'join_date' => $joinDate,
                    'leave_date' => $leaveDate,
                    'notes' => $plan->notes,
                    'crew_assignment_id' => null,
                    'assignment_no' => null,
                    'status' => 'planned',
                    'relieves_crew_assignment_id' => $canSeeRelievedEmployee ? $plan->relieves_crew_assignment_id : null,
                    'relieves_employee_name' => $canSeeRelievedEmployee ? $plan->relievedAssignment?->employee?->name : null,
                    'relieves_assignment_no' => $canSeeRelievedEmployee ? $plan->relievedAssignment?->assignment_no : null,
                    'relieves_vessel_name' => $canSeeRelievedEmployee ? $plan->relievedAssignment?->vessel?->name : null,
                    'relieves_rank_name' => $canSeeRelievedEmployee ? $plan->relievedAssignment?->rank?->name : null,
                    'relieves_planned_signoff_at' => $canSeeRelievedEmployee ? $plan->relievedAssignment?->planned_signoff_at?->copy()->timezone($timezone)->toDateString() : null,
                    'is_assigned' => false,
                    'planning_kind' => $kind,
                    'planning_kind_label' => self::planningKindLabel($kind),
                ];
            });

        return $assignmentItems->concat($planItems);
    }

    /**
     * @return 'vacant_slot'|'planned'|'planned_relief'|'assignment_created'
     */
    private static function planningKind(CrewAssignment $assignment): string
    {
        if ($assignment->status === CrewAssignmentStatus::Active) {
            return 'assignment_created';
        }

        if ($assignment->relieves_crew_assignment_id !== null) {
            return 'planned_relief';
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
}
