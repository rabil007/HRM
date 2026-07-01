<?php

namespace App\Support\Payroll;

use App\Models\PayrollRecord;
use App\Models\SalaryInput;
use Illuminate\Support\Collection;

final class ApplyCrewSalaryInputs
{
    /**
     * @param  Collection<int, SalaryInput>  $inputs
     * @return array<string, mixed>
     */
    public function apply(PayrollRecord $record, Collection $inputs): array
    {
        $breakdown = is_array($record->calculation_breakdown) ? $record->calculation_breakdown : [];
        $base = $this->resolveBase($record, $breakdown);
        $totals = $this->sumByType($inputs);

        $additions = round($totals['bonus'] + $totals['commission'], 2);
        $deductions = round(
            $totals['unpaid_leave'] + $totals['late'] + $totals['loan'] + $totals['other'],
            2,
        );

        $bonus = round((float) $base['bonus'] + $additions, 2);
        $otherDeductions = round((float) $base['other_deductions'] + $deductions, 2);
        $grossSalary = round((float) $base['gross'] + $additions, 2);
        $totalDeductions = $otherDeductions;
        $netSalary = round($grossSalary - $totalDeductions, 2);

        $lines = is_array($breakdown['lines'] ?? null) ? $breakdown['lines'] : [];
        $lines['additional'] = $bonus;
        $lines['deduction'] = $otherDeductions;

        $salaryInputRows = $inputs
            ->sortBy('id')
            ->map(fn (SalaryInput $input) => SalaryInputResource::toArray($input))
            ->values()
            ->all();

        return [
            'bonus' => $this->formatMoney($bonus),
            'other_deductions' => $this->formatMoney($otherDeductions),
            'total_deductions' => $this->formatMoney($totalDeductions),
            'gross_salary' => $this->formatMoney($grossSalary),
            'net_salary' => $this->formatMoney($netSalary),
            'calculation_breakdown' => array_merge($breakdown, [
                'base' => $base,
                'salary_inputs' => $salaryInputRows,
                'salary_input_totals' => $totals,
                'lines' => $lines,
                'gross_salary' => $grossSalary,
                'net_salary' => $netSalary,
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $breakdown
     * @return array{gross: float, net: float, bonus: float, other_deductions: float}
     */
    private function resolveBase(PayrollRecord $record, array $breakdown): array
    {
        $storedBase = $breakdown['base'] ?? null;

        if (is_array($storedBase)) {
            return [
                'gross' => (float) ($storedBase['gross'] ?? $record->gross_salary),
                'net' => (float) ($storedBase['net'] ?? $record->net_salary),
                'bonus' => (float) ($storedBase['bonus'] ?? $record->bonus),
                'other_deductions' => (float) ($storedBase['other_deductions'] ?? $record->other_deductions),
            ];
        }

        return [
            'gross' => (float) $record->gross_salary,
            'net' => (float) $record->net_salary,
            'bonus' => (float) $record->bonus,
            'other_deductions' => (float) $record->other_deductions,
        ];
    }

    /**
     * @param  Collection<int, SalaryInput>  $inputs
     * @return array{bonus: float, commission: float, unpaid_leave: float, late: float, loan: float, other: float}
     */
    private function sumByType(Collection $inputs): array
    {
        $totals = [
            'bonus' => 0.0,
            'commission' => 0.0,
            'unpaid_leave' => 0.0,
            'late' => 0.0,
            'loan' => 0.0,
            'other' => 0.0,
        ];

        foreach ($inputs as $input) {
            $input->loadMissing('salaryInputType');
            $type = $input->salaryInputType;
            $code = (string) ($type?->code ?? 'other');
            $isAddition = (bool) ($type?->is_addition ?? false);

            $key = match ($code) {
                'bonus' => 'bonus',
                'commission' => 'commission',
                'unpaid_leave' => 'unpaid_leave',
                'late' => 'late',
                'loan' => 'loan',
                'other' => 'other',
                default => $isAddition ? 'bonus' : 'other',
            };

            $totals[$key] += (float) $input->amount;
        }

        return array_map(fn (float $value) => round($value, 2), $totals);
    }

    private function formatMoney(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
