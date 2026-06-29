<?php

namespace App\Support\Payroll;

use App\Models\PayrollRecord;
use App\Models\SalaryInput;
use Illuminate\Support\Collection;

final class ApplyOfficeSalaryInputs
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

        $bonus = round($totals['bonus'] + $totals['commission'], 2);
        $baseUnpaidLeave = (float) ($base['unpaid_leave_deduction'] ?? $record->unpaid_leave_deduction ?? 0);
        $unpaidLeaveDeduction = round($baseUnpaidLeave + $totals['unpaid_leave'], 2);
        $lateDeduction = round($totals['late'], 2);
        $loanDeduction = round($totals['loan'], 2);
        $otherDeductions = round($totals['other'], 2);
        $additions = round($bonus, 2);
        $totalDeductions = round($unpaidLeaveDeduction + $lateDeduction + $loanDeduction + $otherDeductions, 2);

        $grossSalary = round((float) $base['gross'] + $additions, 2);
        $netSalary = round($grossSalary - $totalDeductions, 2);

        $lines = is_array($breakdown['lines'] ?? null) ? $breakdown['lines'] : [];
        $lines['bonus'] = $bonus;
        $lines['unpaid_leave_deduction'] = $unpaidLeaveDeduction;
        $lines['late_deduction'] = $lateDeduction;
        $lines['loan_deduction'] = $loanDeduction;
        $lines['other_deduction'] = $otherDeductions;

        $salaryInputRows = $inputs
            ->sortBy('id')
            ->map(fn (SalaryInput $input) => SalaryInputResource::toArray($input))
            ->values()
            ->all();

        return [
            'bonus' => $this->formatMoney($bonus),
            'unpaid_leave_deduction' => $this->formatMoney($unpaidLeaveDeduction),
            'late_deduction' => $this->formatMoney($lateDeduction),
            'loan_deduction' => $this->formatMoney($loanDeduction),
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
     * @return array{basic: float, housing: float, transport: float, other: float, gross: float, net: float, bonus: float, unpaid_leave_deduction: float}
     */
    private function resolveBase(PayrollRecord $record, array $breakdown): array
    {
        $storedBase = $breakdown['base'] ?? null;

        if (is_array($storedBase)) {
            return [
                'basic' => (float) ($storedBase['basic'] ?? $record->basic_salary),
                'housing' => (float) ($storedBase['housing'] ?? $record->housing_allowance),
                'transport' => (float) ($storedBase['transport'] ?? $record->transport_allowance),
                'other' => (float) ($storedBase['other'] ?? $record->other_allowances),
                'gross' => (float) ($storedBase['gross'] ?? $record->gross_salary),
                'net' => (float) ($storedBase['net'] ?? $record->net_salary),
                'bonus' => (float) ($storedBase['bonus'] ?? 0),
                'unpaid_leave_deduction' => (float) ($storedBase['unpaid_leave_deduction'] ?? $record->unpaid_leave_deduction),
            ];
        }

        $gross = round(
            (float) $record->basic_salary
            + (float) $record->housing_allowance
            + (float) $record->transport_allowance
            + (float) $record->other_allowances
            + (float) $record->overtime_pay,
            2,
        );

        return [
            'basic' => (float) $record->basic_salary,
            'housing' => (float) $record->housing_allowance,
            'transport' => (float) $record->transport_allowance,
            'other' => (float) $record->other_allowances,
            'gross' => $gross,
            'net' => $gross,
            'bonus' => 0.0,
            'unpaid_leave_deduction' => (float) $record->unpaid_leave_deduction,
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
