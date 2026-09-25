<?php

namespace App\Support\CrewMovements;

final class CrewAssignmentConflictResult
{
    /**
     * @param  list<string>  $allowedActions
     * @param  array<string, mixed>|null  $existingAssignment
     * @param  array<string, mixed>|null  $newAssignment
     * @param  array{start: string|null, end: string|null}|null  $affectedDates
     */
    public function __construct(
        public readonly bool $hasConflict,
        public readonly string $severity, // 'none' | 'warning' | 'blocking'
        public readonly ?string $code,
        public readonly ?string $message,
        public readonly ?array $existingAssignment = null,
        public readonly ?array $newAssignment = null,
        public readonly ?array $affectedDates = null,
        public readonly array $allowedActions = [],
        public readonly bool $blocking = false,
    ) {}

    public static function none(): self
    {
        return new self(
            hasConflict: false,
            severity: 'none',
            code: null,
            message: null,
            existingAssignment: null,
            newAssignment: null,
            affectedDates: null,
            allowedActions: [],
            blocking: false,
        );
    }

    /**
     * @param  list<string>  $allowedActions
     * @param  array<string, mixed>|null  $existingAssignment
     * @param  array<string, mixed>|null  $newAssignment
     * @param  array{start: string|null, end: string|null}|null  $affectedDates
     */
    public static function blocking(
        string $code,
        string $message,
        ?array $existingAssignment = null,
        ?array $newAssignment = null,
        ?array $affectedDates = null,
        array $allowedActions = ['cancel'],
    ): self {
        return new self(
            hasConflict: true,
            severity: 'blocking',
            code: $code,
            message: $message,
            existingAssignment: $existingAssignment,
            newAssignment: $newAssignment,
            affectedDates: $affectedDates,
            allowedActions: $allowedActions,
            blocking: true,
        );
    }

    /**
     * @param  list<string>  $allowedActions
     * @param  array<string, mixed>|null  $existingAssignment
     * @param  array<string, mixed>|null  $newAssignment
     * @param  array{start: string|null, end: string|null}|null  $affectedDates
     */
    public static function warning(
        string $code,
        string $message,
        ?array $existingAssignment = null,
        ?array $newAssignment = null,
        ?array $affectedDates = null,
        array $allowedActions = [],
    ): self {
        return new self(
            hasConflict: true,
            severity: 'warning',
            code: $code,
            message: $message,
            existingAssignment: $existingAssignment,
            newAssignment: $newAssignment,
            affectedDates: $affectedDates,
            allowedActions: $allowedActions,
            blocking: false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $existing = $this->existingAssignment;
        if ($existing !== null) {
            $existing = [
                'id' => $existing['id'] ?? null,
                'assignment_no' => $existing['assignment_no'] ?? null,
                'status' => $existing['status'] ?? null,
                'status_label' => $existing['status_label'] ?? ucfirst($existing['status'] ?? ''),
                'vessel_id' => $existing['vessel_id'] ?? null,
                'vessel_name' => $existing['vessel_name'] ?? null,
                'rank_id' => $existing['rank_id'] ?? null,
                'rank_name' => $existing['rank_name'] ?? null,
                'planned_join_at' => $existing['start_date'] ?? $existing['planned_join_at'] ?? null,
                'planned_signoff_at' => $existing['end_date'] ?? $existing['planned_signoff_at'] ?? null,
                'current_phase_code' => $existing['current_phase_code'] ?? null,
                'current_phase_label' => $existing['current_phase_name'] ?? $existing['current_phase_label'] ?? null,
            ];
        }

        $affectedDates = null;
        if ($this->affectedDates !== null) {
            $affectedDates = [
                'overlap_start' => $this->affectedDates['start'] ?? $this->affectedDates['overlap_start'] ?? null,
                'overlap_end' => $this->affectedDates['end'] ?? $this->affectedDates['overlap_end'] ?? null,
                'existing_start' => $existing['planned_join_at'] ?? null,
                'existing_end' => $existing['planned_signoff_at'] ?? null,
                'new_start' => $this->newAssignment['start_date'] ?? $this->newAssignment['planned_join_at'] ?? null,
                'new_end' => $this->newAssignment['end_date'] ?? $this->newAssignment['planned_signoff_at'] ?? null,
            ];
        }

        return [
            'has_conflict' => $this->hasConflict,
            'severity' => $this->severity === 'blocking' ? 'error' : $this->severity,
            'code' => $this->code,
            'message' => $this->message,
            'existing_assignment' => $existing,
            'new_assignment' => $this->newAssignment,
            'affected_dates' => $affectedDates,
            'allowed_actions' => $this->allowedActions,
            'blocking' => $this->blocking,
        ];
    }
}
