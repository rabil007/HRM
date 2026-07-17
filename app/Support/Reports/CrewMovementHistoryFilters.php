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
        public readonly string $visaTypeId = '',
        public readonly string $source = '',
        public readonly string $needsAttention = '',
        public readonly string $plannedJoinFrom = '',
        public readonly string $plannedJoinTo = '',
        public readonly string $actualJoinFrom = '',
        public readonly string $actualJoinTo = '',
        public readonly string $actualDisembarkationFrom = '',
        public readonly string $actualDisembarkationTo = '',
        public readonly string $assignmentStartedFrom = '',
        public readonly string $assignmentStartedTo = '',
        public readonly string $assignmentClosedFrom = '',
        public readonly string $assignmentClosedTo = '',
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
            visaTypeId: (string) $request->query('visa_type_id', ''),
            source: (string) $request->query('source', ''),
            needsAttention: self::booleanFilter($request->query('needs_attention')),
            plannedJoinFrom: (string) $request->query('planned_join_from', ''),
            plannedJoinTo: (string) $request->query('planned_join_to', ''),
            actualJoinFrom: (string) $request->query('actual_join_from', ''),
            actualJoinTo: (string) $request->query('actual_join_to', ''),
            actualDisembarkationFrom: (string) $request->query('actual_disembarkation_from', ''),
            actualDisembarkationTo: (string) $request->query('actual_disembarkation_to', ''),
            assignmentStartedFrom: (string) $request->query('assignment_started_from', ''),
            assignmentStartedTo: (string) $request->query('assignment_started_to', ''),
            assignmentClosedFrom: (string) $request->query('assignment_closed_from', ''),
            assignmentClosedTo: (string) $request->query('assignment_closed_to', ''),
            sort: (string) $request->query('sort', 'started_at'),
            direction: strtolower((string) $request->query('direction', 'desc')) === 'asc' ? 'asc' : 'desc',
        );
    }

    /**
     * @return array<string, string>
     */
    public function toQueryArray(): array
    {
        return array_filter([
            'search' => $this->search,
            'status' => $this->status,
            'current_phase' => $this->currentPhase,
            'vessel_id' => $this->vesselId,
            'rank_id' => $this->rankId,
            'client_id' => $this->clientId,
            'visa_type_id' => $this->visaTypeId,
            'source' => $this->source,
            'needs_attention' => $this->needsAttention,
            'planned_join_from' => $this->plannedJoinFrom,
            'planned_join_to' => $this->plannedJoinTo,
            'actual_join_from' => $this->actualJoinFrom,
            'actual_join_to' => $this->actualJoinTo,
            'actual_disembarkation_from' => $this->actualDisembarkationFrom,
            'actual_disembarkation_to' => $this->actualDisembarkationTo,
            'assignment_started_from' => $this->assignmentStartedFrom,
            'assignment_started_to' => $this->assignmentStartedTo,
            'assignment_closed_from' => $this->assignmentClosedFrom,
            'assignment_closed_to' => $this->assignmentClosedTo,
            'sort' => $this->sort,
            'direction' => $this->direction,
        ], fn (string $value, string $key): bool => $value !== '' && ! ($key === 'sort' && $value === 'started_at') && ! ($key === 'direction' && $value === 'desc'), ARRAY_FILTER_USE_BOTH);
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
            'visa_type_id' => $this->visaTypeId,
            'source' => $this->source,
            'needs_attention' => $this->needsAttention,
            'planned_join_from' => $this->plannedJoinFrom,
            'planned_join_to' => $this->plannedJoinTo,
            'actual_join_from' => $this->actualJoinFrom,
            'actual_join_to' => $this->actualJoinTo,
            'actual_disembarkation_from' => $this->actualDisembarkationFrom,
            'actual_disembarkation_to' => $this->actualDisembarkationTo,
            'assignment_started_from' => $this->assignmentStartedFrom,
            'assignment_started_to' => $this->assignmentStartedTo,
            'assignment_closed_from' => $this->assignmentClosedFrom,
            'assignment_closed_to' => $this->assignmentClosedTo,
            'sort' => $this->sort,
            'direction' => $this->direction,
        ];
    }

    private static function booleanFilter(mixed $value): string
    {
        return in_array((string) $value, ['1', 'true', 'yes'], true) ? '1' : '';
    }
}
