<?php

namespace App\Support\Payroll;

use App\Enums\PayrollCategory;
use App\Models\PayrollRecord;

final class PayrollRecordIndexResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(PayrollRecord $record): array
    {
        $record->loadMissing(['employee', 'period']);

        $category = $record->payroll_category ?? PayrollCategory::Office;

        return [
            'id' => $record->id,
            'payroll_category' => $category->value,
            'payroll_category_label' => $category->label(),
            'employee' => [
                'id' => $record->employee_id,
                'name' => $record->employee?->name ?? '—',
                'employee_no' => $record->employee?->employee_no,
                'image' => $record->employee?->image,
            ],
            'period' => [
                'id' => $record->period_id,
                'name' => $record->period?->name ?? '—',
                'start_date' => $record->period?->start_date?->toDateString(),
                'end_date' => $record->period?->end_date?->toDateString(),
            ],
            'gross_salary' => self::formatAmount($record->gross_salary),
            'net_salary' => self::formatAmount($record->net_salary),
            'status' => $record->status,
            'has_payslip' => filled($record->payslip_path),
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
