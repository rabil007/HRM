<?php

namespace App\Support\CrewOperations;

use App\Models\CrewPlanningAssignment;
use App\Models\EmployeeDeployment;
use App\Models\User;
use App\Support\CrewDeployments\CrewDeploymentLatestRecords;
use App\Support\CrewDeployments\CrewDeploymentSummary;
use App\Support\CrewDeployments\DeploymentStatus;
use App\Support\CrewDeployments\EmployeeDeploymentPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class CrewOperationsDashboardAnalytics
{
    private const ATTENTION_LIMIT = 10;

    private const UPCOMING_PLANNING_LIMIT = 8;

    private const UPCOMING_PLANNING_DAYS = 14;

    /**
     * @return array<string, mixed>
     */
    public function forCompany(int $companyId, ?User $user): array
    {
        $permissions = CrewOperationsDashboardPagePermissions::for($user);
        $maxHomeDays = CrewOperationsSettings::maxHomeDays($companyId);
        $today = CarbonImmutable::today();

        $deployments = $this->companyDeployments($companyId);
        $inHomeIds = CrewDeploymentLatestRecords::inHomeDeploymentIds($deployments, $today);

        $manningGaps = $permissions['vessel_manning']
            ? CrewOperationsManningGapQuery::forCompany($companyId, $today)
            : [
                'understaffed_positions' => 0,
                'total_shortfall' => 0,
                'items' => [],
            ];

        $alertCounts = $this->alertCounts($deployments, $inHomeIds, $maxHomeDays, $companyId, $today, $permissions['planning']);
        $attentionItems = $this->attentionItems(
            $deployments,
            $inHomeIds,
            $maxHomeDays,
            $companyId,
            $today,
            $permissions['planning'],
            $manningGaps['items'],
        );

        return [
            'deployment_summary' => CrewDeploymentSummary::forCompany($companyId),
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
            'recent_activity' => CrewOperationsRecentActivityQuery::forCompany($user, $companyId),
            'max_home_days' => $maxHomeDays,
            'can' => $permissions,
            'can_view_audit' => $user?->can('audit.view') ?? false,
        ];
    }

    /**
     * @return Collection<int, EmployeeDeployment>
     */
    private function companyDeployments(int $companyId): Collection
    {
        return EmployeeDeployment::query()
            ->where('company_id', $companyId)
            ->with([
                'employee.nationalityRef',
                'rank',
                'client',
                'companyVisaType',
                'vessel',
            ])
            ->get();
    }

    /**
     * @param  Collection<int, EmployeeDeployment>  $deployments
     * @param  Collection<int, int>  $inHomeIds
     * @return array{
     *     needs_update: int,
     *     due_soon: int,
     *     overdue_home: int,
     *     upcoming_planning: int
     * }
     */
    private function alertCounts(
        Collection $deployments,
        Collection $inHomeIds,
        int $maxHomeDays,
        int $companyId,
        CarbonImmutable $today,
        bool $canViewPlanning,
    ): array {
        $needsUpdate = 0;
        $dueSoon = 0;
        $overdueHome = 0;

        foreach ($deployments as $deployment) {
            $status = DeploymentStatus::resolve($deployment, $today)['status'];

            if ($status === DeploymentStatus::UNKNOWN) {
                $needsUpdate++;

                continue;
            }

            if (DeploymentStatus::dueSoonDateFields($deployment, $today) !== []) {
                $dueSoon++;
            }

            if (
                $inHomeIds->contains($deployment->id)
                && ($days = DeploymentStatus::inHomeDays($deployment, $today)) !== null
                && $days > $maxHomeDays
            ) {
                $overdueHome++;
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
     * @param  Collection<int, EmployeeDeployment>  $deployments
     * @param  Collection<int, int>  $inHomeIds
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
        Collection $deployments,
        Collection $inHomeIds,
        int $maxHomeDays,
        int $companyId,
        CarbonImmutable $today,
        bool $canViewPlanning,
        array $manningGapItems,
    ): array {
        $items = [];
        $seenDeploymentIds = [];

        foreach ($deployments as $deployment) {
            if (count($items) >= self::ATTENTION_LIMIT) {
                break;
            }

            if (DeploymentStatus::resolve($deployment, $today)['status'] !== DeploymentStatus::UNKNOWN) {
                continue;
            }

            $presented = EmployeeDeploymentPresenter::toArray($deployment);
            $items[] = $this->deploymentAttentionItem(
                'needs_update',
                $presented,
                $presented['status_hint'] ?? 'Deployment record needs an update',
                'critical',
            );
            $seenDeploymentIds[$deployment->id] = true;
        }

        foreach ($deployments as $deployment) {
            if (count($items) >= self::ATTENTION_LIMIT) {
                break;
            }

            if (isset($seenDeploymentIds[$deployment->id])) {
                continue;
            }

            if (
                ! $inHomeIds->contains($deployment->id)
                || ($days = DeploymentStatus::inHomeDays($deployment, $today)) === null
                || $days <= $maxHomeDays
            ) {
                continue;
            }

            $presented = EmployeeDeploymentPresenter::toArray($deployment);
            $items[] = $this->deploymentAttentionItem(
                'overdue_home',
                $presented,
                sprintf('In home %d days — exceeds %d day limit', $days, $maxHomeDays),
                'critical',
            );
            $seenDeploymentIds[$deployment->id] = true;
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

        foreach ($deployments as $deployment) {
            if (count($items) >= self::ATTENTION_LIMIT) {
                break;
            }

            if (isset($seenDeploymentIds[$deployment->id])) {
                continue;
            }

            if (DeploymentStatus::dueSoonDateFields($deployment, $today) === []) {
                continue;
            }

            $presented = EmployeeDeploymentPresenter::toArray($deployment);
            $items[] = $this->deploymentAttentionItem(
                'due_soon',
                $presented,
                'Key date due within '.$this->dueSoonWindowDays().' days',
                'warning',
            );
            $seenDeploymentIds[$deployment->id] = true;
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

    /**
     * @param  array<string, mixed>  $presented
     * @return array{
     *     type: string,
     *     title: string,
     *     subtitle: string|null,
     *     hint: string,
     *     href: string,
     *     severity: string
     * }
     */
    private function deploymentAttentionItem(
        string $type,
        array $presented,
        string $hint,
        string $severity,
    ): array {
        return [
            'type' => $type,
            'title' => (string) ($presented['employee_name'] ?? 'Unknown employee'),
            'subtitle' => trim(implode(' · ', array_filter([
                $presented['rank_name'] ?? null,
                $presented['vessel_name'] ?? $presented['current_vessel'] ?? null,
            ]))) ?: null,
            'hint' => $hint,
            'href' => route('organization.crew-deployments.show', ['deployment' => $presented['id']]),
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

    private function dueSoonWindowDays(): int
    {
        return 2;
    }
}
