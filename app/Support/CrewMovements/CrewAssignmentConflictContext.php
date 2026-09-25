<?php

namespace App\Support\CrewMovements;

use App\Models\User;
use Carbon\CarbonInterface;

final class CrewAssignmentConflictContext
{
    public function __construct(
        public readonly int $companyId,
        public readonly int $employeeId,
        public readonly string $action, // 'draft' | 'plan' | 'start' | 'transfer' | 'redeploy'
        public readonly ?CarbonInterface $plannedJoinAt = null,
        public readonly ?CarbonInterface $plannedSignoffAt = null,
        public readonly ?CarbonInterface $plannedArrivalAt = null,
        public readonly ?int $vesselId = null,
        public readonly ?int $rankId = null,
        public readonly ?int $clientId = null,
        public readonly ?int $relievesCrewAssignmentId = null,
        public readonly ?int $currentAssignmentId = null,
        public readonly ?User $actor = null,
    ) {}
}
