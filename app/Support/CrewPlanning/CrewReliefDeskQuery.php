<?php

namespace App\Support\CrewPlanning;

use App\Enums\CrewReliefRisk;
use App\Enums\CrewReliefStatus;
use App\Models\Client;
use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\User;
use App\Support\CrewMovements\CrewAssignmentSnapshotFilterOptions;
use App\Support\CrewMovements\CrewMobilisationReadinessResolver;
use App\Support\CrewMovements\CrewMobilisationReadinessResult;
use App\Support\CrewMovements\CrewReliefPlanningLoader;
use App\Support\CrewMovements\CrewReliefReadinessResolver;
use App\Support\CrewMovements\CrewReliefReadinessResult;
use App\Support\CrewMovements\CurrentCrewQuery;
use App\Support\CrewMovements\CurrentOnboardCrewQuery;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;

final class CrewReliefDeskQuery
{
    public const DEFAULT_HORIZON_DAYS = 30;

    public function __construct(
        private readonly CrewReliefReadinessResolver $resolver = new CrewReliefReadinessResolver,
        private readonly CrewReliefPlanningLoader $loader = new CrewReliefPlanningLoader,
        private readonly CrewReliefDeskPresenter $presenter = new CrewReliefDeskPresenter,
        private readonly CrewMobilisationReadinessResolver $readinessResolver = new CrewMobilisationReadinessResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $queryString
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     pagination: LengthAwarePaginator<int, CrewAssignment>,
     *     summary: array<string, int>,
     *     filters: array<string, mixed>,
     *     filter_options: array<string, mixed>
     * }
     */
    public function page(
        int $companyId,
        array $filters,
        User $user,
        int $page = 1,
        string $path = '/organization/crew-planning',
        array $queryString = [],
    ): array {
        $timezone = CompanyTimezone::forCompanyId($companyId);
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $resolved = $this->resolveCandidates($companyId, $filters, $today, $timezone);
        $summary = $this->summarize($resolved);
        $filtered = $this->applyFocusAndReliefFilters($resolved, $filters);
        $sorted = $this->sortRows($filtered);
        $perPage = CurrentCrewQuery::resolvePerPage($filters['per_page'] ?? null);
        $page = max(1, $page);
        $total = $sorted->count();
        $slice = $sorted->slice(($page - 1) * $perPage, $perPage)->values();
        (new EloquentCollection($slice->pluck('assignment')->all()))->loadMissing(['phases']);
        $readinessByLinkedId = $this->readinessForPage($slice, $companyId, $user);

        $rows = $slice->map(function (array $item) use ($user, $companyId, $readinessByLinkedId): array {
            $linkedId = $item['relief']->reliefCrewAssignmentId;

            return $this->presenter->row(
                $item['assignment'],
                $item['relief'],
                $linkedId !== null ? $readinessByLinkedId->get($linkedId) : null,
                $user,
                $companyId,
            );
        })->all();

        $paginator = new Paginator(
            $slice->pluck('assignment'),
            $total,
            $perPage,
            $page,
            [
                'path' => $path,
                'query' => $queryString,
            ],
        );

        return [
            'rows' => $rows,
            'pagination' => $paginator,
            'summary' => $summary,
            'filters' => CrewReliefDeskFilters::inertiaFilters($filters),
            'filter_options' => $this->filterOptions($companyId),
        ];
    }

    /**
     * @return array{
     *     clients: list<array{id: int, name: string}>,
     *     vessels: list<array{id: int, name: string, client_id: int|null, client_ids: list<int>}>,
     *     relief_statuses: list<array{value: string, label: string}>,
     *     relief_risks: list<array{value: string, label: string}>
     * }
     */
    public function filterOptions(int $companyId): array
    {
        return [
            'clients' => Client::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Client $client): array => [
                    'id' => (int) $client->id,
                    'name' => (string) $client->name,
                ])
                ->values()
                ->all(),
            'vessels' => CrewAssignmentSnapshotFilterOptions::vessels($companyId),
            'relief_statuses' => collect(CrewReliefStatus::filterable())
                ->map(fn (CrewReliefStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ])
                ->values()
                ->all(),
            'relief_risks' => collect(CrewReliefRisk::filterable())
                ->map(fn (CrewReliefRisk $risk): array => [
                    'value' => $risk->value,
                    'label' => $risk->label(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array{assignment: CrewAssignment, relief: CrewReliefReadinessResult}>
     */
    private function resolveCandidates(
        int $companyId,
        array $filters,
        CarbonImmutable $today,
        string $timezone,
    ): Collection {
        $query = CrewAssignment::query();
        CurrentOnboardCrewQuery::applyConstraint($query, $companyId);
        $this->applySqlFilters($query, $companyId, $filters, $today, $timezone);

        $assignments = $query
            ->with([
                'employee:id,company_id,name,employee_no',
                'rank:id,name',
                'vessel:id,company_id,name',
                'client:id,name',
                'currentPhase',
                'company',
            ])
            ->get();

        $plans = $this->loader->forSourceAssignmentIds(
            $companyId,
            $assignments->pluck('id')->all(),
        );

        return $assignments->map(function (CrewAssignment $assignment) use ($plans, $today, $timezone, $companyId): array {
            $plan = $this->tenantSafePlan($plans->get((int) $assignment->id), $companyId);

            return [
                'assignment' => $assignment,
                'relief' => $this->resolver->forPreloadedPlan(
                    $assignment,
                    $plan,
                    $today,
                    $timezone,
                ),
            ];
        })->values();
    }

    /**
     * @param  Builder<CrewAssignment>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applySqlFilters(
        Builder $query,
        int $companyId,
        array $filters,
        CarbonImmutable $today,
        string $timezone,
    ): void {
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function (Builder $q) use ($search, $companyId): void {
                $q->where('assignment_no', 'like', '%'.$search.'%')
                    ->orWhereHas('employee', fn (Builder $e) => $e
                        ->where('company_id', $companyId)
                        ->where(function (Builder $employeeSearch) use ($search): void {
                            $employeeSearch->where('name', 'like', '%'.$search.'%')
                                ->orWhere('employee_no', 'like', '%'.$search.'%');
                        }))
                    ->orWhereHas('vessel', fn (Builder $v) => $v->where('name', 'like', '%'.$search.'%'))
                    ->orWhereHas('rank', fn (Builder $r) => $r->where('name', 'like', '%'.$search.'%'))
                    ->orWhereHas('client', fn (Builder $c) => $c->where('name', 'like', '%'.$search.'%'))
                    ->orWhereHas('reliefPlanningAssignments', function (Builder $planning) use ($search, $companyId): void {
                        $planning->where('company_id', $companyId)
                            ->whereHas('employee', fn (Builder $e) => $e
                                ->where('company_id', $companyId)
                                ->where('name', 'like', '%'.$search.'%'));
                    });
            });
        }

        if (! empty($filters['vessel_id'])) {
            $query->where('vessel_id', (int) $filters['vessel_id']);
        }

        if (! empty($filters['rank_id'])) {
            $query->where('rank_id', (int) $filters['rank_id']);
        }

        if (! empty($filters['client_id'])) {
            $query->where('client_id', (int) $filters['client_id']);
        }

        $from = $this->nullableDate((string) ($filters['planned_signoff_from'] ?? ''), $timezone);
        $to = $this->nullableDate((string) ($filters['planned_signoff_to'] ?? ''), $timezone);
        $hasExplicitRange = $from !== null || $to !== null;

        if ($from !== null) {
            $query->where('planned_signoff_at', '>=', $from->startOfDay());
        }

        if ($to !== null) {
            $query->where('planned_signoff_at', '<=', $to->endOfDay());
        }

        $horizon = (string) ($filters['horizon'] ?? CrewReliefDeskFilters::HORIZON_DEFAULT);

        if (! $hasExplicitRange && $horizon !== CrewReliefDeskFilters::HORIZON_ALL) {
            $horizonEnd = $today->addDays(self::DEFAULT_HORIZON_DAYS)->endOfDay();
            $query->where(function (Builder $q) use ($horizonEnd): void {
                $q->whereNull('planned_signoff_at')
                    ->orWhere('planned_signoff_at', '<=', $horizonEnd);
            });
        }
    }

    /**
     * @param  Collection<int, array{assignment: CrewAssignment, relief: CrewReliefReadinessResult}>  $rows
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array{assignment: CrewAssignment, relief: CrewReliefReadinessResult}>
     */
    private function applyFocusAndReliefFilters(Collection $rows, array $filters): Collection
    {
        $status = CrewReliefStatus::tryFrom((string) ($filters['relief_status'] ?? ''));
        $risk = CrewReliefRisk::tryFrom((string) ($filters['relief_risk'] ?? ''));
        $focus = (string) ($filters['focus'] ?? '');

        return $rows->filter(function (array $item) use ($status, $risk, $focus): bool {
            $relief = $item['relief'];

            if ($status !== null && $relief->status !== $status) {
                return false;
            }

            if ($risk !== null && $relief->risk !== $risk) {
                return false;
            }

            return $this->matchesFocus($relief, $focus);
        })->values();
    }

    private function matchesFocus(CrewReliefReadinessResult $relief, string $focus): bool
    {
        if ($focus === '') {
            return true;
        }

        $days = $relief->daysUntilSignoff;

        return match ($focus) {
            'needs_relief' => $relief->status === CrewReliefStatus::NoRelief,
            'critical' => $relief->risk === CrewReliefRisk::Critical,
            'not_ready' => in_array($relief->status, [
                CrewReliefStatus::ReliefPlanned,
                CrewReliefStatus::AssignmentCreated,
                CrewReliefStatus::Mobilising,
            ], true),
            'signoff_7' => $days !== null && $days >= 0 && $days <= 7,
            'signoff_14' => $days !== null && $days >= 0 && $days <= 14,
            'ready' => $relief->status->isReadyOrOnboard(),
            'overdue' => $days !== null && $days < 0,
            default => true,
        };
    }

    /**
     * @param  Collection<int, array{assignment: CrewAssignment, relief: CrewReliefReadinessResult}>  $rows
     * @return array<string, int>
     */
    private function summarize(Collection $rows): array
    {
        $summary = [
            'needs_relief' => 0,
            'critical' => 0,
            'not_ready' => 0,
            'signoff_14' => 0,
            'ready' => 0,
            'overdue' => 0,
        ];

        foreach ($rows as $item) {
            $relief = $item['relief'];
            $days = $relief->daysUntilSignoff;

            if ($relief->status === CrewReliefStatus::NoRelief) {
                $summary['needs_relief']++;
            }

            if ($relief->risk === CrewReliefRisk::Critical) {
                $summary['critical']++;
            }

            if (in_array($relief->status, [
                CrewReliefStatus::ReliefPlanned,
                CrewReliefStatus::AssignmentCreated,
                CrewReliefStatus::Mobilising,
            ], true)) {
                $summary['not_ready']++;
            }

            if ($days !== null && $days >= 0 && $days <= 14) {
                $summary['signoff_14']++;
            }

            if ($relief->status->isReadyOrOnboard()) {
                $summary['ready']++;
            }

            if ($days !== null && $days < 0) {
                $summary['overdue']++;
            }
        }

        return $summary;
    }

    /**
     * @param  Collection<int, array{assignment: CrewAssignment, relief: CrewReliefReadinessResult}>  $rows
     * @return Collection<int, array{assignment: CrewAssignment, relief: CrewReliefReadinessResult}>
     */
    private function sortRows(Collection $rows): Collection
    {
        return $rows
            ->sort(function (array $left, array $right): int {
                $leftRank = $this->urgencyRank($left['relief']);
                $rightRank = $this->urgencyRank($right['relief']);

                if ($leftRank !== $rightRank) {
                    return $leftRank <=> $rightRank;
                }

                $leftDays = $left['relief']->daysUntilSignoff;
                $rightDays = $right['relief']->daysUntilSignoff;

                if ($leftDays === null && $rightDays === null) {
                    return $left['assignment']->id <=> $right['assignment']->id;
                }

                if ($leftDays === null) {
                    return 1;
                }

                if ($rightDays === null) {
                    return -1;
                }

                if ($leftDays !== $rightDays) {
                    return $leftDays <=> $rightDays;
                }

                return $left['assignment']->id <=> $right['assignment']->id;
            })
            ->values();
    }

    private function urgencyRank(CrewReliefReadinessResult $relief): int
    {
        $days = $relief->daysUntilSignoff;

        if ($days !== null && $days < 0) {
            return 0;
        }

        if ($days === 0) {
            return 1;
        }

        if ($relief->risk === CrewReliefRisk::Critical) {
            return 2;
        }

        if ($days === null) {
            return 3;
        }

        if ($relief->risk === CrewReliefRisk::Warning) {
            return 4;
        }

        return 5;
    }

    /**
     * @param  Collection<int, array{assignment: CrewAssignment, relief: CrewReliefReadinessResult}>  $slice
     * @return Collection<int, CrewMobilisationReadinessResult>
     */
    private function readinessForPage(Collection $slice, int $companyId, User $user): Collection
    {
        $linkedIds = $slice
            ->map(fn (array $item): ?int => $item['relief']->reliefCrewAssignmentId)
            ->filter(fn (?int $id): bool => $id !== null && $id > 0)
            ->map(fn (?int $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($linkedIds === []) {
            return collect();
        }

        $linked = CrewAssignment::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $linkedIds)
            ->with(['employee', 'currentPhase'])
            ->get();

        $this->readinessResolver->attachForAssignments($linked, $companyId, $user);

        return $linked->mapWithKeys(fn (CrewAssignment $assignment): array => [
            (int) $assignment->id => $assignment->mobilisation_readiness instanceof CrewMobilisationReadinessResult
                ? $assignment->mobilisation_readiness
                : null,
        ])->filter();
    }

    private function tenantSafePlan(?CrewPlanningAssignment $plan, int $companyId): ?CrewPlanningAssignment
    {
        if ($plan === null || (int) $plan->company_id !== $companyId) {
            return null;
        }

        $employee = $plan->employee;

        if ($employee !== null && (int) $employee->company_id !== $companyId) {
            $plan->setRelation('employee', null);
        }

        $linked = $plan->crewAssignment;

        if ($linked !== null && (int) $linked->company_id !== $companyId) {
            $plan->setRelation('crewAssignment', null);
            $plan->crew_assignment_id = null;
        }

        return $plan;
    }

    private function nullableDate(string $value, string $timezone): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, $timezone)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
