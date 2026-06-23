<?php

namespace App\Support\Payroll;

use App\Models\PayrollRecord;

final class PayrollRecordResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(PayrollRecord $record): array
    {
        $record->loadMissing('employee');

        $breakdown = $record->calculation_breakdown ?? [];
        $lines = is_array($breakdown['lines'] ?? null) ? $breakdown['lines'] : [];

        return [
            'id' => $record->id,
            'employee' => [
                'id' => $record->employee_id,
                'name' => $record->employee?->name ?? '—',
                'employee_no' => $record->employee?->employee_no,
            ],
            'standby_days' => $breakdown['standby_days'] ?? null,
            'onsite_days' => $breakdown['onsite_days'] ?? null,
            'standby_pay' => self::formatAmount($lines['standby_pay'] ?? null),
            'onsite_pay' => self::formatAmount($lines['onsite_pay'] ?? null),
            'site_allowance' => self::formatAmount($lines['site_allowance'] ?? null),
            'supplementary_allowance' => self::formatAmount($lines['supplementary_allowance'] ?? null),
            'overtime_pay' => self::formatAmount($record->overtime_pay),
            'additional_amount' => self::formatAmount($record->bonus),
            'deduction_amount' => self::formatAmount($record->other_deductions),
            'gross_salary' => self::formatAmount($record->gross_salary),
            'net_salary' => self::formatAmount($record->net_salary),
            'status' => $record->status,
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
