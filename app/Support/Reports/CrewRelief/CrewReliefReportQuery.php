<?php

namespace App\Support\Reports\CrewRelief;

use App\Enums\CrewPhaseCode;
use App\Models\Client;
use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\User;
use App\Support\CrewMovements\CrewReliefPlanningLoader;
use App\Support\CrewMovements\CurrentOnboardCrewQuery;
use App\Support\Employees\EmployeeVisibilityScope;
use App\Support\Positions\CrewPositionCatalog;
use App\Support\Settings\CompanyTimezone;
use App\Support\Vessels\ResolvesCompanyVessels;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class CrewReliefReportQuery
{
    private readonly string $timezone;

    private readonly CarbonImmutable $today;

    /**
     * @var Collection<int, CrewAssignment|CrewPlanningAssignment>
     */
    private Collection $cachedPlans;

    /**
     * @var list<int>
     */
    private array $authorizedReliefEmployeeIds = [];

    public function __construct(
        private readonly int $companyId,
        private readonly CrewReliefReportFilters $filters,
        private readonly User $user,
        private readonly CrewReliefStatusResolver $statusResolver = new CrewReliefStatusResolver,
        private readonly CrewReliefAttentionResolver $attentionResolver = new CrewReliefAttentionResolver,
        private readonly CrewReliefPlanningLoader $loader = new CrewReliefPlanningLoader,
        private readonly CrewReliefReportPresenter $presenter = new CrewReliefReportPresenter,
        ?CarbonInterface $asOf = null,
    ) {
        $this->timezone = CompanyTimezone::forCompanyId($this->companyId);
        $this->today = CarbonImmutable::parse(
            ($asOf ?? now($this->timezone))->copy()->timezone($this->timezone)->toDateString(),
            $this->timezone,
        )->startOfDay();
        $this->cachedPlans = collect();
        $this->authorizedReliefEmployeeIds = [];
    }

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     pagination: array<string, mixed>,
     *     summary: array{
     *         signing_off_next_7_days: int,
     *         no_relief_assigned: int,
     *         relief_not_ready: int,
     *         overdue_signoffs: int
     *     },
     *     filters: array<string, mixed>,
     *     filter_options: array<string, mixed>
     * }
     */
    public function page(
        int $page = 1,
        string $path = '/organization/reports/crew-relief',
        array $queryString = [],
    ): array {
        $candidates = $this->resolveCandidateSummaries();
        $filtered = $this->applyInMemoryFilters($candidates);
        $summary = $this->summarize($filtered);
        $sorted = $this->sortCandidateSummaries($filtered);

        $perPage = $this->filters->perPage;
        $page = max(1, $page);
        $total = $sorted->count();
        $slice = $sorted->slice(($page - 1) * $perPage, $perPage)->values();

        $pageIds = $slice->pluck('id')->all();

        if ($pageIds === []) {
            $paginator = new LengthAwarePaginator([], $total, $perPage, $page, [
                'path' => $path,
                'query' => $queryString,
            ]);

            return [
                'rows' => [],
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ],
                'summary' => $summary,
                'filters' => $this->filters->toArray(),
                'filter_options' => $this->filterOptions(),
            ];
        }

        // Hydrate ONLY the assignments for the current page
        $hydratedAssignments = CrewAssignment::query()
            ->whereIn('id', $pageIds)
            ->with([
                'employee:id,company_id,name,employee_no,photo_url,department_id,user_id',
                'position:id,title',
                'position:id,title',
                'vessel:id,company_id,name',
                'client:id,name',
                'currentPhase',
                'phases:id,crew_assignment_id,phase_code,sequence,status,actual_start_at',
                'nextAssignments:id,company_id,previous_assignment_id,assignment_no,status,started_at,planned_join_at',
            ])
            ->get()
            ->keyBy('id');

        $rows = $slice->map(function (array $item) use ($hydratedAssignments): ?array {
            $assignment = $hydratedAssignments->get($item['id']);
            if ($assignment === null) {
                return null;
            }

            $plan = $this->cachedPlans->get($item['id']);
            $reliefEmployee = null;
            if ($plan !== null && $plan->employee !== null) {
                $reliefEmployee = [
                    'id' => (int) $plan->employee->id,
                    'name' => (string) $plan->employee->name,
                    'employee_no' => $plan->employee->employee_no !== null ? (string) $plan->employee->employee_no : null,
                ];
            }

            $actualJoinedDate = $this->statusResolver->actualJoinedDate($assignment, $this->timezone);
            $daysOnboard = $this->statusResolver->daysOnboard($assignment, $this->timezone, $this->today);
            $nextAssignment = $this->statusResolver->resolveNextAssignment($assignment);

            $rowPayload = [
                'assignment' => $assignment,
                'actual_joined_date' => $actualJoinedDate,
                'days_onboard' => $daysOnboard,
                'planned_signoff_at' => $item['planned_signoff_at'],
                'days_until_signoff' => $item['days_until_signoff'],
                'days_to_signoff_label' => $this->statusResolver->daysToSignoffLabel($item['days_until_signoff']),
                'has_relief' => $item['has_relief'],
                'relief_employee' => $reliefEmployee,
                'relief_status' => $item['relief_status_info']['display_label'],
                'relief_phase_code' => $item['relief_status_info']['phase_code'],
                'relief_planned_join' => $item['relief_planned_join'],
                'readiness' => $item['readiness'],
                'next_assignment' => $nextAssignment,
                'attention' => $item['attention'],
            ];

            return $this->presenter->present(
                $rowPayload,
                $this->user,
                $this->companyId,
                $this->authorizedReliefEmployeeIds,
            );
        })->filter()->values()->all();

        $paginator = new LengthAwarePaginator(
            $rows,
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
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'summary' => $summary,
            'filters' => $this->filters->toArray(),
            'filter_options' => $this->filterOptions(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function exportCollection(): Collection
    {
        $candidates = $this->resolveCandidateSummaries();
        $filtered = $this->applyInMemoryFilters($candidates);
        $sorted = $this->sortCandidateSummaries($filtered);

        $allIds = $sorted->pluck('id')->all();
        if ($allIds === []) {
            return collect();
        }

        $assignments = CrewAssignment::query()
            ->whereIn('id', $allIds)
            ->with([
                'employee:id,company_id,name,employee_no,photo_url,department_id,user_id',
                'position:id,title',
                'position:id,title',
                'vessel:id,company_id,name',
                'client:id,name',
                'currentPhase',
                'phases:id,crew_assignment_id,phase_code,sequence,status,actual_start_at',
                'nextAssignments:id,company_id,previous_assignment_id,assignment_no,status,started_at,planned_join_at',
            ])
            ->get()
            ->keyBy('id');

        return $sorted->map(function (array $item) use ($assignments): ?array {
            $assignment = $assignments->get($item['id']);
            if ($assignment === null) {
                return null;
            }

            $plan = $this->cachedPlans->get($item['id']);
            $reliefEmployee = null;
            if ($plan !== null && $plan->employee !== null) {
                $reliefEmployee = [
                    'id' => (int) $plan->employee->id,
                    'name' => (string) $plan->employee->name,
                    'employee_no' => $plan->employee->employee_no !== null ? (string) $plan->employee->employee_no : null,
                ];
            }

            $actualJoinedDate = $this->statusResolver->actualJoinedDate($assignment, $this->timezone);
            $daysOnboard = $this->statusResolver->daysOnboard($assignment, $this->timezone, $this->today);
            $nextAssignment = $this->statusResolver->resolveNextAssignment($assignment);

            $rowPayload = [
                'assignment' => $assignment,
                'actual_joined_date' => $actualJoinedDate,
                'days_onboard' => $daysOnboard,
                'planned_signoff_at' => $item['planned_signoff_at'],
                'days_until_signoff' => $item['days_until_signoff'],
                'days_to_signoff_label' => $this->statusResolver->daysToSignoffLabel($item['days_until_signoff']),
                'has_relief' => $item['has_relief'],
                'relief_employee' => $reliefEmployee,
                'relief_status' => $item['relief_status_info']['display_label'],
                'relief_phase_code' => $item['relief_status_info']['phase_code'],
                'relief_planned_join' => $item['relief_planned_join'],
                'readiness' => $item['readiness'],
                'next_assignment' => $nextAssignment,
                'attention' => $item['attention'],
            ];

            return $this->presenter->present(
                $rowPayload,
                $this->user,
                $this->companyId,
                $this->authorizedReliefEmployeeIds,
            );
        })->filter()->values();
    }

    /**
     * Resolve lightweight candidate summaries without hydrating complete relational models.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function resolveCandidateSummaries(): Collection
    {
        $query = CrewAssignment::query();

        // 1. Authoritative active On Vessel constraint with company and visibility scoping
        CurrentOnboardCrewQuery::applyConstraint($query, $this->companyId, $this->user);

        // Must have planned sign-off
        $query->whereNotNull('planned_signoff_at');

        // 2. SQL filters
        $this->applySqlFilters($query);

        // 3. Deterministic base ordering and lightweight select
        $rawCandidates = $query
            ->orderBy('planned_signoff_at')
            ->orderBy('id')
            ->get(['id', 'planned_signoff_at']);

        if ($rawCandidates->isEmpty()) {
            $this->cachedPlans = collect();
            $this->authorizedReliefEmployeeIds = [];

            return collect();
        }

        $candidateIds = $rawCandidates->pluck('id')->all();

        // 4. Batch load relief plans
        $this->cachedPlans = $this->loader->forSourceAssignmentIds(
            $this->companyId,
            $candidateIds,
        );

        // 5. Batch detect relief conflicts using typed non-colliding keys
        $conflicts = $this->statusResolver->detectBatchConflicts(
            $this->companyId,
            $this->cachedPlans,
            $this->timezone,
        );

        // 6. Pre-filter authorized relief employees for candidate privacy scoping
        $reliefEmpIds = $this->cachedPlans
            ->pluck('employee_id')
            ->filter(fn (?int $id): bool => $id !== null && $id > 0)
            ->unique()
            ->values()
            ->all();

        $this->authorizedReliefEmployeeIds = EmployeeVisibilityScope::filterAuthorizedEmployeeIds(
            $this->user,
            $this->companyId,
            $reliefEmpIds,
        );

        // 7. Map lightweight domain properties with privacy normalization
        return $rawCandidates->map(function (CrewAssignment $assignment) use ($conflicts): array {
            $sourceId = (int) $assignment->id;
            $plan = $this->cachedPlans->get($sourceId);
            $hasRelief = $plan !== null;

            $reliefEmployeeId = ($plan !== null && $plan->employee_id !== null)
                ? (int) $plan->employee_id
                : null;

            $isReliefRestricted = false;
            if ($reliefEmployeeId !== null) {
                $isReliefRestricted = ! in_array($reliefEmployeeId, $this->authorizedReliefEmployeeIds, true);
            }

            $signoffDate = $assignment->planned_signoff_at !== null
                ? $assignment->planned_signoff_at->copy()->timezone($this->timezone)->toDateString()
                : null;

            $daysUntil = $signoffDate !== null
                ? $this->statusResolver->daysUntilSignoff($signoffDate, $this->timezone, $this->today)
                : null;

            if ($isReliefRestricted) {
                $reliefStatusInfo = [
                    'display_label' => 'Restricted',
                    'phase_code' => null,
                    'planned_join_date' => null,
                ];
                $reliefPlannedJoin = null;
                $reliefConflict = null;
                $reliefPhaseCode = null;
                $readiness = [
                    'code' => 'restricted',
                    'label' => 'Restricted',
                ];

                if ($daysUntil !== null && $daysUntil < 0) {
                    $absDays = abs($daysUntil);
                    $attention = [
                        'level' => CrewReliefAttentionResolver::LEVEL_CRITICAL,
                        'badge' => $absDays === 1 ? 'Sign-off overdue by 1 day' : "Sign-off overdue by {$absDays} days",
                        'reason' => CrewReliefAttentionResolver::REASON_OVERDUE,
                        'urgency_rank' => 1,
                    ];
                } else {
                    $attention = [
                        'level' => CrewReliefAttentionResolver::LEVEL_NEUTRAL,
                        'badge' => 'Relief assigned',
                        'reason' => 'relief_assigned',
                        'urgency_rank' => 6,
                    ];
                }
            } else {
                $reliefStatusInfo = $this->statusResolver->resolveReliefStatusInfo($plan, $this->timezone);
                $reliefPlannedJoin = $reliefStatusInfo['planned_join_date'];

                // Relief timing problem: relief joins after outgoing crew's planned signoff
                $joinsLate = false;
                $daysLate = 0;
                if ($reliefPlannedJoin !== null && $signoffDate !== null && $reliefPlannedJoin > $signoffDate) {
                    $joinsLate = true;
                    $daysLate = (int) CarbonImmutable::parse($signoffDate, $this->timezone)->diffInDays(
                        CarbonImmutable::parse($reliefPlannedJoin, $this->timezone),
                        false,
                    );
                }

                // Relief conflict using typed non-colliding key
                $planKey = CrewReliefPlanKey::for($plan);
                $reliefConflict = $planKey !== null ? ($conflicts[$planKey] ?? null) : null;

                $reliefPhaseCode = $reliefStatusInfo['phase_code'] !== null
                    ? CrewPhaseCode::tryFrom($reliefStatusInfo['phase_code'])
                    : null;

                $readiness = $this->statusResolver->resolveReadiness(
                    $hasRelief,
                    $reliefPhaseCode,
                    $reliefConflict !== null,
                    $joinsLate,
                );

                $attention = $this->attentionResolver->resolve([
                    'days_until_signoff' => $daysUntil,
                    'has_relief' => $hasRelief,
                    'relief_conflict' => $reliefConflict,
                    'relief_joins_late' => $joinsLate,
                    'days_late' => $daysLate,
                    'relief_phase_code' => $reliefPhaseCode,
                    'readiness' => $readiness['code'],
                ]);
            }

            return [
                'id' => $sourceId,
                'planned_signoff_at' => $signoffDate,
                'days_until_signoff' => $daysUntil,
                'has_relief' => $hasRelief,
                'relief_status_info' => $reliefStatusInfo,
                'relief_planned_join' => $reliefPlannedJoin,
                'relief_conflict' => $reliefConflict,
                'relief_phase_code' => $reliefPhaseCode,
                'readiness' => $readiness,
                'attention' => $attention,
                'relief_employee_id' => $reliefEmployeeId,
                'is_relief_restricted' => $isReliefRestricted,
            ];
        })->values();
    }

    /**
     * @param  Builder<CrewAssignment>  $query
     */
    private function applySqlFilters(Builder $query): void
    {
        $search = $this->filters->search;

        if ($search !== '') {
            $companyId = $this->companyId;
            $user = $this->user;

            $query->where(function (Builder $q) use ($search, $companyId, $user): void {
                $q->where('assignment_no', 'like', '%'.$search.'%')
                    ->orWhere('remarks', 'like', '%'.$search.'%')
                    ->orWhereHas('employee', function (Builder $e) use ($search, $companyId, $user): void {
                        EmployeeVisibilityScope::apply($e, $user, $companyId);
                        $e->where(function (Builder $sub) use ($search): void {
                            $sub->where('name', 'like', '%'.$search.'%')
                                ->orWhere('employee_no', 'like', '%'.$search.'%');
                        });
                    })
                    ->orWhereHas('vessel', fn (Builder $v) => $v->where('name', 'like', '%'.$search.'%'))
                    ->orWhereHas('position', fn (Builder $p) => $p->where('title', 'like', '%'.$search.'%'))
                    ->orWhereHas('client', fn (Builder $c) => $c->where('name', 'like', '%'.$search.'%'))
                    ->orWhereHas('reliefAssignments', function (Builder $relief) use ($search, $companyId, $user): void {
                        $relief->where('company_id', $companyId)
                            ->whereHas('employee', function (Builder $e) use ($search, $companyId, $user): void {
                                EmployeeVisibilityScope::apply($e, $user, $companyId);
                                $e->where('name', 'like', '%'.$search.'%')
                                    ->orWhere('employee_no', 'like', '%'.$search.'%');
                            });
                    })
                    ->orWhereHas('reliefPlanningAssignments', function (Builder $planning) use ($search, $companyId, $user): void {
                        $planning->where('company_id', $companyId)
                            ->whereHas('employee', function (Builder $e) use ($search, $companyId, $user): void {
                                EmployeeVisibilityScope::apply($e, $user, $companyId);
                                $e->where('name', 'like', '%'.$search.'%')
                                    ->orWhere('employee_no', 'like', '%'.$search.'%');
                            });
                    });
            });
        }

        if ($this->filters->vesselId !== '') {
            $query->where('vessel_id', (int) $this->filters->vesselId);
        }

        if ($this->filters->clientId !== '') {
            $query->where('client_id', (int) $this->filters->clientId);
        }

        if ($this->filters->positionId !== '') {
            $query->where('crew_assignments.position_id', (int) $this->filters->positionId);
        }

        $from = null;
        if ($this->filters->plannedSignoffFrom !== '') {
            try {
                $from = CarbonImmutable::parse($this->filters->plannedSignoffFrom, $this->timezone)->startOfDay();
            } catch (\Throwable) {
                $from = null;
            }
        }

        $to = null;
        if ($this->filters->plannedSignoffTo !== '') {
            try {
                $to = CarbonImmutable::parse($this->filters->plannedSignoffTo, $this->timezone)->endOfDay();
            } catch (\Throwable) {
                $to = null;
            }
        }

        if ($from !== null && $to !== null && $to < $from) {
            $query->whereRaw('0 = 1');

            return;
        }

        if ($from !== null || $to !== null) {
            if ($from !== null) {
                $query->where('planned_signoff_at', '>=', $from);
            }
            if ($to !== null) {
                $query->where('planned_signoff_at', '<=', $to);
            }
        } else {
            // Apply preset or default horizon (30 days + overdue)
            match ($this->filters->preset) {
                CrewReliefReportFilters::PRESET_NEXT_7_DAYS => $query->where('planned_signoff_at', '<=', $this->today->addDays(7)->endOfDay()),
                CrewReliefReportFilters::PRESET_NEXT_14_DAYS => $query->where('planned_signoff_at', '<=', $this->today->addDays(14)->endOfDay()),
                CrewReliefReportFilters::PRESET_OVERDUE => $query->where('planned_signoff_at', '<', $this->today->startOfDay()),
                CrewReliefReportFilters::PRESET_ALL => null,
                default => $query->where('planned_signoff_at', '<=', $this->today->addDays(30)->endOfDay()),
            };
        }
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $candidates
     * @return array{
     *     signing_off_next_7_days: int,
     *     no_relief_assigned: int,
     *     relief_not_ready: int,
     *     overdue_signoffs: int
     * }
     */
    private function summarize(Collection $candidates): array
    {
        $summary = [
            'signing_off_next_7_days' => 0,
            'no_relief_assigned' => 0,
            'relief_not_ready' => 0,
            'overdue_signoffs' => 0,
        ];

        foreach ($candidates as $row) {
            $days = $row['days_until_signoff'];
            $hasRelief = $row['has_relief'];
            $readinessCode = $row['readiness']['code'];

            if ($days !== null && $days >= 0 && $days <= 7) {
                $summary['signing_off_next_7_days']++;
            }

            if (! $hasRelief) {
                $summary['no_relief_assigned']++;
            }

            if ($hasRelief && in_array($readinessCode, ['in_progress', 'at_risk'], true)) {
                $summary['relief_not_ready']++;
            }

            if ($days !== null && $days < 0) {
                $summary['overdue_signoffs']++;
            }
        }

        return $summary;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $candidates
     * @return Collection<int, array<string, mixed>>
     */
    private function applyInMemoryFilters(Collection $candidates): Collection
    {
        return $candidates->filter(function (array $row): bool {
            // Readiness filter
            if ($this->filters->readiness !== '' && $this->filters->readiness !== 'all') {
                if ($row['readiness']['code'] !== $this->filters->readiness) {
                    return false;
                }
            }

            // Attention filter
            if ($this->filters->attention !== '' && $this->filters->attention !== 'all') {
                if ($row['attention']['level'] !== $this->filters->attention
                    && $row['attention']['reason'] !== $this->filters->attention) {
                    return false;
                }
            }

            // Preset in-memory filters
            if ($this->filters->preset === CrewReliefReportFilters::PRESET_NO_RELIEF) {
                if ($row['has_relief']) {
                    return false;
                }
            } elseif ($this->filters->preset === CrewReliefReportFilters::PRESET_NOT_READY) {
                if (! $row['has_relief'] || ! in_array($row['readiness']['code'], ['in_progress', 'at_risk'], true)) {
                    return false;
                }
            } elseif ($this->filters->preset === CrewReliefReportFilters::PRESET_OVERDUE) {
                if ($row['days_until_signoff'] === null || $row['days_until_signoff'] >= 0) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $candidates
     * @return Collection<int, array<string, mixed>>
     */
    private function sortCandidateSummaries(Collection $candidates): Collection
    {
        return $candidates->sort(function (array $left, array $right): int {
            // 1. Urgency rank
            $leftRank = (int) $left['attention']['urgency_rank'];
            $rightRank = (int) $right['attention']['urgency_rank'];

            if ($leftRank !== $rightRank) {
                return $leftRank <=> $rightRank;
            }

            // 2. Planned signoff date ascending
            $leftSignoff = $left['planned_signoff_at'];
            $rightSignoff = $right['planned_signoff_at'];

            if ($leftSignoff === null && $rightSignoff === null) {
                return $left['id'] <=> $right['id'];
            }

            if ($leftSignoff === null) {
                return 1;
            }

            if ($rightSignoff === null) {
                return -1;
            }

            if ($leftSignoff !== $rightSignoff) {
                return $leftSignoff <=> $rightSignoff;
            }

            return $left['id'] <=> $right['id'];
        })->values();
    }

    /**
     * @return array{
     *     vessels: list<array{id: int, name: string, client_id: int|null}>,
     *     clients: list<array{id: int, name: string}>,
     *     ranks: list<array{id: int, name: string}>,
     *     readiness_options: list<array{value: string, label: string}>,
     *     attention_options: list<array{value: string, label: string}>
     * }
     */
    public function filterOptions(): array
    {
        return [
            'vessels' => ResolvesCompanyVessels::activeOptions($this->companyId),
            'clients' => Client::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Client $c): array => [
                    'id' => (int) $c->id,
                    'name' => (string) $c->name,
                ])
                ->values()
                ->all(),
            'positions' => CrewPositionCatalog::crewPositionOptions($this->companyId),
            'readiness_options' => [
                ['value' => 'all', 'label' => 'All Readiness'],
                ['value' => 'ready', 'label' => 'Ready'],
                ['value' => 'in_progress', 'label' => 'In Progress'],
                ['value' => 'not_assigned', 'label' => 'Not Assigned'],
                ['value' => 'at_risk', 'label' => 'At Risk'],
                ['value' => 'joined', 'label' => 'Joined'],
            ],
            'attention_options' => [
                ['value' => 'all', 'label' => 'All Attention'],
                ['value' => 'critical', 'label' => 'Critical / Red'],
                ['value' => 'warning', 'label' => 'Warning / Amber'],
                ['value' => 'healthy', 'label' => 'Healthy / Green'],
            ],
        ];
    }
}
