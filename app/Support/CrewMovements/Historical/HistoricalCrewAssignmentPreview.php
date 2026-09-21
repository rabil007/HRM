<?php

namespace App\Support\CrewMovements\Historical;

final class HistoricalCrewAssignmentPreview
{
    /**
     * @param  array{id: int, name: string, employee_no: ?string}  $employee
     * @param  array{id: int, name: string}  $vessel
     * @param  array{id: int, name: string}  $rank
     * @param  array{id: int, name: string}|null  $client
     * @param  list<array{phase_code: string, label: string, timestamp: string, formatted: string}>  $timeline
     * @param  array{status: string, days: int, months: int, message: string, existing_id: ?int}  $seaService
     * @param  list<array{key: string, label: string, passed: bool, message: ?string}>  $checks
     * @param  list<string>  $warnings
     * @param  list<string>  $errors
     */
    public function __construct(
        public readonly bool $valid,
        public readonly array $employee,
        public readonly array $vessel,
        public readonly array $rank,
        public readonly ?array $client,
        public readonly array $timeline,
        public readonly int $durationDays,
        public readonly array $seaService,
        public readonly array $checks,
        public readonly array $warnings = [],
        public readonly array $errors = [],
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
            'timeline' => $this->timeline,
            'duration_days' => $this->durationDays,
            'sea_service' => $this->seaService,
            'checks' => $this->checks,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
        ];
    }
}
