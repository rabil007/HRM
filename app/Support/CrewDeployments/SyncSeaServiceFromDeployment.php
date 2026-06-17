<?php

namespace App\Support\CrewDeployments;

use App\Models\EmployeeDeployment;
use App\Models\EmployeeSeaService;
use App\Models\Vessel;
use App\Support\Employees\SeaServiceDuration;

final class SyncSeaServiceFromDeployment
{
    public function sync(EmployeeDeployment $deployment): ?EmployeeSeaService
    {
        $deployment->loadMissing(['employee', 'vessel']);

        $linked = EmployeeSeaService::query()
            ->withTrashed()
            ->where('employee_deployment_id', $deployment->id)
            ->first();

        if (! $this->canSync($deployment)) {
            $linked?->forceDelete();

            return null;
        }

        $startDate = $deployment->joined_date->toDateString();
        $endDate = $deployment->disembarked_date->toDateString();
        $duration = SeaServiceDuration::fromDates($startDate, $endDate);

        $vessel = $deployment->vessel ?? Vessel::query()->find($deployment->vessel_id);
        $rankId = $deployment->rank_id ?? $deployment->employee?->rank_id;

        $attributes = [
            'company_id' => $deployment->company_id,
            'employee_id' => $deployment->employee_id,
            'employee_deployment_id' => $deployment->id,
            'vessel_id' => $deployment->vessel_id,
            'vessel_type_id' => $vessel?->vessel_type_id,
            'rank_id' => $rankId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'total_months' => $duration['months'],
            'total_days' => $duration['days'],
            'client_id' => $deployment->client_id,
            'is_offshore' => $linked?->is_offshore ?? false,
        ];

        if ($linked !== null) {
            if ($linked->trashed()) {
                $linked->restore();
            }

            $linked->update($attributes);

            return $linked->fresh();
        }

        $maxSort = EmployeeSeaService::query()
            ->where('employee_id', $deployment->employee_id)
            ->where('company_id', $deployment->company_id)
            ->max('sort_order');

        return EmployeeSeaService::query()->create([
            ...$attributes,
            'sort_order' => $maxSort === null ? 0 : ((int) $maxSort + 1),
        ]);
    }

    public function removeLinked(EmployeeDeployment $deployment): void
    {
        EmployeeSeaService::query()
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
