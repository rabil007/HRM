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
        $record->loadMissing([
            'employee.department.parent:id,name',
            'employee.position:id,title',
        ]);

        $breakdown = $record->calculation_breakdown ?? [];
        $lines = is_array($breakdown['lines'] ?? null) ? $breakdown['lines'] : [];
        $category = $record->payroll_category ?? PayrollCategory::Office;
        /** @var WpsStatus|null $wpsStatus */
        $wpsStatus = $record->wps_status;
        $paymentMethod = $record->resolvedSalaryPaymentMethod();

        $base = [
            'id' => $record->id,
            'payroll_category' => $category->value,
            'employee' => PayrollEmployeeIdentityResource::forEmployee($record->employee),
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
            $rates = is_array($breakdown['rates'] ?? null) ? $breakdown['rates'] : [];
            $overtime = is_array($breakdown['overtime'] ?? null) ? $breakdown['overtime'] : [];

            return array_merge($base, [
                'basic_salary' => self::formatAmount($record->basic_salary),
                'deduction_amount' => self::formatAmount($record->total_deductions),
                'standby_days' => $breakdown['standby_days'] ?? null,
                'onsite_days' => $breakdown['onsite_days'] ?? null,
                'standby_pay' => self::formatAmount($lines['standby_pay'] ?? null),
                'onsite_pay' => self::formatAmount($lines['onsite_pay'] ?? null),
                'site_allowance' => self::formatAmount($lines['site_allowance'] ?? null),
                'supplementary_allowance' => self::formatAmount($lines['supplementary_allowance'] ?? null),
                'overtime_hours' => self::formatAmount($record->overtime_hours),
                'overtime' => [
                    'hours' => self::formatAmount($overtime['hours'] ?? $record->overtime_hours),
                    'monthly_salary' => self::formatAmount($overtime['monthly_salary'] ?? null),
                    'hour_rate' => self::formatAmount($overtime['hour_rate'] ?? null),
                    'overtime_hourly_rate' => self::formatAmount($overtime['overtime_hourly_rate'] ?? null),
                    'overtime_pay' => self::formatAmount($overtime['overtime_pay'] ?? $record->overtime_pay),
                ],
                'rates' => [
                    'basic_daily' => self::formatAmount($rates['basic_daily'] ?? null),
                    'site_allowance_daily' => self::formatAmount($rates['site_allowance_daily'] ?? null),
                    'supplementary_allowance_daily' => self::formatAmount($rates['supplementary_allowance_daily'] ?? null),
                ],
                'primary_account' => EmployeePrimaryAccountResource::forPayrollRecord($record),
                'salary_inputs_count' => $salaryInputsCount,
            ]);
        }

        return array_merge($base, [
            'basic_salary' => self::formatAmount($record->basic_salary),
            'housing_allowance' => self::formatAmount($record->housing_allowance),
            'transport_allowance' => self::formatAmount($record->transport_allowance),
            'other_allowances' => self::formatAmount($record->other_allowances),
            'primary_account' => EmployeePrimaryAccountResource::forPayrollRecord($record),
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
