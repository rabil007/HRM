<?php

namespace App\Support\Payroll;

use App\Enums\PayrollCategory;
use App\Enums\WpsStatus;
use App\Models\PayrollRecord;

final class PayslipListResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(PayrollRecord $record): array
    {
        $record->loadMissing(['employee', 'period']);

        $category = $record->payroll_category ?? PayrollCategory::Office;
        /** @var WpsStatus|null $wpsStatus */
        $wpsStatus = $record->wps_status;
        $paymentMethod = $record->resolvedSalaryPaymentMethod();

        $breakdown = $record->calculation_breakdown ?? [];
        $startDate = $breakdown['period_start_date'] ?? $record->period?->start_date?->toDateString();
        $endDate = $breakdown['period_end_date'] ?? $record->period?->end_date?->toDateString();

        return [
            'id' => $record->id,
            'payroll_category' => $category->value,
            'payroll_category_label' => $category->label(),
            'employee' => [
                'id' => $record->employee_id,
                'name' => $record->employee?->name ?? '—',
                'employee_no' => $record->employee?->employee_no,
                'work_email' => $record->employee?->work_email,
                'image' => $record->employee?->image,
            ],
            'period' => [
                'id' => $record->period_id,
                'name' => $record->period?->name ?? '—',
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'gross_salary' => self::formatAmount($record->gross_salary),
            'net_salary' => self::formatAmount($record->net_salary),
            'status' => $record->status,
            'payslip_path' => $record->payslip_path,
            'has_payslip' => filled($record->payslip_path),
            'wps_status' => $wpsStatus?->value,
            'wps_status_label' => $wpsStatus?->label(),
            'salary_payment_method' => $paymentMethod->value,
            'salary_payment_method_label' => $paymentMethod->label(),
        ];
    }

    private static function formatAmount(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        return number_format((float) $value, 2, '.', '');
    }
}
