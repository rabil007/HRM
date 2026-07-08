<?php

namespace App\Support\Employees;

use App\Models\Employee;
use App\Support\Departments\ResolveDepartmentEffectiveManager;
use Throwable;

final class EmployeeExportFieldResolver
{
    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public function resolve(Employee $employee, array $keys): array
    {
        $contract = $employee->currentContract;
        $bankAccount = $employee->primaryBankAccount;

        $resolved = [];

        foreach ($keys as $key) {
            $resolved[$key] = $this->value($key, $employee, $contract, $bankAccount);
        }

        return $resolved;
    }

    private function value(
        string $key,
        Employee $employee,
        mixed $contract,
        mixed $bankAccount,
    ): mixed {
        return match ($key) {
            'id' => $employee->id,
            'employee_no' => $employee->employee_no,
            'name' => $employee->name,
            'branch' => $employee->branch?->name,
            'department' => $employee->department?->name,
            'position' => $employee->position?->title,
            'rank' => $employee->rank?->name,
            'project' => $employee->project?->name,
            'client' => $employee->client?->name,
            'manager' => ResolveDepartmentEffectiveManager::managerForEmployee($employee)?->name,
            'work_email' => $employee->work_email,
            'personal_email' => $employee->personal_email,
            'phone' => $employee->phone,
            'phone_home_country' => $employee->phone_home_country,
            'date_of_birth' => $employee->date_of_birth?->toDateString(),
            'hire_date' => $employee->hire_date?->toDateString(),
            'place_of_birth' => $employee->place_of_birth,
            'marital_status' => $employee->marital_status,
            'spouse_name' => $employee->spouse_name,
            'address' => $employee->address,
            'nearest_airport' => $employee->nearest_airport,
            'emergency_contact' => $employee->emergency_contact,
            'emergency_phone' => $employee->emergency_phone,
            'passport_number' => $employee->passport_number,
            'emirates_id' => $employee->emirates_id,
            'gender' => $employee->genderRef?->name,
            'religion' => $employee->religionRef?->name,
            'nationality' => $employee->nationalityRef?->name,
            'visa_type' => $employee->visaTypeRef?->name,
            'company_visa_type' => $employee->companyVisaTypeRef?->name,
            'salary_payment_method' => $this->safe(fn () => $employee->salary_payment_method?->label()),
            'status' => $employee->status,
            'created_at' => $employee->created_at?->toDateTimeString(),
            'contract_payroll_category' => $this->safe(fn () => $contract?->payroll_category?->label()),
            'contract_salary_structure' => $this->safe(fn () => $contract?->resolvedSalaryStructure()->label()),
            'contract_start_date' => $contract?->start_date?->toDateString(),
            'contract_end_date' => $contract?->end_date?->toDateString(),
            'contract_labor_contract_id' => $contract?->labor_contract_id,
            'contract_basic_salary' => $contract?->basic_salary,
            'contract_housing_allowance' => $contract?->housing_allowance,
            'contract_transport_allowance' => $contract?->transport_allowance,
            'contract_other_allowances' => $contract?->other_allowances,
            'contract_supplementary_allowance' => $contract?->supplementary_allowance,
            'contract_site_allowance' => $contract?->site_allowance,
            'contract_note' => $contract?->note,
            'contract_status' => $contract?->status,
            'contract_total_compensation_aed' => $contract !== null
                ? round(
                    (float) ($contract->basic_salary ?? 0)
                    + (float) ($contract->housing_allowance ?? 0)
                    + (float) ($contract->transport_allowance ?? 0)
                    + (float) ($contract->other_allowances ?? 0)
                    + (float) ($contract->supplementary_allowance ?? 0)
                    + (float) ($contract->site_allowance ?? 0),
                    2,
                )
                : null,
            'contract_total_compensation_usd' => $contract !== null
                ? round(
                    (
                        (float) ($contract->basic_salary ?? 0)
                        + (float) ($contract->housing_allowance ?? 0)
                        + (float) ($contract->transport_allowance ?? 0)
                        + (float) ($contract->other_allowances ?? 0)
                        + (float) ($contract->supplementary_allowance ?? 0)
                        + (float) ($contract->site_allowance ?? 0)
                    ) / EmployeeExportFieldRegistry::AED_PER_USD,
                    2,
                )
                : null,
            'bank_name' => $bankAccount?->bank?->name,
            'bank_iban' => $bankAccount?->iban,
            'bank_account_name' => $bankAccount?->account_name,
            'bank_is_primary' => $bankAccount?->is_primary,
            default => null,
        };
    }

    /**
     * Safely call a closure that may throw due to enum cast failures or
     * unexpected database values, returning null on any error.
     */
    private function safe(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (Throwable) {
            return null;
        }
    }
}
