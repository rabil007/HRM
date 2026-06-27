<?php

namespace App\Support\Payroll;

use App\Enums\SalaryComponentCode;
use App\Enums\SalaryComponentStatus;
use App\Models\ContractSalaryComponent;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class OfficePayrollCalculator
{
    /**
     * @param  Collection<int, ContractSalaryComponent>  $components
     * @param  list<array{leave_type_id: int, code: string, name: string, color: string|null, days: float}>  $leaveUsage
     * @return array{
     *     basic_salary: string,
     *     housing_allowance: string,
     *     transport_allowance: string,
     *     other_allowances: string,
     *     overtime_pay: string,
     *     bonus: string,
     *     other_deductions: string,
     *     unpaid_leave_deduction: string,
     *     late_deduction: string,
     *     loan_deduction: string,
     *     total_deductions: string,
     *     gross_salary: string,
     *     net_salary: string,
     *     working_days: int,
     *     present_days: float,
     *     absent_days: float,
     *     leave_days: float,
     *     overtime_hours: float,
     *     calculation_breakdown: array<string, mixed>
     * }
     */
    public function calculate(
        Collection $components,
        int $workingDays,
        float $totalLeaveDays = 0.0,
        array $leaveUsage = [],
    ): array {
        $monthlyBasic = $this->activeAmount($components, SalaryComponentCode::Basic);
        $monthlyHousing = $this->activeAmount($components, SalaryComponentCode::Housing) ?? 0.0;
        $monthlyTransport = $this->activeAmount($components, SalaryComponentCode::Transport) ?? 0.0;
        $monthlyOther = $this->activeAmount($components, SalaryComponentCode::Other) ?? 0.0;

        if ($monthlyBasic === null) {
            throw ValidationException::withMessages([
                'basic_salary' => 'Active basic monthly salary is required on the office contract.',
            ]);
        }

        $earnedBasic = round($monthlyBasic, 2);
        $earnedHousing = round($monthlyHousing, 2);
        $earnedTransport = round($monthlyTransport, 2);
        $earnedOther = round($monthlyOther, 2);

        $overtimePay = 0.0;
        $bonus = 0.0;
        $unpaidLeaveDeduction = 0.0;
        $lateDeduction = 0.0;
        $loanDeduction = 0.0;
        $otherDeductions = 0.0;

        $grossSalary = round(
            $earnedBasic + $earnedHousing + $earnedTransport + $earnedOther + $overtimePay + $bonus,
            2,
        );

        $totalDeductions = round(
            $unpaidLeaveDeduction + $lateDeduction + $loanDeduction + $otherDeductions,
            2,
        );

        $netSalary = round($grossSalary - $totalDeductions, 2);
        $presentDays = max(0.0, round($workingDays - $totalLeaveDays, 2));
        $absentDays = round($totalLeaveDays, 2);

        return [
            'basic_salary' => $this->formatMoney($earnedBasic),
            'housing_allowance' => $this->formatMoney($earnedHousing),
            'transport_allowance' => $this->formatMoney($earnedTransport),
            'other_allowances' => $this->formatMoney($earnedOther),
            'overtime_pay' => $this->formatMoney($overtimePay),
            'bonus' => $this->formatMoney($bonus),
            'other_deductions' => $this->formatMoney($otherDeductions),
            'unpaid_leave_deduction' => $this->formatMoney($unpaidLeaveDeduction),
            'late_deduction' => $this->formatMoney($lateDeduction),
            'loan_deduction' => $this->formatMoney($loanDeduction),
            'total_deductions' => $this->formatMoney($totalDeductions),
            'gross_salary' => $this->formatMoney($grossSalary),
            'net_salary' => $this->formatMoney($netSalary),
            'working_days' => $workingDays,
            'present_days' => $presentDays,
            'absent_days' => $absentDays,
            'leave_days' => $absentDays,
            'overtime_hours' => 0.0,
            'calculation_breakdown' => [
                'base' => [
                    'basic' => $earnedBasic,
                    'housing' => $earnedHousing,
                    'transport' => $earnedTransport,
                    'other' => $earnedOther,
                    'gross' => $grossSalary,
                    'net' => $netSalary,
                    'bonus' => $bonus,
                ],
                'working_days' => $workingDays,
                'present_days' => $presentDays,
                'absent_days' => $absentDays,
                'leave_days' => $absentDays,
                'leave_usage' => $leaveUsage,
                'rates' => [
                    'basic_monthly' => $monthlyBasic,
                    'housing_monthly' => $monthlyHousing,
                    'transport_monthly' => $monthlyTransport,
                    'other_monthly' => $monthlyOther,
                ],
                'lines' => [
                    'basic' => $earnedBasic,
                    'housing' => $earnedHousing,
                    'transport' => $earnedTransport,
                    'other' => $earnedOther,
                    'overtime' => $overtimePay,
                    'bonus' => $bonus,
                    'unpaid_leave_deduction' => $unpaidLeaveDeduction,
                    'late_deduction' => $lateDeduction,
                    'loan_deduction' => $loanDeduction,
                    'other_deduction' => $otherDeductions,
                ],
                'gross_salary' => $grossSalary,
                'net_salary' => $netSalary,
            ],
        ];
    }

    /**
     * @param  Collection<int, ContractSalaryComponent>  $components
     */
    private function activeAmount(Collection $components, SalaryComponentCode $code): ?float
    {
        $component = $components->first(
            fn (ContractSalaryComponent $item) => $item->component_code === $code
                && $item->status === SalaryComponentStatus::Active,
        );

        if ($component === null || (float) $component->amount <= 0) {
            return null;
        }

        return (float) $component->amount;
    }

    private function formatMoney(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
