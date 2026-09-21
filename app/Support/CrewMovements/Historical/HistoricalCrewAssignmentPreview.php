<?php

namespace App\Support\CrewMovements\Historical;

final class HistoricalCrewAssignmentPreview
{
    /**
     * @param  array{id: int, name: string, employee_no: ?string}  $employee
     * @param  array{id: int, name: string}  $vessel
     * @param  array{id: int, name: string}  $rank
     * @param  array{id: int, name: string}|null  $client
     * @param  array<string, mixed>  $summary
     * @param  list<array<string, mixed>>  $timeline
     * @param  array<string, mixed>  $seaService
     * @param  list<array{code: string, passed: bool, message: string}>  $checks
     * @param  list<string>  $warnings
     * @param  list<string>  $errors
     * @param  array<string, mixed>|null  $inferredState
     * @param  array<string, mixed>|null  $lastMovement
     */
    public function __construct(
        public readonly bool $valid,
        public readonly array $employee,
        public readonly array $vessel,
        public readonly array $rank,
        public readonly ?array $client,
        public readonly array $summary,
        public readonly array $timeline,
        public readonly array $seaService,
        public readonly array $checks,
        public readonly array $warnings = [],
        public readonly array $errors = [],
        public readonly ?array $inferredState = null,
        public readonly ?array $lastMovement = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'employee' => $this->employee,
            'vessel' => $this->vessel,
            'rank' => $this->rank,
            'client' => $this->client,
            'summary' => $this->summary,
            'timeline' => $this->timeline,
            'sea_service' => $this->seaService,
            'checks' => $this->checks,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
            'inferred_state' => $this->inferredState,
            'last_movement' => $this->lastMovement,
        ];
    }
}
