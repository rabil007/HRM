<?php

namespace App\Support\Payroll;

use App\Enums\PayrollCategory;
use App\Enums\WpsStatus;
use App\Models\PayrollRecord;

final class PayrollRecordResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(PayrollRecord $record, int $salaryInputsCount = 0): array
    {
        $record->loadMissing('employee.primaryBankAccount.bank');

        $breakdown = $record->calculation_breakdown ?? [];
        $lines = is_array($breakdown['lines'] ?? null) ? $breakdown['lines'] : [];
        $category = $record->payroll_category ?? PayrollCategory::Office;
        /** @var WpsStatus|null $wpsStatus */
        $wpsStatus = $record->wps_status;
        $paymentMethod = $record->resolvedSalaryPaymentMethod();

        $base = [
            'id' => $record->id,
            'payroll_category' => $category->value,
            'employee' => [
                'id' => $record->employee_id,
                'name' => $record->employee?->name ?? '—',
                'employee_no' => $record->employee?->employee_no,
                'image' => $record->employee?->image,
            ],
            'overtime_pay' => self::formatAmount($record->overtime_pay),
            'additional_amount' => self::formatAmount($record->bonus),
            'gross_salary' => self::formatAmount($record->gross_salary),
            'net_salary' => self::formatAmount($record->net_salary),
            'status' => $record->status,
            'has_payslip' => filled($record->payslip_path),
            'wps_status' => $wpsStatus?->value,
            'wps_status_label' => $wpsStatus?->label(),
            'salary_payment_method' => $paymentMethod->value,
            'salary_payment_method_label' => $paymentMethod->label(),
        ];

        if ($category === PayrollCategory::Crew) {
            return array_merge($base, [
                'deduction_amount' => self::formatAmount($record->total_deductions),
                'standby_days' => $breakdown['standby_days'] ?? null,
                'onsite_days' => $breakdown['onsite_days'] ?? null,
                'standby_pay' => self::formatAmount($lines['standby_pay'] ?? null),
                'onsite_pay' => self::formatAmount($lines['onsite_pay'] ?? null),
                'site_allowance' => self::formatAmount($lines['site_allowance'] ?? null),
                'supplementary_allowance' => self::formatAmount($lines['supplementary_allowance'] ?? null),
                'primary_account' => EmployeePrimaryAccountResource::forEmployee($record->employee),
            ]);
        }

        return array_merge($base, [
            'basic_salary' => self::formatAmount($record->basic_salary),
            'housing_allowance' => self::formatAmount($record->housing_allowance),
            'transport_allowance' => self::formatAmount($record->transport_allowance),
            'other_allowances' => self::formatAmount($record->other_allowances),
            'primary_account' => EmployeePrimaryAccountResource::forEmployee($record->employee),
            'salary_inputs_count' => $salaryInputsCount,
            'working_days' => $record->working_days,
            'present_days' => $record->present_days,
            'absent_days' => $record->absent_days,
            'unpaid_leave_deduction' => self::formatAmount($record->unpaid_leave_deduction),
        ]);
    }

    private static function formatAmount(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        return number_format((float) $value, 2, '.', '');
    }
}
