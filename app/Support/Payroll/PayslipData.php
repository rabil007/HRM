<?php

namespace App\Support\Payroll;

use App\Enums\PayrollCategory;
use App\Models\Company;
use App\Models\PayrollRecord;
use Carbon\CarbonImmutable;

final class PayslipData
{
    /**
     * @return array<string, mixed>
     */
    public static function for(PayrollRecord $record, int $companyId): array
    {
        $record->loadMissing([
            'employee.position:id,title',
            'employee.company.currency:id,code,symbol',
            'period',
            'company.currency:id,code,symbol',
        ]);

        abort_unless((int) $record->company_id === $companyId, 404);

        $employee = $record->employee;
        $period = $record->period;
        $company = $record->company ?? Company::query()->with('currency:id,code,symbol')->find($companyId);
        $category = $record->payroll_category ?? PayrollCategory::Office;
        $breakdown = $record->calculation_breakdown ?? [];
        $lines = is_array($breakdown['lines'] ?? null) ? $breakdown['lines'] : [];
        $currencyCode = (string) ($company?->currency?->code ?? 'AED');

        $base = [
            'company_name' => (string) ($company?->name ?? ''),
            'company_logo' => $company?->logo ? \Illuminate\Support\Facades\Storage::disk('public')->url($company->logo) : null,
            'employee_name' => (string) ($employee?->name ?? ''),
            'employee_no' => (string) ($employee?->employee_no ?? ''),
            'designation' => (string) ($employee?->position?->title ?? ''),
            'period_name' => (string) ($period?->name ?? ''),
            'period_start' => $period?->start_date?->format('M d, Y') ?? '',
            'period_end' => $period?->end_date?->format('M d, Y') ?? '',
            'payment_date' => $period?->payment_date?->format('M d, Y') ?? '',
            'issued_on' => CarbonImmutable::now((string) ($company?->timezone ?? config('app.timezone')))->format('M d, Y'),
            'currency_code' => $currencyCode,
            'payroll_category' => $category->value,
            'payroll_category_label' => $category->label(),
            'gross_salary' => self::formatAmount($record->gross_salary),
            'total_deductions' => self::formatAmount($record->total_deductions),
            'net_salary' => self::formatAmount($record->net_salary),
            'status' => $record->status,
            'printable' => true,
            'is_pdf' => false,
        ];

        if ($category === PayrollCategory::Crew) {
            return array_merge($base, [
                'earnings' => [
                    ['label' => 'Standby pay', 'amount' => self::formatAmount($lines['standby_pay'] ?? 0)],
                    ['label' => 'Onsite pay', 'amount' => self::formatAmount($lines['onsite_pay'] ?? 0)],
                    ['label' => 'Site allowance', 'amount' => self::formatAmount($lines['site_allowance'] ?? 0)],
                    ['label' => 'Supplementary allowance', 'amount' => self::formatAmount($lines['supplementary_allowance'] ?? 0)],
                    ['label' => 'Overtime', 'amount' => self::formatAmount($record->overtime_pay)],
                    ['label' => 'Additional amount', 'amount' => self::formatAmount($record->bonus)],
                ],
                'deductions' => [
                    ['label' => 'Deductions', 'amount' => self::formatAmount($record->total_deductions)],
                ],
                'standby_days' => $breakdown['standby_days'] ?? null,
                'onsite_days' => $breakdown['onsite_days'] ?? null,
            ]);
        }

        return array_merge($base, [
            'earnings' => [
                ['label' => 'Basic salary', 'amount' => self::formatAmount($record->basic_salary)],
                ['label' => 'Housing allowance', 'amount' => self::formatAmount($record->housing_allowance)],
                ['label' => 'Transport allowance', 'amount' => self::formatAmount($record->transport_allowance)],
                ['label' => 'Other allowances', 'amount' => self::formatAmount($record->other_allowances)],
                ['label' => 'Overtime', 'amount' => self::formatAmount($record->overtime_pay)],
                ['label' => 'Bonus', 'amount' => self::formatAmount($record->bonus)],
            ],
            'deductions' => [],
            'working_days' => $record->working_days,
            'present_days' => $record->present_days,
            'absent_days' => $record->absent_days,
            'leave_days' => self::formatAmount($record->leave_days),
            'overtime_hours' => self::formatAmount($record->overtime_hours),
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
