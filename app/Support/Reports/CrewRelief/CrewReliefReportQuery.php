<?php

namespace App\Support\Reports\CrewRelief;

use App\Enums\CrewPhaseCode;
use App\Enums\CrewReliefRisk;
use App\Enums\CrewReliefStatus;
use App\Models\Client;
use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\User;
use App\Support\CrewMovements\CrewReliefFocusMatcher;
use App\Support\CrewMovements\CrewReliefPlanningLoader;
use App\Support\CrewMovements\CrewReliefReadinessResolver;
use App\Support\CrewMovements\CrewReliefReadinessResult;
use App\Support\CrewMovements\CrewReliefVisibility;
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
        private readonly CrewReliefReadinessResolver $readinessResolver = new CrewReliefReadinessResolver,
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
     *         overdue_signoffs: int,
     *         missing_planned_signoff: int
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

            return $this->presenter->present(
                $this->hydratedRowPayload($assignment, $item),
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

            return $this->presenter->present(
                $this->hydratedRowPayload($assignment, $item),
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

        // 2. SQL filters
        $this->applySqlFilters($query);

        // 3. Deterministic base ordering and lightweight select
        $rawCandidates = $query
            ->orderBy('planned_signoff_at')
            ->orderBy('id')
            ->get(['id', 'company_id', 'planned_signoff_at']);

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
        $readinessBySourceId = $rawCandidates->mapWithKeys(function (CrewAssignment $assignment): array {
            $sourceId = (int) $assignment->id;
            $plan = $this->tenantSafePlan($this->cachedPlans->get($sourceId));

            return [
                $sourceId => $this->readinessResolver->forPreloadedPlan(
                    $assignment,
                    $plan,
                    $this->today,
                    $this->timezone,
                ),
            ];
        });

        $this->authorizedReliefEmployeeIds = CrewReliefVisibility::authorizedReliefEmployeeIdsFromResults(
            $readinessBySourceId->values(),
            $this->user,
            $this->companyId,
        ) ?? [];

        // 7. Map lightweight domain properties with privacy normalization
        return $rawCandidates->map(function (CrewAssignment $assignment) use ($conflicts, $readinessBySourceId): array {
            $sourceId = (int) $assignment->id;
            $plan = $this->tenantSafePlan($this->cachedPlans->get($sourceId));
            $relief = $readinessBySourceId->get($sourceId);

            if (! $relief instanceof CrewReliefReadinessResult) {
                $relief = $this->readinessResolver->forPreloadedPlan(
                    $assignment,
                    $plan,
                    $this->today,
                    $this->timezone,
                );
            }

            $sanitizedRelief = $relief->sanitizeForViewer(
                $this->user,
                $this->companyId,
                $this->authorizedReliefEmployeeIds,
            );

            $hasRelief = $sanitizedRelief->status !== CrewReliefStatus::NoRelief;
            $signoffDate = $sanitizedRelief->sourcePlannedSignoffDate;
            $daysUntil = $sanitizedRelief->daysUntilSignoff;
            $missingPlannedSignoff = $signoffDate === null;
            $isReliefRestricted = $relief->reliefEmployee !== null && $sanitizedRelief->reliefEmployee === null;

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
                $reliefPlannedJoin = $sanitizedRelief->reliefPlannedJoinDate ?? $reliefStatusInfo['planned_join_date'];

                $joinsLate = false;
                $daysLate = 0;
                if ($reliefPlannedJoin !== null && $signoffDate !== null && $reliefPlannedJoin > $signoffDate) {
                    $joinsLate = true;
                    $daysLate = (int) CarbonImmutable::parse($signoffDate, $this->timezone)->diffInDays(
                        CarbonImmutable::parse($reliefPlannedJoin, $this->timezone),
                        false,
                    );
                }

                $planKey = CrewReliefPlanKey::for($plan);
                $reliefConflict = $planKey !== null ? ($conflicts[$planKey] ?? null) : null;

                $reliefPhaseCode = $sanitizedRelief->reliefPhase['code'] ?? null;
                $reliefPhaseCode = $reliefPhaseCode !== null
                    ? CrewPhaseCode::tryFrom($reliefPhaseCode)
                    : ($reliefStatusInfo['phase_code'] !== null
                        ? CrewPhaseCode::tryFrom($reliefStatusInfo['phase_code'])
                        : null);

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
                    'missing_planned_signoff' => $missingPlannedSignoff,
                ]);
            }

            return [
                'id' => $sourceId,
                'planned_signoff_at' => $signoffDate,
                'days_until_signoff' => $daysUntil,
                'missing_planned_signoff' => $missingPlannedSignoff,
                'has_relief' => $hasRelief,
                'relief_status_info' => $reliefStatusInfo,
                'relief_planned_join' => $reliefPlannedJoin,
                'relief_conflict' => $reliefConflict,
                'relief_phase_code' => $reliefPhaseCode,
                'readiness' => $readiness,
                'attention' => $attention,
                'relief_result' => $sanitizedRelief,
                'relief_result_raw' => $relief,
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

            return;
        }

        if ($this->filters->preset === CrewReliefReportFilters::PRESET_ALL) {
            return;
        }

        if ($this->filters->preset === CrewReliefReportFilters::PRESET_OVERDUE) {
            $query->where('planned_signoff_at', '<', $this->today->startOfDay());

            return;
        }

        $horizonDays = match ($this->filters->preset) {
            CrewReliefReportFilters::PRESET_NEXT_7_DAYS => 7,
            CrewReliefReportFilters::PRESET_NEXT_14_DAYS => 14,
            default => 30,
        };

        $horizonEnd = $this->today->addDays($horizonDays)->endOfDay();
        $query->where(function (Builder $q) use ($horizonEnd): void {
            $q->whereNull('planned_signoff_at')
                ->orWhere('planned_signoff_at', '<=', $horizonEnd);
        });
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
            'missing_planned_signoff' => 0,
        ];

        foreach ($candidates as $row) {
            $days = $row['days_until_signoff'];
            $hasRelief = $row['has_relief'];
            $readinessCode = $row['readiness']['code'];
            $reliefResult = $row['relief_result'] ?? null;

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

            if ($row['missing_planned_signoff'] ?? false) {
                $summary['missing_planned_signoff']++;
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
            if ($row['is_relief_restricted'] ?? false) {
                return $this->passesFiltersForRestrictedReliefRow($row);
            }

            return $this->passesFiltersForVisibleReliefRow($row);
        })->values();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function passesFiltersForVisibleReliefRow(array $row): bool
    {
        $reliefStatus = CrewReliefStatus::tryFrom($this->filters->reliefStatus);
        $reliefRisk = CrewReliefRisk::tryFrom($this->filters->reliefRisk);
        $focus = $this->filters->focus;
        $reliefResult = $row['relief_result'] ?? null;

        if ($reliefStatus !== null) {
            if (! $reliefResult instanceof CrewReliefReadinessResult || $reliefResult->status !== $reliefStatus) {
                return false;
            }
        }

        if ($reliefRisk !== null) {
            if (! $reliefResult instanceof CrewReliefReadinessResult || $reliefResult->risk !== $reliefRisk) {
                return false;
            }
        }

        if ($focus !== '') {
            if (! $reliefResult instanceof CrewReliefReadinessResult
                || ! CrewReliefFocusMatcher::matches($reliefResult, $focus)) {
                return false;
            }
        }

        return $this->passesSharedRowFilters($row);
    }

    /**
     * Rows with a hidden relief employee must not leak relief status, risk, readiness, or focus via filters.
     *
     * @param  array<string, mixed>  $row
     */
    private function passesFiltersForRestrictedReliefRow(array $row): bool
    {
        if (CrewReliefStatus::tryFrom($this->filters->reliefStatus) !== null) {
            return false;
        }

        if (CrewReliefRisk::tryFrom($this->filters->reliefRisk) !== null) {
            return false;
        }

        if ($this->filters->focus !== '') {
            if (! $this->matchesSourceOnlyFocus($row, $this->filters->focus)) {
                return false;
            }
        }

        if ($this->filters->readiness !== '' && $this->filters->readiness !== 'all') {
            if ($this->filters->readiness !== 'restricted') {
                return false;
            }
        }

        if ($this->filters->attention !== '' && $this->filters->attention !== 'all') {
            $level = $row['attention']['level'] ?? null;
            $reason = $row['attention']['reason'] ?? null;

            if ($this->filters->attention === CrewReliefAttentionResolver::REASON_MISSING_PLANNED_SIGNOFF) {
                if ($reason !== CrewReliefAttentionResolver::REASON_MISSING_PLANNED_SIGNOFF) {
                    return false;
                }
            } elseif ($this->filters->attention === 'critical') {
                if ($level !== CrewReliefAttentionResolver::LEVEL_CRITICAL) {
                    return false;
                }
            } else {
                return false;
            }
        }

        if ($this->filters->preset === CrewReliefReportFilters::PRESET_NO_RELIEF
            || $this->filters->preset === CrewReliefReportFilters::PRESET_NOT_READY) {
            return false;
        }

        if ($this->filters->preset === CrewReliefReportFilters::PRESET_OVERDUE) {
            if ($row['days_until_signoff'] === null || $row['days_until_signoff'] >= 0) {
                return false;
            }
        } elseif ($this->filters->preset === CrewReliefReportFilters::PRESET_MISSING_PLANNED_SIGNOFF) {
            if (! ($row['missing_planned_signoff'] ?? false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function passesSharedRowFilters(array $row): bool
    {
        if ($this->filters->readiness !== '' && $this->filters->readiness !== 'all') {
            if ($row['readiness']['code'] !== $this->filters->readiness) {
                return false;
            }
        }

        if ($this->filters->attention !== '' && $this->filters->attention !== 'all') {
            if ($row['attention']['level'] !== $this->filters->attention
                && $row['attention']['reason'] !== $this->filters->attention) {
                return false;
            }
        }

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
        } elseif ($this->filters->preset === CrewReliefReportFilters::PRESET_MISSING_PLANNED_SIGNOFF) {
            if (! ($row['missing_planned_signoff'] ?? false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Focus presets that depend only on the visible outgoing assignment, not hidden relief metadata.
     *
     * @param  array<string, mixed>  $row
     */
    private function matchesSourceOnlyFocus(array $row, string $focus): bool
    {
        $days = $row['days_until_signoff'];

        return match ($focus) {
            'overdue' => $days !== null && $days < 0,
            'missing_planned_signoff' => (bool) ($row['missing_planned_signoff'] ?? false),
            'signoff_7' => $days !== null && $days >= 0 && $days <= 7,
            'signoff_14' => $days !== null && $days >= 0 && $days <= 14,
            default => false,
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $candidates
     * @return Collection<int, array<string, mixed>>
     */
    private function sortCandidateSummaries(Collection $candidates): Collection
    {
        return $candidates->sort(function (array $left, array $right): int {
            $leftRank = $this->operationalUrgencyRank($left);
            $rightRank = $this->operationalUrgencyRank($right);

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
                ['value' => CrewReliefAttentionResolver::REASON_MISSING_PLANNED_SIGNOFF, 'label' => 'Missing Planned Sign-Off'],
            ],
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
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function hydratedRowPayload(CrewAssignment $assignment, array $item): array
    {
        $reliefResult = $item['relief_result'] ?? null;
        $reliefResultRaw = $item['relief_result_raw'] ?? $reliefResult;
        $reliefEmployee = null;

        if ($reliefResult instanceof CrewReliefReadinessResult && $reliefResult->reliefEmployee !== null) {
            $reliefEmployee = $reliefResult->reliefEmployee;
        }

        $actualJoinedDate = $this->statusResolver->actualJoinedDate($assignment, $this->timezone);
        $daysOnboard = $this->statusResolver->daysOnboard($assignment, $this->timezone, $this->today);
        $nextAssignment = $this->statusResolver->resolveNextAssignment($assignment);

        return [
            'assignment' => $assignment,
            'actual_joined_date' => $actualJoinedDate,
            'days_onboard' => $daysOnboard,
            'planned_signoff_at' => $item['planned_signoff_at'],
            'days_until_signoff' => $item['days_until_signoff'],
            'days_to_signoff_label' => $item['missing_planned_signoff'] ?? false
                ? 'Missing Planned Sign-Off'
                : $this->statusResolver->daysToSignoffLabel($item['days_until_signoff']),
            'missing_planned_signoff' => $item['missing_planned_signoff'] ?? false,
            'has_relief' => $item['has_relief'],
            'relief_employee' => $reliefEmployee,
            'relief_status' => $reliefResult instanceof CrewReliefReadinessResult
                ? $reliefResult->status->label()
                : ($item['relief_status_info']['display_label'] ?? '—'),
            'relief_status_code' => $reliefResult instanceof CrewReliefReadinessResult
                ? $reliefResult->status->value
                : null,
            'relief_risk' => $reliefResult instanceof CrewReliefReadinessResult
                ? $reliefResult->risk->value
                : null,
            'relief_risk_label' => $reliefResult instanceof CrewReliefReadinessResult
                ? $reliefResult->risk->label()
                : null,
            'relief_phase_code' => $item['relief_phase_code'] instanceof CrewPhaseCode
                ? $item['relief_phase_code']->value
                : ($item['relief_status_info']['phase_code'] ?? null),
            'relief_phase_label' => $reliefResult instanceof CrewReliefReadinessResult
                ? ($reliefResult->reliefPhase['label'] ?? null)
                : ($item['relief_status_info']['phase_label'] ?? null),
            'relief_planned_join' => $item['relief_planned_join'],
            'relief_planning_assignment_id' => $reliefResult instanceof CrewReliefReadinessResult
                ? $reliefResult->reliefPlanningAssignmentId
                : null,
            'relief_crew_assignment_id' => $reliefResult instanceof CrewReliefReadinessResult
                ? $reliefResult->reliefCrewAssignmentId
                : null,
            'readiness' => $item['readiness'],
            'next_assignment' => $nextAssignment,
            'attention' => $item['attention'],
            'relief_result' => $reliefResultRaw instanceof CrewReliefReadinessResult
                ? $reliefResultRaw
                : $reliefResult,
            'is_relief_restricted' => $item['is_relief_restricted'] ?? false,
        ];
    }

    private function operationalUrgencyRank(array $row): int
    {
        $days = $row['days_until_signoff'];
        $relief = $row['relief_result'] ?? null;

        if ($days !== null && $days < 0) {
            return 0;
        }

        if ($days === 0) {
            return 1;
        }

        if (! ($row['is_relief_restricted'] ?? false)
            && $relief instanceof CrewReliefReadinessResult
            && $relief->risk === CrewReliefRisk::Critical) {
            return 2;
        }

        if ($days === null) {
            return 3;
        }

        if (! ($row['is_relief_restricted'] ?? false)
            && $relief instanceof CrewReliefReadinessResult
            && $relief->risk === CrewReliefRisk::Warning) {
            return 4;
        }

        return 5;
    }

    private function tenantSafePlan(CrewAssignment|CrewPlanningAssignment|null $plan): CrewAssignment|CrewPlanningAssignment|null
    {
        if ($plan === null || (int) $plan->company_id !== $this->companyId) {
            return null;
        }

        $employee = $plan->employee;

        if ($employee !== null && (int) $employee->company_id !== $this->companyId) {
            $plan->setRelation('employee', null);
        }

        if ($plan instanceof CrewPlanningAssignment) {
            $linked = $plan->crewAssignment;

            if ($linked !== null && (int) $linked->company_id !== $this->companyId) {
                $plan->setRelation('crewAssignment', null);
                $plan->crew_assignment_id = null;
            }
        }

        return $plan;
    }
}
