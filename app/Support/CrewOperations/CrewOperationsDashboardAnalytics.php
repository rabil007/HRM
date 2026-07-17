<?php

namespace App\Support\CrewOperations;

use App\Enums\CrewAssignmentStatus;
use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\CrewMovementCorrection;
use App\Models\CrewPlanningAssignment;
use App\Models\Employee;
use App\Models\User;
use App\Support\CrewMovements\Corrections\CrewMovementCorrectionSla;
use App\Support\CrewMovements\CrewAssignmentStatusResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class CrewOperationsDashboardAnalytics
{
    private const ATTENTION_LIMIT = 10;

    private const UPCOMING_PLANNING_LIMIT = 8;

    private const UPCOMING_PLANNING_DAYS = 14;

    public function __construct(
        private readonly CrewMovementCorrectionSla $correctionSla,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forCompany(int $companyId, ?User $user): array
    {
        $permissions = CrewOperationsDashboardPagePermissions::for($user);
        $maxHomeDays = CrewOperationsSettings::maxHomeDays($companyId);
        $today = CarbonImmutable::today();

        $manningGaps = $permissions['vessel_manning']
            ? CrewOperationsManningGapQuery::forCompany($companyId, $today)
            : [
                'understaffed_positions' => 0,
                'total_shortfall' => 0,
                'items' => [],
            ];

        $deploymentSummary = $this->deploymentSummary($companyId);
        $alertCounts = $this->alertCounts(
            $companyId,
            $maxHomeDays,
            $today,
            $permissions['planning'],
        );
        $attentionItems = $this->attentionItems(
            $companyId,
            $maxHomeDays,
            $today,
            $permissions['planning'],
            $manningGaps['items'],
        );

        return [
            'deployment_summary' => $deploymentSummary,
            'alert_counts' => array_merge($alertCounts, [
                'manning_gaps' => $manningGaps['understaffed_positions'],
            ]),
            'attention_items' => $attentionItems,
            'manning_gaps' => $manningGaps,
            'deployment_trends' => CrewOperationsDeploymentTrends::lastSixMonths($companyId),
            'upcoming_planning' => $permissions['planning']
                ? $this->upcomingPlanning($companyId, $today)
                : [],
            'pool_snapshot' => [
                'count' => count(CrewOperationsSettings::poolEmployees($companyId)),
            ],
            ...($permissions['corrections_view']
                ? ['movement_corrections' => $this->movementCorrectionSummary($companyId)]
                : []),
            'recent_activity' => CrewOperationsRecentActivityQuery::forCompany($user, $companyId),
            'max_home_days' => $maxHomeDays,
            'can' => $permissions,
            'can_view_audit' => $user?->can('audit.view') ?? false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deploymentSummary(int $companyId): array
    {
        $employees = Employee::query()
            ->where('company_id', $companyId)
            ->active()
            ->with(['company'])
            ->get();

        $summary = [
            'pre_mobilisation' => 0,
            'travel_in' => 0,
            'join_standby' => 0,
            'training' => 0,
            'ready_to_join' => 0,
            'on_vessel' => 0,
            'demob_standby' => 0,
            'home_redeploy' => 0,
            'in_home' => 0,
            'movement_update_required' => 0,
            'total' => 0,
        ];

        $resolver = new CrewAssignmentStatusResolver;

        foreach ($employees as $employee) {
            $resolved = $resolver->forEmployee($employee);
            $status = $resolved['status'];

            if (isset($summary[$status])) {
                $summary[$status]++;
            }

            $summary['total']++;
        }

        return $summary;
    }

    /**
     * @return array{
     *     needs_update: int,
     *     due_soon: int,
     *     overdue_home: int,
     *     upcoming_planning: int
     * }
     */
    private function alertCounts(
        int $companyId,
        int $maxHomeDays,
        CarbonImmutable $today,
        bool $canViewPlanning,
    ): array {
        $employees = Employee::query()
            ->where('company_id', $companyId)
            ->active()
            ->with(['company'])
            ->get();

        $resolver = new CrewAssignmentStatusResolver;
        $needsUpdate = 0;
        $dueSoon = 0;
        $overdueHome = 0;

        foreach ($employees as $employee) {
            $resolved = $resolver->forEmployee($employee);

            if ($resolved['status'] === 'movement_update_required') {
                $needsUpdate++;
            }

            if ($resolved['assignment_id'] !== null) {
                $assignment = CrewAssignment::query()->find($resolved['assignment_id']);

                if ($assignment !== null && $assignment->status === CrewAssignmentStatus::Active) {
                    if ($assignment->planned_signoff_at !== null) {
                        $daysUntilSignoff = $today->diffInDays($assignment->planned_signoff_at, false);
                        if ($daysUntilSignoff >= 0 && $daysUntilSignoff <= 2) {
                            $dueSoon++;
                        }
                    }
                }
            }

            if ($resolved['status'] === 'in_home' && $resolved['in_home_days'] !== null) {
                if ($resolved['in_home_days'] > $maxHomeDays) {
                    $overdueHome++;
                }
            }
        }

        return [
            'needs_update' => $needsUpdate,
            'due_soon' => $dueSoon,
            'overdue_home' => $overdueHome,
            'upcoming_planning' => $canViewPlanning
                ? $this->upcomingPlanningCount($companyId, $today)
                : 0,
        ];
    }

    /**
     * @return list<array{
     *     type: string,
     *     title: string,
     *     subtitle: string|null,
     *     hint: string,
     *     href: string,
     *     severity: string
     * }>
     */
    private function attentionItems(
        int $companyId,
        int $maxHomeDays,
        CarbonImmutable $today,
        bool $canViewPlanning,
        array $manningGapItems,
    ): array {
        $items = [];
        $seenEmployeeIds = [];

        $employees = Employee::query()
            ->where('company_id', $companyId)
            ->active()
            ->with(['company', 'rank'])
            ->get();

        $resolver = new CrewAssignmentStatusResolver;

        foreach ($employees as $employee) {
            if (count($items) >= self::ATTENTION_LIMIT) {
                break;
            }

            $resolved = $resolver->forEmployee($employee);

            if ($resolved['status'] !== 'movement_update_required') {
                continue;
            }

            $items[] = $this->employeeAttentionItem(
                'needs_update',
                $employee,
                $resolved,
                $resolved['warning'] ?? 'Assignment needs an update',
                'critical',
            );
            $seenEmployeeIds[$employee->id] = true;
        }

        foreach ($employees as $employee) {
            if (count($items) >= self::ATTENTION_LIMIT) {
                break;
            }

            if (isset($seenEmployeeIds[$employee->id])) {
                continue;
            }

            $resolved = $resolver->forEmployee($employee);

            if ($resolved['status'] !== 'in_home' || $resolved['in_home_days'] === null) {
                continue;
            }

            if ($resolved['in_home_days'] <= $maxHomeDays) {
                continue;
            }

            $items[] = $this->employeeAttentionItem(
                'overdue_home',
                $employee,
                $resolved,
                sprintf('In home %d days — exceeds %d day limit', $resolved['in_home_days'], $maxHomeDays),
                'critical',
            );
            $seenEmployeeIds[$employee->id] = true;
        }

        if ($manningGapItems !== []) {
            foreach ($manningGapItems as $gap) {
                if (count($items) >= self::ATTENTION_LIMIT) {
                    break;
                }

                $items[] = [
                    'type' => 'manning_gap',
                    'title' => $gap['vessel_name'],
                    'subtitle' => $gap['rank_name'],
                    'hint' => sprintf(
                        'Short %d — %d of %d on board',
                        $gap['gap'],
                        $gap['actual_count'],
                        $gap['required_count'],
                    ),
                    'href' => route('organization.vessel-manning.show', ['vessel' => $gap['vessel_id']]),
                    'severity' => 'warning',
                ];
            }
        }

        foreach ($employees as $employee) {
            if (count($items) >= self::ATTENTION_LIMIT) {
                break;
            }

            if (isset($seenEmployeeIds[$employee->id])) {
                continue;
            }

            $resolved = $resolver->forEmployee($employee);

            if ($resolved['assignment_id'] === null) {
                continue;
            }

            $assignment = CrewAssignment::query()->find($resolved['assignment_id']);

            if ($assignment === null || $assignment->status !== CrewAssignmentStatus::Active) {
                continue;
            }

            if ($assignment->planned_signoff_at === null) {
                continue;
            }

            $daysUntilSignoff = $today->diffInDays($assignment->planned_signoff_at, false);
            if ($daysUntilSignoff < 0 || $daysUntilSignoff > 2) {
                continue;
            }

            $items[] = $this->employeeAttentionItem(
                'due_soon',
                $employee,
                $resolved,
                'Planned signoff within 2 days',
                'warning',
            );
            $seenEmployeeIds[$employee->id] = true;
        }

        if ($canViewPlanning) {
            foreach ($this->upcomingPlanningAssignments($companyId, $today) as $assignment) {
                if (count($items) >= self::ATTENTION_LIMIT) {
                    break;
                }

                $items[] = [
                    'type' => 'upcoming_join',
                    'title' => $assignment->employee?->name ?? 'Unassigned',
                    'subtitle' => trim(implode(' · ', array_filter([
                        $assignment->vessel?->name,
                        $assignment->rank?->name,
                    ]))) ?: null,
                    'hint' => 'Planned join on '.$assignment->planned_join_date->toDateString(),
                    'href' => route('organization.crew-planning.index', [
                        'from' => $today->toDateString(),
                        'to' => $today->addDays(self::UPCOMING_PLANNING_DAYS)->toDateString(),
                    ]),
                    'severity' => 'info',
                ];
            }
        }

        return $items;
    }

    private function movementCorrectionSummary(int $companyId): array
    {
        $timezone = (string) (Company::query()
            ->whereKey($companyId)
            ->value('timezone') ?? config('app.timezone', 'UTC'));
        $counts = $this->correctionSla->pendingCounts(
            CrewMovementCorrection::query()->where('company_id', $companyId),
            $timezone,
        );

        return [
            'pending' => $counts['pending'],
            'overdue' => $counts['overdue'],
            'url' => route('organization.crew-movement-corrections.index'),
        ];
    }

    /**
     * @param  array<string, mixed>  $resolved
     * @return array{
     *     type: string,
     *     title: string,
     *     subtitle: string|null,
     *     hint: string,
     *     href: string,
     *     severity: string
     * }
     */
    private function employeeAttentionItem(
        string $type,
        Employee $employee,
        array $resolved,
        string $hint,
        string $severity,
    ): array {
        return [
            'type' => $type,
            'title' => $employee->name,
            'subtitle' => trim(implode(' · ', array_filter([
                $employee->rank?->name,
                $resolved['current_vessel'] ?? null,
            ]))) ?: null,
            'hint' => $hint,
            'href' => route('organization.employees.show', ['employee' => $employee->id]),
            'severity' => $severity,
        ];
    }

    /**
     * @return list<array{
     *     id: int,
     *     employee_name: string|null,
     *     vessel_name: string|null,
     *     rank_name: string|null,
     *     planned_join_date: string,
     *     planned_leave_date: string
     * }>
     */
    private function upcomingPlanning(int $companyId, CarbonImmutable $today): array
    {
        return $this->upcomingPlanningAssignments($companyId, $today)
            ->take(self::UPCOMING_PLANNING_LIMIT)
            ->map(fn (CrewPlanningAssignment $assignment): array => [
                'id' => $assignment->id,
                'employee_name' => $assignment->employee?->name,
                'vessel_name' => $assignment->vessel?->name,
                'rank_name' => $assignment->rank?->name,
                'planned_join_date' => $assignment->planned_join_date->toDateString(),
                'planned_leave_date' => $assignment->planned_leave_date->toDateString(),
            ])
            ->values()
            ->all();
    }

    private function upcomingPlanningCount(int $companyId, CarbonImmutable $today): int
    {
        return $this->upcomingPlanningQuery($companyId, $today)->count();
    }

    /**
     * @return Collection<int, CrewPlanningAssignment>
     */
    private function upcomingPlanningAssignments(int $companyId, CarbonImmutable $today): Collection
    {
        return $this->upcomingPlanningQuery($companyId, $today)
            ->limit(self::UPCOMING_PLANNING_LIMIT)
            ->get();
    }

    /**
     * @return Builder<CrewPlanningAssignment>
     */
    private function upcomingPlanningQuery(int $companyId, CarbonImmutable $today): Builder
    {
        $end = $today->addDays(self::UPCOMING_PLANNING_DAYS);

        return CrewPlanningAssignment::query()
            ->where('company_id', $companyId)
            ->whereBetween('planned_join_date', [$today->toDateString(), $end->toDateString()])
            ->with(['employee:id,name', 'vessel:id,name', 'rank:id,name'])
            ->orderBy('planned_join_date')
            ->orderBy('id');
    }
}
