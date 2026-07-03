<?php

namespace App\Support\Contracts;

use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Support\Employees\Resources\EmployeeContractResource;

final class ContractEmployeeBrowseQuery
{
    /**
     * @return array{
     *     employee: array{id: int, name: string, employee_no: string},
     *     contracts: list<array<string, mixed>>
     * }
     */
    public function forEmployee(int $companyId, Employee $employee): array
    {
        $contracts = EmployeeContract::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (EmployeeContract $contract) => EmployeeContractResource::toArray($contract))
            ->values()
            ->all();

        return [
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'employee_no' => $employee->employee_no,
            ],
            'contracts' => $contracts,
        ];
    }
}
