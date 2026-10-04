<?php

namespace App\Support\CrewOperations;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMobilisationReadinessStatus;
use App\Enums\CrewPhaseCode;
use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\Position;
use App\Models\User;
use App\Models\Vessel;
use App\Support\CrewMovements\CrewMobilisationReadinessResolver;
use App\Support\CrewMovements\CrewMobilisationReadinessResult;
use App\Support\EmployeeDocuments\DocumentComplianceQuery;
use App\Support\Employees\EmployeeVisibilityScope;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;

final class CrewReadinessQuery
{
    public function __construct(
        private readonly CrewMobilisationReadinessResolver $readinessResolver = new CrewMobilisationReadinessResolver,
        private readonly DocumentComplianceQuery $complianceQuery = new DocumentComplianceQuery,
        private readonly CrewReadinessPresenter $presenter = new CrewReadinessPresenter,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $queryString
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     pagination: LengthAwarePaginator<int, mixed>,
     *     summary: array{
     *         upcoming_crew: int,
     *         ready: int,
     *         attention: int,
     *         not_ready: int,
     *         joining_7: int,
     *         no_checks: int
     *     },
     *     filters: array<string, mixed>,
     *     filter_options: array<string, mixed>
     * }
     */
    public function page(
        int $companyId,
        array $filters,
        User $user,
        int $page = 1,
        string $path = '/organization/crew-operations/readiness',
        array $queryString = [],
    ): array {
        $canViewPlanning = $user->can('crew_operations.planning.view');
        $canViewAssignments = $user->can('crew_operations.assignments.view');

        if (! $canViewPlanning && ! $canViewAssignments) {
            abort(403);
        }

        $filters['page'] = $page;
        $normalizedFilters = CrewReadinessFilters::normalize($filters);

        $timezone = CompanyTimezone::forCompanyId($companyId);
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $todayLocal = $today->toDateString();

        $windowCutoff = match ($normalizedFilters['window']) {
            CrewReadinessFilters::WINDOW_7 => $today->addDays(7)->toDateString(),
            CrewReadinessFilters::WINDOW_14 => $today->addDays(14)->toDateString(),
            CrewReadinessFilters::WINDOW_30 => $today->addDays(30)->toDateString(),
            default => null,
        };

        $planningRows = collect();
        if ($canViewPlanning && in_array($normalizedFilters['source'], [CrewReadinessFilters::SOURCE_ALL, CrewReadinessFilters::SOURCE_PLANNING], true)) {
            $planningRows = $this->queryPlanningRows($companyId, $user, $normalizedFilters, $todayLocal, $windowCutoff);
        }

        $assignmentRows = collect();
        if ($canViewAssignments && in_array($normalizedFilters['source'], [CrewReadinessFilters::SOURCE_ALL, CrewReadinessFilters::SOURCE_ASSIGNMENT], true)) {
            $assignmentRows = $this->queryAssignmentRows($companyId, $user, $normalizedFilters, $windowCutoff);
        }

        $allEmployeeIds = collect()
            ->concat($planningRows->pluck('employee_id'))
            ->concat($assignmentRows->pluck('employee_id'))
            ->filter(fn ($id): bool => $id !== null && (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $complianceByEmployee = $allEmployeeIds !== []
            ? $this->complianceQuery->itemsForEmployeeIds($companyId, $allEmployeeIds)
            : collect();

        $canViewDocuments = $user->can('documents.view');

        $candidates = $this->buildCandidates(
            $planningRows,
            $assignmentRows,
            $complianceByEmployee,
            $user,
            $canViewDocuments,
            $timezone,
            $today,
        );

        $summary = $this->summarize($candidates);

        $filtered = $this->applyFiltersAndFocus($candidates, $normalizedFilters);

        $sorted = $this->sortRows($filtered);

        $perPage = $normalizedFilters['per_page'];
        $currentPage = max(1, $normalizedFilters['page']);
        $total = $sorted->count();
        $slice = $sorted->slice(($currentPage - 1) * $perPage, $perPage)->values();

        $presentedRows = $slice->map(fn (array $candidate): array => $this->presenter->row($candidate, $user))->all();

        $paginator = new Paginator(
            items: $presentedRows,
            total: $total,
            perPage: $perPage,
            currentPage: $currentPage,
            options: [
                'path' => $path,
                'query' => $queryString,
            ]
        );

        $filterOptions = $this->resolveFilterOptions($companyId);

        return [
            'rows' => $presentedRows,
            'pagination' => $paginator,
            'summary' => $summary,
            'filters' => $normalizedFilters,
            'filter_options' => $filterOptions,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, CrewPlanningAssignment>
     */
    private function queryPlanningRows(
        int $companyId,
        User $user,
        array $filters,
        string $todayLocal,
        ?string $windowCutoff,
    ): Collection {
        $query = CrewPlanningAssignment::query()
            ->where('crew_planning_assignments.company_id', $companyId)
            ->whereNotNull('crew_planning_assignments.employee_id')
            ->whereNull('crew_planning_assignments.crew_assignment_id')
            ->whereDate('crew_planning_assignments.planned_leave_date', '>=', $todayLocal);

        $query->whereHas('employee', function (Builder $eq) use ($user, $companyId): void {
            EmployeeVisibilityScope::apply($eq, $user, $companyId);
        });

        if ($filters['vessel_id'] !== null) {
            $query->where('crew_planning_assignments.vessel_id', $filters['vessel_id']);
        }

        if ($filters['position_id'] !== null) {
            $query->where('crew_planning_assignments.position_id', $filters['position_id']);
        }

        if ($filters['search'] !== null) {
            $search = $filters['search'];
            $query->where(function (Builder $sq) use ($search): void {
                $sq->whereHas('employee', fn (Builder $eq) => $eq->where('name', 'like', "%{$search}%")->orWhere('employee_no', 'like', "%{$search}%"))
                    ->orWhereHas('vessel', fn (Builder $vq) => $vq->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('position', fn (Builder $pq) => $pq->where('title', 'like', "%{$search}%"));
            });
        }

        if ($windowCutoff !== null) {
            $query->where(function (Builder $wq) use ($windowCutoff): void {
                $wq->whereNull('crew_planning_assignments.planned_join_date')
                    ->orWhereDate('crew_planning_assignments.planned_join_date', '<=', $windowCutoff);
            });
        }

        return $query->with([
            'employee:id,company_id,name,employee_no,position_id,department_id',
            'vessel:id,name',
            'position:id,title',
        ])->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, CrewAssignment>
     */
    private function queryAssignmentRows(
        int $companyId,
        User $user,
        array $filters,
        ?string $windowCutoff,
    ): Collection {
        $query = CrewAssignment::query()
            ->where('crew_assignments.company_id', $companyId)
            ->whereNotNull('crew_assignments.employee_id')
            ->whereIn('crew_assignments.status', [CrewAssignmentStatus::Draft, CrewAssignmentStatus::Active]);

        $preJoinValues = CrewPhaseCode::preJoinValues();

        $query->where(function (Builder $q) use ($preJoinValues): void {
            $q->where(function (Builder $draftQuery) use ($preJoinValues): void {
                $draftQuery->where('crew_assignments.status', CrewAssignmentStatus::Draft)
                    ->where(function (Builder $sq) use ($preJoinValues): void {
                        $sq->whereNull('crew_assignments.current_phase_id')
                            ->orWhereHas('currentPhase', fn (Builder $pq) => $pq->whereIn('phase_code', $preJoinValues));
                    });
            })->orWhere(function (Builder $activeQuery) use ($preJoinValues): void {
                $activeQuery->where('crew_assignments.status', CrewAssignmentStatus::Active)
                    ->whereHas('currentPhase', fn (Builder $pq) => $pq->whereIn('phase_code', $preJoinValues));
            });
        });

        $query->whereHas('employee', function (Builder $eq) use ($user, $companyId): void {
            EmployeeVisibilityScope::apply($eq, $user, $companyId);
        });

        if ($filters['vessel_id'] !== null) {
            $query->where('crew_assignments.vessel_id', $filters['vessel_id']);
        }

        if ($filters['position_id'] !== null) {
            $query->where('crew_assignments.position_id', $filters['position_id']);
        }

        if ($filters['search'] !== null) {
            $search = $filters['search'];
            $query->where(function (Builder $sq) use ($search): void {
                $sq->whereHas('employee', fn (Builder $eq) => $eq->where('name', 'like', "%{$search}%")->orWhere('employee_no', 'like', "%{$search}%"))
                    ->orWhereHas('vessel', fn (Builder $vq) => $vq->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('position', fn (Builder $pq) => $pq->where('title', 'like', "%{$search}%"));
            });
        }

        if ($windowCutoff !== null) {
            $query->where(function (Builder $wq) use ($windowCutoff): void {
                $wq->whereNull('crew_assignments.planned_join_at')
                    ->orWhereDate('crew_assignments.planned_join_at', '<=', $windowCutoff);
            });
        }

        return $query->with([
            'employee:id,company_id,name,employee_no,position_id,department_id',
            'vessel:id,name',
            'position:id,title',
            'currentPhase',
        ])->get();
    }

    /**
     * @param  Collection<int, CrewPlanningAssignment>  $planningRows
     * @param  Collection<int, CrewAssignment>  $assignmentRows
     * @param  Collection<int, list<array<string, mixed>>>  $complianceByEmployee
     * @return Collection<int, array{
     *     source_type: 'planning'|'assignment',
     *     planning_assignment_id: int|null,
     *     crew_assignment_id: int|null,
     *     employee: array{id: int, name: string, employee_no: string|null},
     *     vessel: array{id: int, name: string}|null,
     *     position: array{id: int, title: string}|null,
     *     phase_code: string|null,
     *     phase_label: string|null,
     *     expected_arrival_date: string|null,
     *     expected_join_date: string|null,
     *     expected_signoff_date: string|null,
     *     days_until_join: int|null,
     *     is_overdue: bool,
     *     is_joining_soon: bool,
     *     readiness: CrewMobilisationReadinessResult
     * }>
     */
    private function buildCandidates(
        Collection $planningRows,
        Collection $assignmentRows,
        Collection $complianceByEmployee,
        User $user,
        bool $canViewDocuments,
        string $timezone,
        CarbonImmutable $today,
    ): Collection {
        $candidates = collect();

        foreach ($planningRows as $plan) {
            $employee = $plan->employee;
            if ($employee === null) {
                continue;
            }

            $rawItems = $complianceByEmployee->get((int) $employee->id) ?? [];
            $unique = $this->readinessResolver->uniqueByDocumentType($rawItems);
            $readiness = $this->readinessResolver->resultForComplianceItems(
                (int) $employee->id,
                $unique,
                $user,
                $canViewDocuments,
            );

            $arrivalDate = $plan->planned_arrival_date?->toDateString();
            $joinDate = $plan->planned_join_date?->toDateString();
            $signoffDate = $plan->planned_leave_date?->toDateString();

            $daysUntilJoin = null;
            $isOverdue = false;
            $isJoiningSoon = false;

            if ($joinDate !== null) {
                $parsedJoin = CarbonImmutable::parse($joinDate, $timezone)->startOfDay();
                $daysUntilJoin = (int) $today->diffInDays($parsedJoin, false);
                $isOverdue = $daysUntilJoin < 0;
                $isJoiningSoon = $daysUntilJoin >= 0 && $daysUntilJoin <= 7;
            }

            $candidates->push([
                'source_type' => 'planning',
                'planning_assignment_id' => (int) $plan->id,
                'crew_assignment_id' => null,
                'employee' => [
                    'id' => (int) $employee->id,
                    'name' => (string) $employee->name,
                    'employee_no' => $employee->employee_no !== null ? (string) $employee->employee_no : null,
                ],
                'vessel' => $plan->vessel !== null ? [
                    'id' => (int) $plan->vessel->id,
                    'name' => (string) $plan->vessel->name,
                ] : null,
                'position' => $plan->position !== null ? [
                    'id' => (int) $plan->position->id,
                    'title' => (string) $plan->position->title,
                ] : null,
                'phase_code' => null,
                'phase_label' => null,
                'expected_arrival_date' => $arrivalDate,
                'expected_join_date' => $joinDate,
                'expected_signoff_date' => $signoffDate,
                'days_until_join' => $daysUntilJoin,
                'is_overdue' => $isOverdue,
                'is_joining_soon' => $isJoiningSoon,
                'readiness' => $readiness,
            ]);
        }

        foreach ($assignmentRows as $assignment) {
            $employee = $assignment->employee;
            if ($employee === null) {
                continue;
            }

            $rawItems = $complianceByEmployee->get((int) $employee->id) ?? [];
            $unique = $this->readinessResolver->uniqueByDocumentType($rawItems);
            $readiness = $this->readinessResolver->resultForComplianceItems(
                (int) $employee->id,
                $unique,
                $user,
                $canViewDocuments,
            );

            $arrivalDate = $assignment->planned_arrival_at?->timezone($timezone)->toDateString();
            $joinDate = $assignment->planned_join_at?->timezone($timezone)->toDateString();
            $signoffDate = $assignment->planned_signoff_at?->timezone($timezone)->toDateString();

            $daysUntilJoin = null;
            $isOverdue = false;
            $isJoiningSoon = false;

            if ($joinDate !== null) {
                $parsedJoin = CarbonImmutable::parse($joinDate, $timezone)->startOfDay();
                $daysUntilJoin = (int) $today->diffInDays($parsedJoin, false);
                $isOverdue = $daysUntilJoin < 0;
                $isJoiningSoon = $daysUntilJoin >= 0 && $daysUntilJoin <= 7;
            }

            $phase = $assignment->currentPhase;

            $candidates->push([
                'source_type' => 'assignment',
                'planning_assignment_id' => null,
                'crew_assignment_id' => (int) $assignment->id,
                'employee' => [
                    'id' => (int) $employee->id,
                    'name' => (string) $employee->name,
                    'employee_no' => $employee->employee_no !== null ? (string) $employee->employee_no : null,
                ],
                'vessel' => $assignment->vessel !== null ? [
                    'id' => (int) $assignment->vessel->id,
                    'name' => (string) $assignment->vessel->name,
                ] : null,
                'position' => $assignment->position !== null ? [
                    'id' => (int) $assignment->position->id,
                    'title' => (string) $assignment->position->title,
                ] : null,
                'phase_code' => $phase?->phase_code?->value,
                'phase_label' => $phase?->phase_code?->label() ?? 'Draft',
                'expected_arrival_date' => $arrivalDate,
                'expected_join_date' => $joinDate,
                'expected_signoff_date' => $signoffDate,
                'days_until_join' => $daysUntilJoin,
                'is_overdue' => $isOverdue,
                'is_joining_soon' => $isJoiningSoon,
                'readiness' => $readiness,
            ]);
        }

        return $candidates;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $candidates
     * @return array{
     *     upcoming_crew: int,
     *     ready: int,
     *     attention: int,
     *     not_ready: int,
     *     joining_7: int,
     *     no_checks: int
     * }
     */
    private function summarize(Collection $candidates): array
    {
        return [
            'upcoming_crew' => $candidates->count(),
            'ready' => $candidates->filter(fn (array $c): bool => $c['readiness']->status === CrewMobilisationReadinessStatus::Ready && $c['readiness']->hasConfiguredChecks())->count(),
            'attention' => $candidates->filter(fn (array $c): bool => $c['readiness']->status === CrewMobilisationReadinessStatus::Attention)->count(),
            'not_ready' => $candidates->filter(fn (array $c): bool => $c['readiness']->status === CrewMobilisationReadinessStatus::NotReady)->count(),
            'joining_7' => $candidates->filter(fn (array $c): bool => $c['days_until_join'] !== null && $c['days_until_join'] <= 7)->count(),
            'no_checks' => $candidates->filter(fn (array $c): bool => ! $c['readiness']->hasConfiguredChecks())->count(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $candidates
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function applyFiltersAndFocus(Collection $candidates, array $filters): Collection
    {
        $filtered = $candidates;

        if ($filters['readiness_status'] !== CrewReadinessFilters::STATUS_ALL) {
            $filtered = $filtered->filter(fn (array $c): bool => $c['readiness']->status->value === $filters['readiness_status']);
        }

        if ($filters['focus'] !== '') {
            $filtered = match ($filters['focus']) {
                CrewReadinessFilters::FOCUS_READY => $filtered->filter(fn (array $c): bool => $c['readiness']->status === CrewMobilisationReadinessStatus::Ready && $c['readiness']->hasConfiguredChecks()),
                CrewReadinessFilters::FOCUS_ATTENTION => $filtered->filter(fn (array $c): bool => $c['readiness']->status === CrewMobilisationReadinessStatus::Attention),
                CrewReadinessFilters::FOCUS_NOT_READY => $filtered->filter(fn (array $c): bool => $c['readiness']->status === CrewMobilisationReadinessStatus::NotReady),
                CrewReadinessFilters::FOCUS_JOINING_7 => $filtered->filter(fn (array $c): bool => $c['days_until_join'] !== null && $c['days_until_join'] <= 7),
                CrewReadinessFilters::FOCUS_NO_CHECKS => $filtered->filter(fn (array $c): bool => ! $c['readiness']->hasConfiguredChecks()),
                default => $filtered,
            };
        }

        return $filtered->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $candidates
     * @return Collection<int, array<string, mixed>>
     */
    private function sortRows(Collection $candidates): Collection
    {
        return $candidates->sort(function (array $a, array $b): int {
            $statusWeight = fn (CrewMobilisationReadinessResult $r): int => match ($r->status) {
                CrewMobilisationReadinessStatus::NotReady => 1,
                CrewMobilisationReadinessStatus::Attention => 2,
                CrewMobilisationReadinessStatus::Ready => 3,
            };

            $weightA = $statusWeight($a['readiness']);
            $weightB = $statusWeight($b['readiness']);

            if ($weightA !== $weightB) {
                return $weightA <=> $weightB;
            }

            $joinA = $a['expected_join_date'] ?? '9999-12-31';
            $joinB = $b['expected_join_date'] ?? '9999-12-31';

            if ($joinA !== $joinB) {
                return strcmp($joinA, $joinB);
            }

            $arrivalA = $a['expected_arrival_date'] ?? '9999-12-31';
            $arrivalB = $b['expected_arrival_date'] ?? '9999-12-31';

            if ($arrivalA !== $arrivalB) {
                return strcmp($arrivalA, $arrivalB);
            }

            return strcasecmp((string) $a['employee']['name'], (string) $b['employee']['name']);
        })->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveFilterOptions(int $companyId): array
    {
        $vessels = Vessel::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Vessel $v): array => [
                'id' => (int) $v->id,
                'name' => (string) $v->name,
            ])
            ->all();

        $positions = Position::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->where('is_crew_position', true)
            ->orderBy('title')
            ->get(['id', 'title'])
            ->map(fn (Position $p): array => [
                'id' => (int) $p->id,
                'name' => (string) $p->title,
            ])
            ->all();

        return [
            'vessels' => $vessels,
            'positions' => $positions,
            'windows' => [
                ['value' => CrewReadinessFilters::WINDOW_7, 'label' => 'Next 7 days'],
                ['value' => CrewReadinessFilters::WINDOW_14, 'label' => 'Next 14 days'],
                ['value' => CrewReadinessFilters::WINDOW_30, 'label' => 'Next 30 days'],
                ['value' => CrewReadinessFilters::WINDOW_ALL, 'label' => 'All upcoming'],
            ],
            'sources' => [
                ['value' => CrewReadinessFilters::SOURCE_ALL, 'label' => 'All Sources'],
                ['value' => CrewReadinessFilters::SOURCE_PLANNING, 'label' => 'Future Planning'],
                ['value' => CrewReadinessFilters::SOURCE_ASSIGNMENT, 'label' => 'Operational Pre-Join'],
            ],
            'statuses' => [
                ['value' => CrewReadinessFilters::STATUS_ALL, 'label' => 'All Statuses'],
                ['value' => CrewReadinessFilters::STATUS_READY, 'label' => 'Ready'],
                ['value' => CrewReadinessFilters::STATUS_ATTENTION, 'label' => 'Needs Attention'],
                ['value' => CrewReadinessFilters::STATUS_NOT_READY, 'label' => 'Not Ready'],
            ],
        ];
    }
}
