<?php

namespace App\Support\SeaServices;

use App\Models\Employee;
use App\Models\EmployeeSeaService;

final class SeaServiceEmployeeBrowseQuery
{
    /**
     * @return array{
     *     employee: array{id: int, name: string, employee_no: string},
     *     sea_services: list<array<string, mixed>>
     * }
     */
    public function forEmployee(int $companyId, Employee $employee): array
    {
        $seaServices = EmployeeSeaService::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->with(['vesselType:id,name', 'vessel:id,name', 'rank:id,name', 'client:id,name'])
            ->orderBy('sort_order')
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (EmployeeSeaService $seaService) => SeaServiceListResource::toProfileArray($seaService))
            ->values()
            ->all();

        return [
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'employee_no' => $employee->employee_no,
            ],
            'sea_services' => $seaServices,
        ];
    }
}
