<?php

namespace App\Support\Contracts;

use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Support\Employees\Resources\EmployeeContractResource;

final class ContractListResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(EmployeeContract $contract, ?Employee $employee = null): array
    {
        $employee ??= $contract->employee;

        return [
            ...EmployeeContractResource::toArray($contract),
            'employee_id' => $employee?->id ?? $contract->employee_id,
            'employee_name' => $employee?->name ?? '',
            'employee_no' => $employee?->employee_no ?? '',
            'employee_image' => $employee?->image,
            'profile_template_name' => $employee?->employeeProfileTemplate?->name,
            'total_contracts' => (int) $contract->total_contracts,
        ];
    }
}
