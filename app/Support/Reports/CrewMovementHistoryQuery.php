<?php

namespace App\Support\Reports;

use App\Enums\CrewMovementCorrectionStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\Client;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Models\Vessel;
use App\Support\CrewMovements\CrewArrivalResolver;
use App\Support\CrewMovements\CrewMovementAttentionQuery;
use App\Support\CrewMovements\CrewTourStatusQuery;
use App\Support\Employees\EmployeeVisibilityScope;
use App\Support\Positions\CrewPositionCatalog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

final class CrewMovementHistoryQuery
{
    private const SORTS = [
        'actual_join',
        'assignment_no',
        'employee_no',
        'employee_name',
        'crew_name',
        'rank',
        'position',
        'vessel',
        'client',
        'status',
        'actual_arrival',
        'actual_disembarkation',
        'planned_join',
        'planned_signoff',
        'started_at',
        'closed_at',
        'created_at',
    ];

    public function __construct(
        private readonly int $companyId,
        private readonly CrewMovementHistoryFilters $filters,
        private readonly string $timezone,
        private readonly ?User $user = null,
    ) {}

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(int $perPage = 25): LengthAwarePaginator
    {
        $paginator = $this->ordered($this->filteredQuery())
            ->paginate($perPage)
            ->withQueryString();

        CrewPositionCatalog::hydrateCanonicalPositions($paginator->getCollection(), $this->companyId);

        return $paginator->through(fn (CrewAssignment $assignment): array => CrewMovementHistoryPresenter::toArray($assignment));
    }

    /**
     * @return Builder<CrewAssignment>
     */
    public function exportQuery(): Builder
    {
        return $this->ordered($this->filteredQuery());
    }

    /**
     * @return array{total: int, draft: int, active: int, completed: int, cancelled: int, on_vessel: int, needs_attention: int}
     */
    public function summary(): array
    {
        $query = $this->filteredQuery(withRelations: false);
        $counts = (clone $query)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft")
            ->selectRaw("SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active")
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed")
            ->selectRaw("SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled")
            ->first();

        return [
            'total' => (int) ($counts?->total ?? 0),
            'draft' => (int) ($counts?->draft ?? 0),
            'active' => (int) ($counts?->active ?? 0),
            'completed' => (int) ($counts?->completed ?? 0),
            'cancelled' => (int) ($counts?->cancelled ?? 0),
            'on_vessel' => (clone $query)
                ->whereHas('currentPhase', fn (Builder $phaseQuery) => $phaseQuery
                    ->where('company_id', $this->companyId)
                    ->where('phase_code', CrewPhaseCode::OnVessel))
                ->count(),
            // Authoritative CrewMovementAttentionQuery — same rules as row warnings.
            'needs_attention' => CrewMovementAttentionQuery::applyFilter(
                (clone $query),
                $this->companyId,
            )->count(),
        ];
    }

    /**
     * @return Builder<CrewAssignment>
     */
    private function filteredQuery(bool $withRelations = true): Builder
    {
        $query = CrewAssignment::query()
            ->where('crew_assignments.company_id', $this->companyId);

        if ($this->user !== null) {
            EmployeeVisibilityScope::whereHas($query, $this->user, $this->companyId, 'employee');
        }

        if ($withRelations) {
            $query->with([
                'company:id,timezone',
                'employee:id,company_id,employee_no,name',
                'position:id,company_id,title',
                'vessel:id,company_id,name',
                'client:id,name',
                'currentPhase' => fn ($query) => $query
                    ->select('id', 'company_id', 'crew_assignment_id', 'phase_code', 'status', 'actual_start_at', 'actual_end_at')
                    ->where('company_id', $this->companyId),
                'phases' => fn ($query) => $query
                    ->select('id', 'company_id', 'crew_assignment_id', 'phase_code', 'sequence', 'status', 'planned_start_at', 'planned_end_at', 'actual_start_at', 'actual_end_at', 'details', 'remarks')
                    ->where('company_id', $this->companyId)
                    ->orderBy('sequence'),
                'phases.employeeTraining' => fn ($query) => $query
                    ->select('id', 'company_id', 'source_crew_assignment_phase_id', 'course_id')
                    ->where('company_id', $this->companyId),
                'phases.employeeTraining.course:id,name',
                'corrections' => fn ($query) => $query
                    ->select([
                        'id',
                        'company_id',
                        'crew_assignment_id',
                        'status',
                        'decided_at',
                        'requested_at',
                    ])
                    ->where('company_id', $this->companyId)
                    ->whereIn('status', [
                        CrewMovementCorrectionStatus::Approved,
                        CrewMovementCorrectionStatus::Pending,
                    ]),
                'accommodationStays' => fn ($query) => $query
                    ->select([
                        'id',
                        'company_id',
                        'crew_assignment_id',
                        'hotel_id',
                        'room_type_id',
                        'stay_type',
                        'accommodation_status',
                        'check_in_date',
                        'check_out_date',
                        'started_from_phase_id',
                    ])
                    ->where('company_id', $this->companyId)
                    ->orderBy('id'),
                'accommodationStays.hotel' => fn ($query) => $query
                    ->select('id', 'company_id', 'name')
                    ->where('company_id', $this->companyId),
                'accommodationStays.roomType' => fn ($query) => $query
                    ->select('id', 'company_id', 'name')
                    ->where('company_id', $this->companyId),
                'accommodationStays.startedFromPhase' => fn ($query) => $query
                    ->select('id', 'company_id', 'phase_code')
                    ->where('company_id', $this->companyId),
                'previousAssignment' => fn ($query) => $query
                    ->select('id', 'company_id', 'employee_id', 'assignment_no', 'source', 'status', 'vessel_id', 'position_id', 'client_id', 'started_at', 'closed_at', 'current_phase_id')
                    ->where('company_id', $this->companyId),
                'previousAssignment.vessel' => fn ($query) => $query
                    ->select('id', 'company_id', 'name')
                    ->where('company_id', $this->companyId),
                'previousAssignment.position' => fn ($query) => $query
                    ->select('id', 'company_id', 'title')
                    ->where('company_id', $this->companyId),
                'previousAssignment.client:id,name',
                'previousAssignment.currentPhase' => fn ($query) => $query
                    ->select('id', 'company_id', 'phase_code')
                    ->where('company_id', $this->companyId),
                'previousAssignment.phases' => fn ($query) => $query
                    ->select('id', 'company_id', 'crew_assignment_id', 'phase_code', 'sequence', 'status', 'actual_start_at')
                    ->where('company_id', $this->companyId),
                'nextAssignments' => fn ($query) => $query
                    ->select('id', 'company_id', 'employee_id', 'previous_assignment_id', 'assignment_no', 'source', 'status', 'vessel_id', 'position_id', 'client_id', 'started_at', 'closed_at', 'current_phase_id')
                    ->where('company_id', $this->companyId),
                'nextAssignments.vessel' => fn ($query) => $query
                    ->select('id', 'company_id', 'name')
                    ->where('company_id', $this->companyId),
                'nextAssignments.position' => fn ($query) => $query
                    ->select('id', 'company_id', 'title')
                    ->where('company_id', $this->companyId),
                'nextAssignments.client:id,name',
                'nextAssignments.currentPhase' => fn ($query) => $query
                    ->select('id', 'company_id', 'phase_code')
                    ->where('company_id', $this->companyId),
                'nextAssignments.phases' => fn ($query) => $query
                    ->select('id', 'company_id', 'crew_assignment_id', 'phase_code', 'sequence', 'status', 'actual_start_at')
                    ->where('company_id', $this->companyId),
            ]);
        }

        $query
            ->when($this->filters->search !== '', function (Builder $inner): void {
                $like = '%'.$this->filters->search.'%';

                $inner->where(function (Builder $search) use ($like): void {
                    $search
                        ->where('crew_assignments.assignment_no', 'like', $like)
                        ->orWhere('crew_assignments.remarks', 'like', $like)
                        ->orWhereHas('employee', fn (Builder $employee) => $employee
                            ->where('company_id', $this->companyId)
                            ->where(function (Builder $e) use ($like): void {
                                $e->where('name', 'like', $like)
                                    ->orWhere('employee_no', 'like', $like);
                            }))
                        ->orWhereHas('vessel', fn (Builder $vessel) => $vessel
                            ->where('company_id', $this->companyId)
                            ->where('name', 'like', $like))
                        ->orWhereHas('client', fn (Builder $client) => $client
                            ->when(Schema::hasColumn('clients', 'company_id'), fn ($q) => $q->where('company_id', $this->companyId))
                            ->where('name', 'like', $like))
                        ->orWhereHas('position', fn (Builder $position) => $position
                            ->where('company_id', $this->companyId)
                            ->where('title', 'like', $like))
                        ->orWhereHas('previousAssignment', fn (Builder $previous) => $previous
                            ->where('company_id', $this->companyId)
                            ->whereColumn($previous->getModel()->qualifyColumn('employee_id'), 'crew_assignments.employee_id')
                            ->where('assignment_no', 'like', $like))
                        ->orWhereHas('nextAssignments', fn (Builder $next) => $next
                            ->where('company_id', $this->companyId)
                            ->whereColumn($next->getModel()->qualifyColumn('employee_id'), 'crew_assignments.employee_id')
                            ->where('assignment_no', 'like', $like))
                        ->orWhereHas('accommodationStays', fn (Builder $stay) => $stay
                            ->where('company_id', $this->companyId)
                            ->where(function (Builder $s) use ($like): void {
                                $s->whereHas('hotel', fn (Builder $hotel) => $hotel
                                    ->where('company_id', $this->companyId)
                                    ->where('name', 'like', $like))
                                    ->orWhereHas('roomType', fn (Builder $roomType) => $roomType
                                        ->where('company_id', $this->companyId)
                                        ->where('name', 'like', $like));
                            }))
                        ->orWhereHas('phases', function (Builder $phase) use ($like): void {
                            $phase->where('company_id', $this->companyId)
                                ->where('phase_code', CrewPhaseCode::Training)
                                ->where(function (Builder $training) use ($like): void {
                                    $training
                                        ->where('details->provider', 'like', $like)
                                        ->orWhere('details->course', 'like', $like)
                                        ->orWhereHas('employeeTraining', fn (Builder $t) => $t
                                            ->where('company_id', $this->companyId)
                                            ->whereHas('course', fn (Builder $c) => $c->where('name', 'like', $like)));
                                });
                        });
                });
            })
            ->when($this->filters->status !== '', fn (Builder $inner) => $inner->where('crew_assignments.status', $this->filters->status))
            ->when($this->filters->currentPhase !== '', fn (Builder $inner) => $inner->whereHas(
                'currentPhase',
                fn (Builder $phase) => $phase
                    ->where('company_id', $this->companyId)
                    ->where('phase_code', $this->filters->currentPhase),
            ))
            ->when($this->filters->vesselId !== '', fn (Builder $inner) => $inner
                ->where('crew_assignments.vessel_id', $this->filters->vesselId)
                ->whereHas('vessel', fn (Builder $vessel) => $vessel->where('company_id', $this->companyId)))
            ->when(
                $this->filters->positionId !== '',
                fn (Builder $inner) => $inner
                    ->where('crew_assignments.position_id', (int) $this->filters->positionId)
                    ->whereHas('position', fn (Builder $position) => $position->where('company_id', $this->companyId)),
            )
            ->when($this->filters->clientId !== '', fn (Builder $inner) => $inner
                ->where('crew_assignments.client_id', $this->filters->clientId)
                ->whereHas('client', fn (Builder $client) => $client
                    ->when(Schema::hasColumn('clients', 'company_id'), fn ($q) => $q->where('company_id', $this->companyId))))
            ->when($this->filters->source !== '', fn (Builder $inner) => $inner->where('crew_assignments.source', $this->filters->source))
            ->when($this->filters->plannedArrivalFrom !== '', fn (Builder $inner) => $inner->whereDate('crew_assignments.planned_arrival_at', '>=', $this->filters->plannedArrivalFrom))
            ->when($this->filters->plannedArrivalTo !== '', fn (Builder $inner) => $inner->whereDate('crew_assignments.planned_arrival_at', '<=', $this->filters->plannedArrivalTo))
            ->when($this->filters->plannedJoinFrom !== '', fn (Builder $inner) => $inner->whereDate('crew_assignments.planned_join_at', '>=', $this->filters->plannedJoinFrom))
            ->when($this->filters->plannedJoinTo !== '', fn (Builder $inner) => $inner->whereDate('crew_assignments.planned_join_at', '<=', $this->filters->plannedJoinTo))
            ->when($this->filters->plannedSignoffFrom !== '', fn (Builder $inner) => $inner->whereDate('crew_assignments.planned_signoff_at', '>=', $this->filters->plannedSignoffFrom))
            ->when($this->filters->plannedSignoffTo !== '', fn (Builder $inner) => $inner->whereDate('crew_assignments.planned_signoff_at', '<=', $this->filters->plannedSignoffTo))
            ->when($this->filters->assignmentStartedFrom !== '', fn (Builder $inner) => $inner->whereDate('crew_assignments.started_at', '>=', $this->filters->assignmentStartedFrom))
            ->when($this->filters->assignmentStartedTo !== '', fn (Builder $inner) => $inner->whereDate('crew_assignments.started_at', '<=', $this->filters->assignmentStartedTo))
            ->when($this->filters->assignmentClosedFrom !== '', fn (Builder $inner) => $inner->whereDate('crew_assignments.closed_at', '>=', $this->filters->assignmentClosedFrom))
            ->when($this->filters->assignmentClosedTo !== '', fn (Builder $inner) => $inner->whereDate('crew_assignments.closed_at', '<=', $this->filters->assignmentClosedTo))
            ->when($this->filters->hasApprovedCorrections === '1', fn (Builder $inner) => $inner->whereHas(
                'corrections',
                fn (Builder $correction) => $correction->where('status', CrewMovementCorrectionStatus::Approved),
            ))
            ->when($this->filters->hasPendingCorrections === '1', fn (Builder $inner) => $inner->whereHas(
                'corrections',
                fn (Builder $correction) => $correction->where('status', CrewMovementCorrectionStatus::Pending),
            ));

        $this->applyMovementDateFilters($query);
        $this->applyVesselServicePeriodFilter($query);
        $this->applyAccommodationFilters($query);

        if ($this->filters->tourStatus !== '') {
            (new CrewTourStatusQuery)->applyFilter($query, $this->filters->tourStatus, $this->companyId);
        }

        if ($this->filters->needsAttention === '1') {
            CrewMovementAttentionQuery::applyFilter($query, $this->companyId);
        }

        return $query;
    }

    /**
     * @param  Builder<CrewAssignment>  $query
     */
    private function applyMovementDateFilters(Builder $query): void
    {
        CrewArrivalResolver::applyDateFilter(
            $query,
            from: $this->filters->actualArrivalFrom !== '' ? $this->filters->actualArrivalFrom : null,
            to: $this->filters->actualArrivalTo !== '' ? $this->filters->actualArrivalTo : null,
        );

        $query
            ->when($this->filters->actualJoinFrom !== '', fn (Builder $inner) => $inner->whereHas(
                'phases',
                fn (Builder $phase) => $phase
                    ->where('company_id', $this->companyId)
                    ->where('phase_code', CrewPhaseCode::OnVessel)
                    ->whereDate('actual_start_at', '>=', $this->filters->actualJoinFrom),
            ))
            ->when($this->filters->actualJoinTo !== '', fn (Builder $inner) => $inner->whereHas(
                'phases',
                fn (Builder $phase) => $phase
                    ->where('company_id', $this->companyId)
                    ->where('phase_code', CrewPhaseCode::OnVessel)
                    ->whereDate('actual_start_at', '<=', $this->filters->actualJoinTo),
            ))
            ->when($this->filters->actualDisembarkationFrom !== '', fn (Builder $inner) => $inner->whereHas(
                'phases',
                fn (Builder $phase) => $phase
                    ->where('company_id', $this->companyId)
                    ->where('phase_code', CrewPhaseCode::OnVessel)
                    ->whereDate('actual_end_at', '>=', $this->filters->actualDisembarkationFrom),
            ))
            ->when($this->filters->actualDisembarkationTo !== '', fn (Builder $inner) => $inner->whereHas(
                'phases',
                fn (Builder $phase) => $phase
                    ->where('company_id', $this->companyId)
                    ->where('phase_code', CrewPhaseCode::OnVessel)
                    ->whereDate('actual_end_at', '<=', $this->filters->actualDisembarkationTo),
            ));
    }

    /**
     * @param  Builder<CrewAssignment>  $query
     */
    private function applyAccommodationFilters(Builder $query): void
    {
        $query
            ->when($this->filters->hotelId !== '', fn (Builder $inner) => $inner->whereHas(
                'accommodationStays',
                fn (Builder $stay) => $stay
                    ->where('company_id', $this->companyId)
                    ->where('hotel_id', $this->filters->hotelId),
            ))
            ->when($this->filters->accommodationStatus !== '', fn (Builder $inner) => $inner->whereHas(
                'accommodationStays',
                fn (Builder $stay) => $stay
                    ->where('company_id', $this->companyId)
                    ->where('accommodation_status', $this->filters->accommodationStatus),
            ))
            ->when($this->filters->stayType !== '', fn (Builder $inner) => $inner->whereHas(
                'accommodationStays',
                fn (Builder $stay) => $stay
                    ->where('company_id', $this->companyId)
                    ->where('stay_type', $this->filters->stayType),
            ));
    }

    /**
     * @param  Builder<CrewAssignment>  $query
     */
    private function applyVesselServicePeriodFilter(Builder $query): void
    {
        if ($this->filters->vesselServicePeriod === '' || $this->filters->vesselServicePeriod === 'all') {
            return;
        }

        $now = now($this->timezone);
        [$periodStart, $periodEnd] = match ($this->filters->vesselServicePeriod) {
            'this_month' => [
                $now->copy()->startOfMonth()->toDateString(),
                $now->copy()->endOfMonth()->toDateString(),
            ],
            'last_month' => [
                $now->copy()->subMonth()->startOfMonth()->toDateString(),
                $now->copy()->subMonth()->endOfMonth()->toDateString(),
            ],
            'last_3_months' => [
                $now->copy()->subMonths(2)->startOfMonth()->toDateString(),
                $now->copy()->endOfMonth()->toDateString(),
            ],
            'this_year' => [
                $now->copy()->startOfYear()->toDateString(),
                $now->copy()->endOfYear()->toDateString(),
            ],
            default => [null, null],
        };

        if ($periodStart === null || $periodEnd === null) {
            return;
        }

        $query->whereHas('phases', function (Builder $phase) use ($periodStart, $periodEnd): void {
            $phase
                ->where('company_id', $this->companyId)
                ->where('phase_code', CrewPhaseCode::OnVessel)
                ->whereDate('actual_start_at', '<=', $periodEnd)
                ->where(function (Builder $endQuery) use ($periodStart): void {
                    $endQuery->whereDate('actual_end_at', '>=', $periodStart)
                        ->orWhereNull('actual_end_at')
                        ->orWhere('status', CrewPhaseStatus::Active);
                });
        });
    }

    /**
     * @param  Builder<CrewAssignment>  $query
     * @return Builder<CrewAssignment>
     */
    private function ordered(Builder $query): Builder
    {
        $sort = in_array($this->filters->sort, self::SORTS, true) ? $this->filters->sort : 'actual_join';
        $direction = $this->filters->direction;

        $ordered = match ($sort) {
            'employee_no' => $query->orderBy(
                Employee::query()->select('employee_no')
                    ->whereColumn('employees.id', 'crew_assignments.employee_id')
                    ->where('employees.company_id', $this->companyId),
                $direction,
            ),
            'employee_name', 'crew_name' => $query->orderBy(
                Employee::query()->select('name')
                    ->whereColumn('employees.id', 'crew_assignments.employee_id')
                    ->where('employees.company_id', $this->companyId),
                $direction,
            ),
            'rank', 'position' => $query->orderBy(
                Position::query()->select('title')
                    ->whereColumn('positions.id', 'crew_assignments.position_id')
                    ->where('positions.company_id', $this->companyId),
                $direction,
            ),
            'vessel' => $query->orderBy(
                Vessel::query()->select('name')
                    ->whereColumn('vessels.id', 'crew_assignments.vessel_id')
                    ->where('vessels.company_id', $this->companyId),
                $direction,
            ),
            'client' => $query->orderBy(
                Client::query()->select('name')
                    ->whereColumn('clients.id', 'crew_assignments.client_id')
                    ->when(Schema::hasColumn('clients', 'company_id'), fn ($q) => $q->where('company_id', $this->companyId)),
                $direction,
            ),
            'actual_arrival' => CrewArrivalResolver::applyOrderBy($query, $direction),
            'actual_join' => $query->orderBy(
                CrewAssignmentPhase::query()
                    ->select('actual_start_at')
                    ->whereColumn('crew_assignment_phases.crew_assignment_id', 'crew_assignments.id')
                    ->where('company_id', $this->companyId)
                    ->where('phase_code', CrewPhaseCode::OnVessel)
                    ->orderBy('sequence')
                    ->limit(1),
                $direction,
            )->orderBy('crew_assignments.started_at', 'desc'),
            'actual_disembarkation' => $query->orderBy(
                CrewAssignmentPhase::query()
                    ->select('actual_end_at')
                    ->whereColumn('crew_assignment_phases.crew_assignment_id', 'crew_assignments.id')
                    ->where('company_id', $this->companyId)
                    ->where('phase_code', CrewPhaseCode::OnVessel)
                    ->where('status', CrewPhaseStatus::Completed)
                    ->orderByDesc('sequence')
                    ->limit(1),
                $direction,
            ),
            'planned_join' => $query->orderBy('crew_assignments.planned_join_at', $direction),
            'planned_signoff' => $query->orderBy('crew_assignments.planned_signoff_at', $direction),
            'started_at' => $query->orderBy('crew_assignments.started_at', $direction),
            'closed_at' => $query->orderBy('crew_assignments.closed_at', $direction),
            'created_at' => $query->orderBy('crew_assignments.created_at', $direction),
            default => $query->orderBy("crew_assignments.{$sort}", $direction),
        };

        return $ordered->orderByDesc('crew_assignments.id');
    }
}
