<?php

namespace App\Support\Employees\Resources;

use App\Models\ContractSalaryRevision;
use App\Models\ContractSalaryRevisionLine;
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
            'payroll_category' => $contract->payroll_category?->value,
            'salary_structure' => $contract->resolvedSalaryStructure()->value,
            'start_date' => $contract->start_date?->toDateString(),
            'end_date' => $contract->end_date?->toDateString(),
            'labor_contract_id' => $contract->labor_contract_id,
            'status' => $contract->status,
            'basic_salary' => $contract->basic_salary,
            'housing_allowance' => $contract->housing_allowance,
            'transport_allowance' => $contract->transport_allowance,
            'other_allowances' => $contract->other_allowances,
            'supplementary_allowance' => $contract->supplementary_allowance,
            'site_allowance' => $contract->site_allowance,
            'note' => $contract->note,
            'created_at' => $contract->created_at?->toDateTimeString(),
            'salary_revisions' => $contract->relationLoaded('salaryRevisions')
                ? $contract->salaryRevisions
                    ->map(fn (ContractSalaryRevision $revision) => self::revisionToArray($revision))
                    ->values()
                    ->all()
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function revisionToArray(ContractSalaryRevision $revision): array
    {
        return [
            'id' => $revision->id,
            'version' => $revision->version,
            'effective_from' => $revision->effective_from?->toDateString(),
            'reason' => $revision->reason,
            'created_at' => $revision->created_at?->toDateTimeString(),
            'lines' => $revision->lines
                ->map(fn (ContractSalaryRevisionLine $line) => [
                    'component_code' => $line->component_code?->value,
                    'component_name' => $line->component_name,
                    'rate_type' => $line->rate_type?->value,
                    'amount' => $line->amount,
                ])
                ->values()
                ->all(),
        ];
    }
}
