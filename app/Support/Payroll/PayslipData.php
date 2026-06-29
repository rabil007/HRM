<?php

namespace App\Support\Payroll;

use App\Enums\PayrollCategory;
use App\Models\Company;
use App\Models\PayrollRecord;
use App\Models\SalaryInput;
use App\Support\Media\CompanyLogoDataUri;
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

        $periodStart = ! empty($breakdown['period_start_date'])
            ? CarbonImmutable::parse($breakdown['period_start_date'])->format('M d, Y')
            : ($period?->start_date?->format('M d, Y') ?? '');

        $periodEnd = ! empty($breakdown['period_end_date'])
            ? CarbonImmutable::parse($breakdown['period_end_date'])->format('M d, Y')
            : ($period?->end_date?->format('M d, Y') ?? '');

        $base = [
            'company_name' => (string) ($company?->name ?? ''),
            'company_logo' => CompanyLogoDataUri::resolve($company),
            'employee_name' => (string) ($employee?->name ?? ''),
            'employee_no' => (string) ($employee?->employee_no ?? ''),
            'designation' => (string) ($employee?->position?->title ?? ''),
            'period_name' => (string) ($period?->name ?? ''),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
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

        $salaryInputLines = self::resolveOfficeSalaryInputLines($record, $breakdown);

        return array_merge($base, [
            'earnings' => self::officeEarnings($record, $salaryInputLines),
            'deductions' => self::officeDeductions($record, $salaryInputLines),
            'working_days' => $record->working_days,
            'present_days' => $record->present_days,
            'absent_days' => $record->absent_days,
            'leave_days' => self::formatAmount($record->leave_days),
            'overtime_hours' => self::formatAmount($record->overtime_hours),
        ]);
    }

    /**
     * @param  array<string, mixed>  $breakdown
     * @return list<array<string, mixed>>
     */
    private static function resolveOfficeSalaryInputLines(PayrollRecord $record, array $breakdown): array
    {
        $stored = is_array($breakdown['salary_inputs'] ?? null) ? $breakdown['salary_inputs'] : [];

        if ($stored !== []) {
            return $stored;
        }

        return SalaryInput::query()
            ->where('company_id', $record->company_id)
            ->where('period_id', $record->period_id)
            ->where('employee_id', $record->employee_id)
            ->with('salaryInputType')
            ->orderBy('id')
            ->get()
            ->map(fn (SalaryInput $input) => SalaryInputResource::toArray($input))
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $salaryInputLines
     * @return list<array{label: string, amount: string}>
     */
    private static function officeEarnings(PayrollRecord $record, array $salaryInputLines): array
    {
        $coreRows = [
            ['label' => 'Basic salary', 'amount' => self::formatAmount($record->basic_salary)],
            ['label' => 'Housing allowance', 'amount' => self::formatAmount($record->housing_allowance)],
            ['label' => 'Transport allowance', 'amount' => self::formatAmount($record->transport_allowance)],
            ['label' => 'Other allowances', 'amount' => self::formatAmount($record->other_allowances)],
        ];

        $optionalRows = [
            ['label' => 'Overtime', 'amount' => self::formatAmount($record->overtime_pay)],
        ];

        foreach ($salaryInputLines as $input) {
            if (! ($input['is_addition'] ?? false)) {
                continue;
            }

            $optionalRows[] = [
                'label' => (string) ($input['type_label'] ?? $input['type'] ?? 'Addition'),
                'amount' => self::formatAmount($input['amount'] ?? 0),
            ];
        }

        if ($salaryInputLines === [] && (float) $record->bonus > 0) {
            $optionalRows[] = ['label' => 'Bonus', 'amount' => self::formatAmount($record->bonus)];
        }

        return array_merge($coreRows, self::filterPositiveLines($optionalRows));
    }

    /**
     * @param  list<array<string, mixed>>  $salaryInputLines
     * @return list<array{label: string, amount: string}>
     */
    private static function officeDeductions(PayrollRecord $record, array $salaryInputLines): array
    {
        if ($salaryInputLines !== []) {
            $rows = [];

            foreach ($salaryInputLines as $input) {
                if ($input['is_addition'] ?? false) {
                    continue;
                }

                $rows[] = [
                    'label' => (string) ($input['type_label'] ?? $input['type'] ?? 'Deduction'),
                    'amount' => self::formatAmount($input['amount'] ?? 0),
                ];
            }

            return self::filterPositiveLines($rows);
        }

        $rows = [
            ['label' => 'Unpaid leave', 'amount' => self::formatAmount($record->unpaid_leave_deduction)],
            ['label' => 'Late', 'amount' => self::formatAmount($record->late_deduction)],
            ['label' => 'Loan', 'amount' => self::formatAmount($record->loan_deduction)],
            ['label' => 'Other', 'amount' => self::formatAmount($record->other_deductions)],
        ];

        return self::filterPositiveLines($rows);
    }

    /**
     * @param  list<array{label: string, amount: string}>  $rows
     * @return list<array{label: string, amount: string}>
     */
    private static function filterPositiveLines(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            fn (array $row): bool => (float) $row['amount'] > 0,
        ));
    }

    private static function formatAmount(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        return number_format((float) $value, 2, '.', '');
    }
}
