<?php

namespace App\Support\Reports;

use App\Enums\CrewMovementCorrectionStatus;
use App\Enums\CrewPhaseCode;
use App\Models\Client;
use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Models\Rank;
use App\Models\User;
use App\Models\Vessel;
use App\Support\CrewMovements\CrewMovementAttentionQuery;
use App\Support\CrewMovements\CrewTourStatusQuery;
use App\Support\Employees\EmployeeVisibilityScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class CrewMovementHistoryQuery
{
    private const SORTS = [
        'assignment_no',
        'employee_name',
        'rank',
        'vessel',
        'client',
        'status',
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
        return $this->ordered($this->filteredQuery())
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (CrewAssignment $assignment): array => CrewMovementHistoryPresenter::toArray($assignment));
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
                ->whereHas('currentPhase', fn (Builder $phaseQuery) => $phaseQuery->where('phase_code', CrewPhaseCode::OnVessel))
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
                'rank:id,name',
                'vessel:id,name',
                'client:id,name',
                'currentPhase:id,crew_assignment_id,phase_code,status,actual_start_at,actual_end_at',
                'phases:id,company_id,crew_assignment_id,phase_code,sequence,status,planned_start_at,planned_end_at,actual_start_at,actual_end_at,details,remarks',
                'phases.employeeTraining:id,company_id,source_crew_assignment_phase_id,course_id',
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
                    ->orderBy('id'),
                'accommodationStays.hotel:id,name',
                'accommodationStays.roomType:id,name',
                'accommodationStays.startedFromPhase:id,phase_code',
                'previousAssignment:id,company_id,assignment_no,source,status,vessel_id,rank_id,client_id,started_at,closed_at,current_phase_id',
                'previousAssignment.vessel:id,name',
                'previousAssignment.rank:id,name',
                'previousAssignment.client:id,name',
                'previousAssignment.currentPhase:id,phase_code',
                'nextAssignments:id,company_id,previous_assignment_id,assignment_no,source,status,vessel_id,rank_id,client_id,started_at,closed_at,current_phase_id',
                'nextAssignments.vessel:id,name',
                'nextAssignments.rank:id,name',
                'nextAssignments.client:id,name',
                'nextAssignments.currentPhase:id,phase_code',
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
                            ->where('name', 'like', $like)
                            ->orWhere('employee_no', 'like', $like))
                        ->orWhereHas('vessel', fn (Builder $vessel) => $vessel->where('name', 'like', $like))
                        ->orWhereHas('client', fn (Builder $client) => $client->where('name', 'like', $like))
                        ->orWhereHas('rank', fn (Builder $rank) => $rank->where('name', 'like', $like))
                        ->orWhereHas('previousAssignment', fn (Builder $previous) => $previous
                            ->where('company_id', $this->companyId)
                            ->where('assignment_no', 'like', $like))
                        ->orWhereHas('nextAssignments', fn (Builder $next) => $next
                            ->where('company_id', $this->companyId)
                            ->where('assignment_no', 'like', $like))
                        ->orWhereHas('accommodationStays.hotel', fn (Builder $hotel) => $hotel
                            ->where('company_id', $this->companyId)
                            ->where('name', 'like', $like))
                        ->orWhereHas('accommodationStays.roomType', fn (Builder $roomType) => $roomType
                            ->where('name', 'like', $like))
                        ->orWhereHas('phases', function (Builder $phase) use ($like): void {
                            $phase->where('phase_code', CrewPhaseCode::Training)
                                ->where(function (Builder $training) use ($like): void {
                                    $training
                                        ->where('details->provider', 'like', $like)
                                        ->orWhere('details->course', 'like', $like);
                                });
                        });
                });
            })
            ->when($this->filters->status !== '', fn (Builder $inner) => $inner->where('crew_assignments.status', $this->filters->status))
            ->when($this->filters->currentPhase !== '', fn (Builder $inner) => $inner->whereHas(
                'currentPhase',
                fn (Builder $phase) => $phase->where('phase_code', $this->filters->currentPhase),
            ))
            ->when($this->filters->vesselId !== '', fn (Builder $inner) => $inner->where('crew_assignments.vessel_id', $this->filters->vesselId))
            ->when($this->filters->rankId !== '', fn (Builder $inner) => $inner->where('crew_assignments.rank_id', $this->filters->rankId))
            ->when($this->filters->clientId !== '', fn (Builder $inner) => $inner->where('crew_assignments.client_id', $this->filters->clientId))
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
        $query
            ->when($this->filters->actualArrivalFrom !== '', fn (Builder $inner) => $inner->whereHas(
                'phases',
                fn (Builder $phase) => $phase
                    ->where('phase_code', CrewPhaseCode::JoinStandby)
                    ->whereDate('actual_start_at', '>=', $this->filters->actualArrivalFrom),
            ))
            ->when($this->filters->actualArrivalTo !== '', fn (Builder $inner) => $inner->whereHas(
                'phases',
                fn (Builder $phase) => $phase
                    ->where('phase_code', CrewPhaseCode::JoinStandby)
                    ->whereDate('actual_start_at', '<=', $this->filters->actualArrivalTo),
            ))
            ->when($this->filters->actualJoinFrom !== '', fn (Builder $inner) => $inner->whereHas(
                'phases',
                fn (Builder $phase) => $phase
                    ->where('phase_code', CrewPhaseCode::OnVessel)
                    ->whereDate('actual_start_at', '>=', $this->filters->actualJoinFrom),
            ))
            ->when($this->filters->actualJoinTo !== '', fn (Builder $inner) => $inner->whereHas(
                'phases',
                fn (Builder $phase) => $phase
                    ->where('phase_code', CrewPhaseCode::OnVessel)
                    ->whereDate('actual_start_at', '<=', $this->filters->actualJoinTo),
            ))
            ->when($this->filters->actualDisembarkationFrom !== '', fn (Builder $inner) => $inner->whereHas(
                'phases',
                fn (Builder $phase) => $phase
                    ->where('phase_code', CrewPhaseCode::OnVessel)
                    ->whereDate('actual_end_at', '>=', $this->filters->actualDisembarkationFrom),
            ))
            ->when($this->filters->actualDisembarkationTo !== '', fn (Builder $inner) => $inner->whereHas(
                'phases',
                fn (Builder $phase) => $phase
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
     * @return Builder<CrewAssignment>
     */
    private function ordered(Builder $query): Builder
    {
        $sort = in_array($this->filters->sort, self::SORTS, true) ? $this->filters->sort : 'started_at';
        $direction = $this->filters->direction;

        $ordered = match ($sort) {
            'employee_name' => $query->orderBy(
                Employee::query()->select('name')->whereColumn('employees.id', 'crew_assignments.employee_id'),
                $direction,
            ),
            'rank' => $query->orderBy(
                Rank::query()->select('name')->whereColumn('ranks.id', 'crew_assignments.rank_id'),
                $direction,
            ),
            'vessel' => $query->orderBy(
                Vessel::query()->select('name')->whereColumn('vessels.id', 'crew_assignments.vessel_id'),
                $direction,
            ),
            'client' => $query->orderBy(
                Client::query()->select('name')->whereColumn('clients.id', 'crew_assignments.client_id'),
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
