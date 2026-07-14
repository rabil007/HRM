<?php

namespace App\Support\Payroll;

use App\Enums\SalaryPaymentMethod;
use App\Models\Employee;
use Carbon\CarbonInterface;

final class OfficePayrollBoardRow
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(
        Employee $employee,
        int $periodId,
        EmployeeLeavePeriodSummary $summary,
        CarbonInterface $asOf,
    ): array {
        $contract = $employee->currentContract;
        $paymentMethod = $employee->salary_payment_method ?? SalaryPaymentMethod::BankTransfer;

        return [
            'employee' => PayrollEmployeeIdentityResource::forEmployee($employee),
            'period_id' => $periodId,
            'timesheet' => null,
            'is_filled' => $summary->hasLeaveUsage(),
            'leave_usage' => $summary->toLeaveUsageArray(),
            'total_leave_days' => $summary->totalLeaveDays,
            'primary_account' => EmployeePrimaryAccountResource::forEmployee($employee),
            'salary_payment_method' => $paymentMethod->value,
            'salary_payment_method_label' => $paymentMethod->label(),
            'contract' => $contract !== null
                ? app(ResolveContractRatesForPeriod::class)->handle($contract, $asOf)
                : null,
        ];
    }
}
