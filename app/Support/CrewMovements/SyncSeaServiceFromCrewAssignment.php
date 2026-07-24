<?php

namespace App\Support\CrewMovements;

use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\EmployeeSeaService;
use App\Models\Vessel;
use App\Support\Employees\SeaServiceDuration;

final class SyncSeaServiceFromCrewAssignment
{
    public function syncFromPhase(CrewAssignmentPhase $phase): ?EmployeeSeaService
    {
        $phase->loadMissing(['assignment.employee', 'assignment.vessel']);

        $linked = EmployeeSeaService::query()
            ->withTrashed()
            ->where('crew_assignment_phase_id', $phase->id)
            ->first();

        if (! $this->canSync($phase)) {
            $linked?->forceDelete();

            return null;
        }

        /** @var CrewAssignment $assignment */
        $assignment = $phase->assignment;
        $startDate = $phase->actual_start_at->toDateString();
        $endDate = $phase->actual_end_at->toDateString();
        $duration = SeaServiceDuration::fromDates($startDate, $endDate);
        $vessel = $assignment->vessel ?? Vessel::query()->find($assignment->vessel_id);
        $rankId = $assignment->rank_id ?? $assignment->employee?->rank_id;

        $attributes = [
            'company_id' => $assignment->company_id,
            'employee_id' => $assignment->employee_id,
            'crew_assignment_phase_id' => $phase->id,
            'vessel_id' => $assignment->vessel_id,
            'vessel_type_id' => $vessel?->vessel_type_id,
            'rank_id' => $rankId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'total_months' => $duration['months'],
            'total_days' => $duration['days'],
            'client_id' => $assignment->client_id,
        ];

        if ($linked !== null) {
            if ($linked->trashed()) {
                $linked->restore();
            }

            $linked->update($attributes);

            return $linked->fresh();
        }

        $maxSort = EmployeeSeaService::query()
            ->where('employee_id', $assignment->employee_id)
            ->where('company_id', $assignment->company_id)
            ->max('sort_order');

        return EmployeeSeaService::query()->create([
            ...$attributes,
            'sort_order' => $maxSort === null ? 0 : ((int) $maxSort + 1),
        ]);
    }

    public function syncCompletedOnVesselPhases(CrewAssignment $assignment): void
    {
        $assignment->phases()
            ->where('phase_code', CrewPhaseCode::OnVessel)
            ->where('status', CrewPhaseStatus::Completed)
            ->get()
            ->each(fn (CrewAssignmentPhase $phase) => $this->syncFromPhase($phase));
    }

    public function removeLinked(CrewAssignmentPhase $phase): void
    {
        EmployeeSeaService::query()
            ->withTrashed()
            ->where('crew_assignment_phase_id', $phase->id)
            ->forceDelete();
    }

    private function canSync(CrewAssignmentPhase $phase): bool
    {
        if ($phase->phase_code !== CrewPhaseCode::OnVessel) {
            return false;
        }

        if ($phase->status === CrewPhaseStatus::Cancelled || $phase->status === CrewPhaseStatus::Corrected) {
            return false;
        }

        if ($phase->actual_start_at === null || $phase->actual_end_at === null) {
            return false;
        }

        $assignment = $phase->assignment;
        if ($assignment === null || $assignment->vessel_id === null) {
            return false;
        }

        $rankId = $assignment->rank_id ?? $assignment->employee?->rank_id;

        return $rankId !== null;
    }
}
