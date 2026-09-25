<?php

namespace App\Support\CrewMovements;

use App\Models\User;
use Carbon\CarbonInterface;

final class CrewAssignmentConflictContext
{
    /**
     * @param  'draft'|'plan'|'start'|'transfer'|'redeploy'  $action
     * @param  CarbonInterface|null  $plannedJoinAt  User-entered Expected Vessel Join forecast only.
     * @param  CarbonInterface|null  $operationalStartAt  Actual Start Assignment / P0 start timestamp for action=start.
     *                                                    Never persisted as planned_join_at.
     */
    public function __construct(
        public readonly int $companyId,
        public readonly int $employeeId,
        public readonly string $action,
        public readonly ?CarbonInterface $plannedJoinAt = null,
        public readonly ?CarbonInterface $plannedSignoffAt = null,
        public readonly ?CarbonInterface $plannedArrivalAt = null,
        public readonly ?CarbonInterface $operationalStartAt = null,
        public readonly ?int $vesselId = null,
        public readonly ?int $rankId = null,
        public readonly ?int $clientId = null,
        public readonly ?int $relievesCrewAssignmentId = null,
        public readonly ?int $currentAssignmentId = null,
        public readonly ?User $actor = null,
    ) {}
}
