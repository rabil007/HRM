<?php

namespace App\Support\CrewPlanning;

use App\Models\CrewPlanningAssignment;
use App\Models\EmployeeDeployment;

final class SyncPlanningAssignmentFromDeployment
{
    public function sync(EmployeeDeployment $deployment): ?CrewPlanningAssignment
    {
        $deployment->loadMissing(['employee']);

        $linked = CrewPlanningAssignment::query()
            ->withTrashed()
            ->where('employee_deployment_id', $deployment->id)
            ->first();

        if (! $this->canSync($deployment)) {
            $linked?->forceDelete();

            return null;
        }

        $rankId = $deployment->rank_id ?? $deployment->employee?->rank_id;

        $attributes = [
            'company_id' => $deployment->company_id,
            'employee_id' => $deployment->employee_id,
            'employee_deployment_id' => $deployment->id,
            'vessel_id' => $deployment->vessel_id,
            'rank_id' => $rankId,
            'planned_join_date' => $deployment->joined_date->toDateString(),
            'planned_leave_date' => $deployment->disembarked_date->toDateString(),
            'notes' => null,
        ];

        if ($linked !== null) {
            if ($linked->trashed()) {
                $linked->restore();
            }

            $linked->update($attributes);

            return $linked->fresh();
        }

        return CrewPlanningAssignment::query()->create($attributes);
    }

    public function removeLinked(EmployeeDeployment $deployment): void
    {
        CrewPlanningAssignment::query()
            ->withTrashed()
            ->where('employee_deployment_id', $deployment->id)
            ->forceDelete();
    }

    private function canSync(EmployeeDeployment $deployment): bool
    {
        if ($deployment->joined_date === null || $deployment->disembarked_date === null) {
            return false;
        }

        if ($deployment->vessel_id === null) {
            return false;
        }

        $rankId = $deployment->rank_id ?? $deployment->employee?->rank_id;

        return $rankId !== null;
    }
}
