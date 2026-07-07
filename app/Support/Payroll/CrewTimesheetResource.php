<?php

namespace App\Support\Payroll;

use App\Enums\ContractSalaryStructure;
use App\Enums\SalaryPaymentMethod;
use App\Models\CrewTimesheet;
use App\Models\Employee;
use App\Models\EmployeeContract;

final class CrewTimesheetResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(?CrewTimesheet $timesheet): ?array
    {
        if ($timesheet === null) {
            return null;
        }

        return [
            'id' => $timesheet->id,
            'period_id' => $timesheet->period_id,
            'employee_id' => $timesheet->employee_id,
            'standby_from' => $timesheet->standby_from?->toDateString(),
            'standby_to' => $timesheet->standby_to?->toDateString(),
            'standby_days' => $timesheet->standby_days,
            'onsite_from' => $timesheet->onsite_from?->toDateString(),
            'onsite_to' => $timesheet->onsite_to?->toDateString(),
            'onsite_days' => $timesheet->onsite_days,
            'overtime_hours' => $timesheet->overtime_hours,
            'overtime_amount' => $timesheet->overtime_amount,
            'additional_amount' => $timesheet->additional_amount,
            'deduction_amount' => $timesheet->deduction_amount,
            'remarks' => $timesheet->remarks,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function toBoardRow(Employee $employee, ?CrewTimesheet $timesheet, int $periodId): array
    {
        $paymentMethod = $employee->salary_payment_method ?? SalaryPaymentMethod::BankTransfer;
        $contract = $employee->currentContract;
        $salaryStructure = $contract?->resolvedSalaryStructure() ?? ContractSalaryStructure::Daily;

        return [
            'employee' => PayrollEmployeeIdentityResource::forEmployee($employee),
            'period_id' => $periodId,
            'timesheet' => self::toArray($timesheet),
            'is_filled' => $timesheet !== null,
            'primary_account' => EmployeePrimaryAccountResource::forEmployee($employee),
            'salary_payment_method' => $paymentMethod->value,
            'salary_payment_method_label' => $paymentMethod->label(),
            'salary_structure' => $salaryStructure->value,
            'contract' => $contract !== null ? self::contractRatesForBoard($contract, $salaryStructure) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function contractRatesForBoard(
        EmployeeContract $contract,
        ContractSalaryStructure $salaryStructure,
    ): array {
        if ($salaryStructure === ContractSalaryStructure::Monthly) {
            return [
                'basic_salary' => $contract->basic_salary,
                'housing_allowance' => $contract->housing_allowance,
                'transport_allowance' => $contract->transport_allowance,
                'other_allowances' => $contract->other_allowances,
            ];
        }

        return [
            'basic_salary' => $contract->basic_salary,
            'supplementary_allowance' => $contract->supplementary_allowance,
            'site_allowance' => $contract->site_allowance,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function toEmployeeRow(Employee $employee, int $periodId): array
    {
        return [
            'employee' => PayrollEmployeeIdentityResource::forEmployee($employee),
            'period_id' => $periodId,
            'timesheet' => null,
            'is_filled' => false,
        ];
    }
}
