<?php

namespace App\Support\CrewMovements\Historical;

use App\Models\CrewAssignment;
use Illuminate\Validation\ValidationException;

final class HistoricalCrewAssignmentValidationResult
{
    /**
     * @param  array<string, string>  $errors
     * @param  list<string>  $warnings
     * @param  list<array{code: string, passed: bool, message: string}>  $checks
     * @param  array{id: int, name: string, employee_no: ?string}  $employee
     * @param  array{id: int, name: string}  $vessel
     * @param  array{id: int, name: string}  $rank
     * @param  array{id: int, name: string}|null  $client
     * @param  array<string, mixed>  $summary
     * @param  list<array<string, mixed>>  $timeline
     * @param  array<string, mixed>  $seaService
     * @param  array<string, mixed>|null  $inferredState
     * @param  array<string, mixed>|null  $lastMovement
     */
    public function __construct(
        public readonly bool $valid,
        public readonly array $errors,
        public readonly array $warnings,
        public readonly array $checks,
        public readonly array $employee,
        public readonly array $vessel,
        public readonly array $rank,
        public readonly ?array $client,
        public readonly array $summary,
        public readonly array $timeline,
        public readonly array $seaService,
        public readonly ?CrewAssignment $conflictingAssignment = null,
        public readonly ?array $inferredState = null,
        public readonly ?array $lastMovement = null,
    ) {}

    public function assertValid(): void
    {
        if (! $this->valid) {
            throw ValidationException::withMessages($this->errors);
        }
    }

    public function toPreview(): HistoricalCrewAssignmentPreview
    {
        return new HistoricalCrewAssignmentPreview(
            valid: $this->valid,
            employee: $this->employee,
            vessel: $this->vessel,
            rank: $this->rank,
            client: $this->client,
            summary: $this->summary,
            timeline: $this->timeline,
            seaService: $this->seaService,
            checks: $this->checks,
            warnings: $this->warnings,
            errors: array_values($this->errors),
            inferredState: $this->inferredState,
            lastMovement: $this->lastMovement,
        );
    }
}
