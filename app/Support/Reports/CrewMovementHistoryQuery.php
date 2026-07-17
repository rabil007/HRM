<?php

namespace App\Support\Reports;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementCorrectionStatus;
use App\Enums\CrewPhaseCode;
use App\Models\Client;
use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Models\Rank;
use App\Models\Vessel;
use Carbon\CarbonImmutable;
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
            'needs_attention' => $this->applyNeedsAttention((clone $query))->count(),
        ];
    }

    /**
     * @return Builder<CrewAssignment>
     */
    private function filteredQuery(bool $withRelations = true): Builder
    {
        $query = CrewAssignment::query()
            ->where('crew_assignments.company_id', $this->companyId);

        if ($withRelations) {
            $query->with([
                'company:id,timezone',
                'employee:id,company_id,employee_no,name',
                'rank:id,name',
                'vessel:id,name',
                'client:id,name',
                'companyVisaType:id,name',
                'currentPhase:id,crew_assignment_id,phase_code,status,actual_start_at,actual_end_at',
                'phases:id,company_id,crew_assignment_id,phase_code,sequence,status,planned_start_at,planned_end_at,actual_start_at,actual_end_at,details,remarks',
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
                        ->orWhereHas('vessel', fn (Builder $vessel) => $vessel->where('name', 'like', $like));
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
            ->when($this->filters->visaTypeId !== '', fn (Builder $inner) => $inner->where('crew_assignments.company_visa_type_id', $this->filters->visaTypeId))
            ->when($this->filters->source !== '', fn (Builder $inner) => $inner->where('crew_assignments.source', $this->filters->source))
            ->when($this->filters->plannedJoinFrom !== '', fn (Builder $inner) => $inner->whereDate('crew_assignments.planned_join_at', '>=', $this->filters->plannedJoinFrom))
            ->when($this->filters->plannedJoinTo !== '', fn (Builder $inner) => $inner->whereDate('crew_assignments.planned_join_at', '<=', $this->filters->plannedJoinTo))
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

        $this->applyOnVesselDateFilters($query);

        if ($this->filters->needsAttention === '1') {
            $this->applyNeedsAttention($query);
        }

        return $query;
    }

    /**
     * @param  Builder<CrewAssignment>  $query
     */
    private function applyOnVesselDateFilters(Builder $query): void
    {
        $query
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
     * @return Builder<CrewAssignment>
     */
    private function applyNeedsAttention(Builder $query): Builder
    {
        $today = CarbonImmutable::today($this->timezone);
        $draftStale = $today->subDays(7)->endOfDay()->utc();
        $phaseStale = $today->subDays(14)->endOfDay()->utc();

        return $query->where(function (Builder $attention) use ($today, $draftStale, $phaseStale): void {
            $attention
                ->where(function (Builder $draft) use ($draftStale): void {
                    $draft->where('crew_assignments.status', CrewAssignmentStatus::Draft)
                        ->where('crew_assignments.created_at', '<=', $draftStale);
                })
                ->orWhere(function (Builder $missingPhase): void {
                    $missingPhase->where('crew_assignments.status', CrewAssignmentStatus::Active)
                        ->whereNull('crew_assignments.current_phase_id');
                })
                ->orWhereHas('currentPhase', fn (Builder $phase) => $phase
                    ->whereNotNull('actual_start_at')
                    ->where('actual_start_at', '<', $phaseStale))
                ->orWhere(function (Builder $joinOverdue) use ($today): void {
                    $joinOverdue
                        ->where('crew_assignments.status', CrewAssignmentStatus::Active)
                        ->whereDate('crew_assignments.planned_join_at', '<', $today->toDateString())
                        ->whereDoesntHave('currentPhase', fn (Builder $phase) => $phase->where('phase_code', CrewPhaseCode::OnVessel));
                })
                ->orWhere(function (Builder $signoffOverdue) use ($today): void {
                    $signoffOverdue
                        ->whereDate('crew_assignments.planned_signoff_at', '<', $today->toDateString())
                        ->whereHas('currentPhase', fn (Builder $phase) => $phase->where('phase_code', CrewPhaseCode::OnVessel));
                })
                ->orWhere(function (Builder $missingPlacement): void {
                    $missingPlacement
                        ->where('crew_assignments.status', CrewAssignmentStatus::Active)
                        ->where(function (Builder $missing): void {
                            $missing->whereNull('crew_assignments.vessel_id')
                                ->orWhereNull('crew_assignments.rank_id');
                        })
                        ->whereHas('currentPhase', fn (Builder $phase) => $phase->whereIn('phase_code', [
                            CrewPhaseCode::ReadyToJoin,
                            CrewPhaseCode::OnVessel,
                        ]));
                });
        });
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
