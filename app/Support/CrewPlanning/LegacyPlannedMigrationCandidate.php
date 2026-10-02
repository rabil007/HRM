<?php

namespace App\Support\CrewPlanning;

/**
 * Inventory row for one legacy CrewAssignment(status=planned) during Phase 4 migration.
 */
final class LegacyPlannedMigrationCandidate
{
    public const STATUS_CONVERTIBLE = 'convertible';

    public const STATUS_ALREADY_REPRESENTED = 'already_represented';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_MIGRATED = 'migrated';

    public const STATUS_FAILED = 'failed';

    public const DISPOSITION_CREATE = 'create_named_planning';

    public const DISPOSITION_REUSE_LINKED_VACANT = 'reuse_linked_vacant_planning';

    public const DISPOSITION_REUSE_LINKED_NAMED = 'reuse_linked_named_planning';

    public const DISPOSITION_REUSE_EQUIVALENT = 'reuse_equivalent_named_planning';

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
        public readonly ?string $plannedArrivalDate,
        public readonly ?string $plannedJoinDate,
        public readonly ?string $plannedLeaveDate,
        public readonly ?int $relievesCrewAssignmentId,
        public readonly ?string $remarks,
        public readonly ?int $linkedPlanningAssignmentId,
        public string $migrationStatus,
        public string $disposition,
        public ?int $planningAssignmentId,
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
            'planned_arrival_date' => $this->plannedArrivalDate,
            'planned_join_date' => $this->plannedJoinDate,
            'planned_leave_date' => $this->plannedLeaveDate,
            'relieves_crew_assignment_id' => $this->relievesCrewAssignmentId,
            'remarks' => $this->remarks,
            'linked_planning_assignment_id' => $this->linkedPlanningAssignmentId,
            'planning_assignment_id' => $this->planningAssignmentId,
            'migration_status' => $this->migrationStatus,
            'disposition' => $this->disposition,
            'blockers' => $this->blockers,
            'failure_reason' => $this->failureReason,
        ];
    }
}
