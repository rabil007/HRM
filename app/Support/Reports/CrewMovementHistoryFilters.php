<?php

namespace App\Support\Reports;

use Illuminate\Http\Request;

final class CrewMovementHistoryFilters
{
    public function __construct(
        public readonly string $search = '',
        public readonly string $status = '',
        public readonly string $currentPhase = '',
        public readonly string $vesselId = '',
        public readonly string $rankId = '',
        public readonly string $clientId = '',
        public readonly string $source = '',
        public readonly string $needsAttention = '',
        public readonly string $plannedArrivalFrom = '',
        public readonly string $plannedArrivalTo = '',
        public readonly string $plannedJoinFrom = '',
        public readonly string $plannedJoinTo = '',
        public readonly string $plannedSignoffFrom = '',
        public readonly string $plannedSignoffTo = '',
        public readonly string $actualArrivalFrom = '',
        public readonly string $actualArrivalTo = '',
        public readonly string $actualJoinFrom = '',
        public readonly string $actualJoinTo = '',
        public readonly string $actualDisembarkationFrom = '',
        public readonly string $actualDisembarkationTo = '',
        public readonly string $assignmentStartedFrom = '',
        public readonly string $assignmentStartedTo = '',
        public readonly string $assignmentClosedFrom = '',
        public readonly string $assignmentClosedTo = '',
        public readonly string $hotelId = '',
        public readonly string $accommodationStatus = '',
        public readonly string $stayType = '',
        public readonly string $tourStatus = '',
        public readonly string $hasApprovedCorrections = '',
        public readonly string $hasPendingCorrections = '',
        public readonly string $sort = 'started_at',
        public readonly string $direction = 'desc',
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            search: trim((string) $request->query('search', '')),
            status: (string) $request->query('status', ''),
            currentPhase: (string) $request->query('current_phase', ''),
            vesselId: (string) $request->query('vessel_id', ''),
            rankId: (string) $request->query('rank_id', ''),
            clientId: (string) $request->query('client_id', ''),
            source: (string) $request->query('source', ''),
            needsAttention: self::booleanFilter($request->query('needs_attention')),
            plannedArrivalFrom: (string) $request->query('planned_arrival_from', ''),
            plannedArrivalTo: (string) $request->query('planned_arrival_to', ''),
            plannedJoinFrom: (string) $request->query('planned_join_from', ''),
            plannedJoinTo: (string) $request->query('planned_join_to', ''),
            plannedSignoffFrom: (string) $request->query('planned_signoff_from', ''),
            plannedSignoffTo: (string) $request->query('planned_signoff_to', ''),
            actualArrivalFrom: (string) $request->query('actual_arrival_from', ''),
            actualArrivalTo: (string) $request->query('actual_arrival_to', ''),
            actualJoinFrom: (string) $request->query('actual_join_from', ''),
            actualJoinTo: (string) $request->query('actual_join_to', ''),
            actualDisembarkationFrom: (string) $request->query('actual_disembarkation_from', ''),
            actualDisembarkationTo: (string) $request->query('actual_disembarkation_to', ''),
            assignmentStartedFrom: (string) $request->query('assignment_started_from', ''),
            assignmentStartedTo: (string) $request->query('assignment_started_to', ''),
            assignmentClosedFrom: (string) $request->query('assignment_closed_from', ''),
            assignmentClosedTo: (string) $request->query('assignment_closed_to', ''),
            hotelId: (string) $request->query('hotel_id', ''),
            accommodationStatus: (string) $request->query('accommodation_status', ''),
            stayType: (string) $request->query('stay_type', ''),
            tourStatus: (string) $request->query('tour_status', ''),
            hasApprovedCorrections: self::booleanFilter($request->query('has_approved_corrections')),
            hasPendingCorrections: self::booleanFilter($request->query('has_pending_corrections')),
            sort: (string) $request->query('sort', 'started_at'),
            direction: strtolower((string) $request->query('direction', 'desc')) === 'asc' ? 'asc' : 'desc',
        );
    }

    /**
     * @return array<string, string>
     */
    public function toQueryArray(): array
    {
        return array_filter(
            $this->toArray(),
            fn (string $value, string $key): bool => $value !== ''
                && ! ($key === 'sort' && $value === 'started_at')
                && ! ($key === 'direction' && $value === 'desc'),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'search' => $this->search,
            'status' => $this->status,
            'current_phase' => $this->currentPhase,
            'vessel_id' => $this->vesselId,
            'rank_id' => $this->rankId,
            'client_id' => $this->clientId,
            'source' => $this->source,
            'needs_attention' => $this->needsAttention,
            'planned_arrival_from' => $this->plannedArrivalFrom,
            'planned_arrival_to' => $this->plannedArrivalTo,
            'planned_join_from' => $this->plannedJoinFrom,
            'planned_join_to' => $this->plannedJoinTo,
            'planned_signoff_from' => $this->plannedSignoffFrom,
            'planned_signoff_to' => $this->plannedSignoffTo,
            'actual_arrival_from' => $this->actualArrivalFrom,
            'actual_arrival_to' => $this->actualArrivalTo,
            'actual_join_from' => $this->actualJoinFrom,
            'actual_join_to' => $this->actualJoinTo,
            'actual_disembarkation_from' => $this->actualDisembarkationFrom,
            'actual_disembarkation_to' => $this->actualDisembarkationTo,
            'assignment_started_from' => $this->assignmentStartedFrom,
            'assignment_started_to' => $this->assignmentStartedTo,
            'assignment_closed_from' => $this->assignmentClosedFrom,
            'assignment_closed_to' => $this->assignmentClosedTo,
            'hotel_id' => $this->hotelId,
            'accommodation_status' => $this->accommodationStatus,
            'stay_type' => $this->stayType,
            'tour_status' => $this->tourStatus,
            'has_approved_corrections' => $this->hasApprovedCorrections,
            'has_pending_corrections' => $this->hasPendingCorrections,
            'sort' => $this->sort,
            'direction' => $this->direction,
        ];
    }

    private static function booleanFilter(mixed $value): string
    {
        return in_array((string) $value, ['1', 'true', 'yes'], true) ? '1' : '';
    }
}
