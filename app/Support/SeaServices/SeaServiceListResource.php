<?php

namespace App\Support\SeaServices;

use App\Models\Employee;
use App\Models\EmployeeSeaService;

final class SeaServiceListResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(EmployeeSeaService $seaService, ?Employee $employee = null): array
    {
        $employee ??= $seaService->employee;

        return [
            'id' => $seaService->id,
            'employee_id' => $employee?->id ?? $seaService->employee_id,
            'employee_name' => $employee?->name ?? '',
            'employee_no' => $employee?->employee_no ?? '',
            'employee_image' => $employee?->image,
            'department_name' => $employee?->department?->name,
            'position_title' => $employee?->position?->title,
            'vessel_type_id' => $seaService->vessel_type_id,
            'vessel_type_name' => $seaService->vesselType?->name,
            'vessel_id' => $seaService->vessel_id,
            'vessel_name' => $seaService->vessel?->name,
            'rank_id' => $seaService->rank_id,
            'rank_name' => $seaService->rank?->name,
            'client_id' => $seaService->client_id,
            'client_name' => $seaService->client?->name,
            'start_date' => $seaService->start_date?->toDateString(),
            'end_date' => $seaService->end_date?->toDateString(),
            'total_months' => (int) $seaService->total_months,
            'total_days' => (int) $seaService->total_days,
            'is_offshore' => (bool) $seaService->is_offshore,
            'employee_deployment_id' => $seaService->employee_deployment_id,
            'has_deployment' => $seaService->employee_deployment_id !== null,
            'sort_order' => (int) $seaService->sort_order,
            'total_sea_services' => (int) ($seaService->total_sea_services ?? 1),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function toProfileArray(EmployeeSeaService $seaService): array
    {
        return [
            'id' => $seaService->id,
            'vessel_type_id' => $seaService->vessel_type_id,
            'vessel_type_name' => $seaService->vesselType?->name,
            'vessel_id' => $seaService->vessel_id,
            'vessel_name' => $seaService->vessel?->name,
            'rank_id' => $seaService->rank_id,
            'rank_name' => $seaService->rank?->name,
            'client_id' => $seaService->client_id,
            'client_name' => $seaService->client?->name,
            'start_date' => $seaService->start_date?->toDateString(),
            'end_date' => $seaService->end_date?->toDateString(),
            'total_months' => (int) $seaService->total_months,
            'total_days' => (int) $seaService->total_days,
            'is_offshore' => (bool) $seaService->is_offshore,
            'employee_deployment_id' => $seaService->employee_deployment_id,
            'has_deployment' => $seaService->employee_deployment_id !== null,
            'sort_order' => (int) $seaService->sort_order,
        ];
    }
}
