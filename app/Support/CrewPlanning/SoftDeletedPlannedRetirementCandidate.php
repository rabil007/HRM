<?php

namespace App\Support\CrewPlanning;

/**
 * Inventory row for one soft-deleted CrewAssignment(status=planned) tombstone.
 */
final class SoftDeletedPlannedRetirementCandidate
{
    public const STATUS_CONVERTIBLE = 'convertible';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_RETIRED = 'retired';

    public const STATUS_FAILED = 'failed';

    public const DISPOSITION_RETIRE_TO_CANCELLED = 'retire_status_to_cancelled';

    public const DISPOSITION_NONE = 'none';

    /**
     * @param  list<string>  $blockers
     */
    public function __construct(
        public readonly int $assignmentId,
        public readonly string $assignmentNo,
        public readonly int $companyId,
        public readonly ?int $employeeId,
        public readonly ?string $employeeName,
        public readonly ?int $vesselId,
        public readonly ?string $vesselName,
        public readonly ?int $positionId,
        public readonly ?string $positionName,
        public readonly ?string $deletedAt,
        public readonly ?string $closedAt,
        public string $migrationStatus,
        public string $disposition,
        public array $blockers,
        public ?string $failureReason = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'assignment_id' => $this->assignmentId,
            'assignment_no' => $this->assignmentNo,
            'company_id' => $this->companyId,
            'employee_id' => $this->employeeId,
            'employee' => $this->employeeName,
            'vessel_id' => $this->vesselId,
            'vessel' => $this->vesselName,
            'position_id' => $this->positionId,
            'position' => $this->positionName,
            'deleted_at' => $this->deletedAt,
            'closed_at' => $this->closedAt,
            'migration_status' => $this->migrationStatus,
            'disposition' => $this->disposition,
            'blockers' => $this->blockers,
            'failure_reason' => $this->failureReason,
        ];
    }
}
