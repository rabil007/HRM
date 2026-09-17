<?php

namespace App\Support\Payroll\CrewTimeline;

use App\Models\CrewAssignmentPhase;
use App\Models\CrewMovementCorrection;
use App\Models\EmployeeContract;
use Illuminate\Support\Collection;

/**
 * Crew timeline source rows loaded under Apply locks. The authoritative Apply
 * hash must fingerprint this collection without re-querying the same tables.
 */
final class LockedCrewTimelineSource
{
    /**
     * @param  Collection<int, CrewAssignmentPhase>  $phases
     * @param  Collection<int, EmployeeContract|null>  $contractsByEmployeeId
     * @param  Collection<int, CrewMovementCorrection>  $pendingCorrections
     */
    public function __construct(
        public readonly Collection $phases,
        public readonly Collection $contractsByEmployeeId,
        public readonly Collection $pendingCorrections,
    ) {}
}
