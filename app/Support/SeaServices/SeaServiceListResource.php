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
        $companyId = (int) $seaService->company_id;
        $positionPayload = self::positionPayload($seaService, $companyId);

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
            'position_id' => $positionPayload['id'],
            'position_name' => $positionPayload['name'],
            'rank_id' => $positionPayload['id'],
            'rank_name' => $positionPayload['name'],
            'client_id' => $seaService->client_id,
            'client_name' => $seaService->client?->name,
            'start_date' => $seaService->start_date?->toDateString(),
            'end_date' => $seaService->end_date?->toDateString(),
            'total_months' => (int) $seaService->total_months,
            'total_days' => (int) $seaService->total_days,
            'crew_assignment_phase_id' => $seaService->crew_assignment_phase_id,
            'has_assignment_phase' => $seaService->crew_assignment_phase_id !== null,
            'sort_order' => (int) $seaService->sort_order,
            'total_sea_services' => (int) ($seaService->total_sea_services ?? 1),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function toProfileArray(EmployeeSeaService $seaService): array
    {
        $companyId = (int) $seaService->company_id;
        $positionPayload = self::positionPayload($seaService, $companyId);

        return [
            'id' => $seaService->id,
            'vessel_type_id' => $seaService->vessel_type_id,
            'vessel_type_name' => $seaService->vesselType?->name,
            'vessel_id' => $seaService->vessel_id,
            'vessel_name' => $seaService->vessel?->name,
            'position_id' => $positionPayload['id'],
            'position_name' => $positionPayload['name'],
            'rank_id' => $positionPayload['id'],
            'rank_name' => $positionPayload['name'],
            'client_id' => $seaService->client_id,
            'client_name' => $seaService->client?->name,
            'start_date' => $seaService->start_date?->toDateString(),
            'end_date' => $seaService->end_date?->toDateString(),
            'total_months' => (int) $seaService->total_months,
            'total_days' => (int) $seaService->total_days,
            'crew_assignment_phase_id' => $seaService->crew_assignment_phase_id,
            'has_assignment_phase' => $seaService->crew_assignment_phase_id !== null,
            'sort_order' => (int) $seaService->sort_order,
            'grt' => $seaService->vessel?->grt !== null ? (string) $seaService->vessel->grt : null,
            'bhp' => $seaService->vessel?->bhp,
            'created_at' => $seaService->created_at?->toDateTimeString(),
        ];
    }

    /**
     * @return array{id: int|null, name: string|null}
     */
    private static function positionPayload(EmployeeSeaService $seaService, int $companyId): array
    {
        $position = $seaService->relationLoaded('position') ? $seaService->position : null;

        if ($position !== null) {
            return [
                'id' => (int) $position->id,
                'name' => (string) $position->title,
            ];
        }

        if ($seaService->position_id !== null) {
            return [
                'id' => (int) $seaService->position_id,
                'name' => null,
            ];
        }

        return [
            'id' => null,
            'name' => null,
        ];
    }
}
