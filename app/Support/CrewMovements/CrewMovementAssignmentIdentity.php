<?php

namespace App\Support\CrewMovements;

/**
 * Immutable tenant-scoped crew assignment identity resolved before a movement
 * transaction opens. Only these fields may be trusted from the pre-lock read.
 */
final readonly class CrewMovementAssignmentIdentity
{
    public function __construct(
        public int $companyId,
        public int $assignmentId,
        public int $employeeId,
    ) {}
}
