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
        return [
            'has_conflict' => $this->hasConflict,
            'severity' => $this->severity,
            'code' => $this->code,
            'message' => $this->message,
            'existing_assignment' => $this->existingAssignment,
            'new_assignment' => $this->newAssignment,
            'affected_dates' => $this->affectedDates,
            'allowed_actions' => $this->allowedActions,
            'blocking' => $this->blocking,
        ];
    }
}
