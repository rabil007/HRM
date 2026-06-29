<?php

namespace App\Support\Payroll;

use App\Enums\SalaryPaymentMethod;
use App\Models\CrewTimesheet;
use App\Models\Employee;

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

        return [
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'employee_no' => $employee->employee_no,
                'image' => $employee->image,
            ],
            'period_id' => $periodId,
            'timesheet' => self::toArray($timesheet),
            'is_filled' => $timesheet !== null,
            'primary_account' => EmployeePrimaryAccountResource::forEmployee($employee),
            'salary_payment_method' => $paymentMethod->value,
            'salary_payment_method_label' => $paymentMethod->label(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function toEmployeeRow(Employee $employee, int $periodId): array
    {
        return [
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'employee_no' => $employee->employee_no,
                'image' => $employee->image,
            ],
            'period_id' => $periodId,
            'timesheet' => null,
            'is_filled' => false,
        ];
    }
}
