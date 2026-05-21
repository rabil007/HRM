<?php

namespace App\Support\Employees\Resources;

use App\Models\EmployeeContract;

final class EmployeeContractResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(EmployeeContract $contract): array
    {
        return [
            'id' => $contract->id,
            'contract_type' => $contract->contract_type,
            'start_date' => $contract->start_date?->toDateString(),
            'end_date' => $contract->end_date?->toDateString(),
            'labor_contract_id' => $contract->labor_contract_id,
            'status' => $contract->status,
            'basic_salary' => $contract->basic_salary,
            'housing_allowance' => $contract->housing_allowance,
            'transport_allowance' => $contract->transport_allowance,
            'other_allowances' => $contract->other_allowances,
            'note' => $contract->note,
            'created_at' => $contract->created_at?->toDateTimeString(),
        ];
    }
}
