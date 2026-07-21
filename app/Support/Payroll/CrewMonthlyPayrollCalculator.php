<?php

namespace App\Support\Payroll;

use App\Enums\SalaryComponentCode;
use App\Enums\SalaryComponentStatus;
use App\Models\ContractSalaryComponent;
use App\Models\CrewTimesheet;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class CrewMonthlyPayrollCalculator
{
    /**
     * @param  Collection<int, ContractSalaryComponent>  $components
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
        CrewTimesheet $timesheet,
        Collection $components,
        int $workingDaysInPeriod,
    ): array {
        $monthlyBasic = $this->activeAmount($components, SalaryComponentCode::Basic);
        $monthlyHousing = $this->activeAmount($components, SalaryComponentCode::Housing) ?? 0.0;
        $monthlyTransport = $this->activeAmount($components, SalaryComponentCode::Transport) ?? 0.0;
        $monthlyOther = $this->activeAmount($components, SalaryComponentCode::Other) ?? 0.0;

        $unpaidLeaveDays = (float) ($timesheet->unpaid_leave_days ?? 0);

        if ($monthlyBasic === null) {
            throw ValidationException::withMessages([
                'employee_id' => 'Active basic monthly salary is required on the crew contract.',
            ]);
        }

        $workingDays = max(0, $workingDaysInPeriod);
        $activePeriodDays = max(0, $workingDays - $unpaidLeaveDays);
        $prorateRatio = $workingDays > 0 ? ($activePeriodDays / $workingDays) : 1.0;

        $earnedBasic = round($monthlyBasic * $prorateRatio, 2);
        $earnedHousing = round($monthlyHousing * $prorateRatio, 2);
        $earnedTransport = round($monthlyTransport * $prorateRatio, 2);
        $earnedOther = round($monthlyOther * $prorateRatio, 2);

        $monthlyBase = $monthlyBasic + $monthlyHousing + $monthlyTransport + $monthlyOther;
        $dailyRate = $workingDays > 0 ? ($monthlyBase / $workingDays) : 0.0;
        $unpaidLeaveDeduction = round($dailyRate * $unpaidLeaveDays, 2);

        $additionalAmount = round((float) ($timesheet->additional_amount ?? 0), 2);
        $deductionAmount = round((float) ($timesheet->deduction_amount ?? 0), 2);

        $grossSalary = round(
            $earnedBasic + $earnedHousing + $earnedTransport + $earnedOther + $additionalAmount,
            2,
        );

        $totalDeductions = round($unpaidLeaveDeduction + $deductionAmount, 2);
        $netSalary = round($grossSalary - $totalDeductions, 2);
        $presentDays = round($activePeriodDays, 2);
        $absentDays = round($unpaidLeaveDays, 2);

        return [
            'basic_salary' => $this->formatMoney($earnedBasic),
            'housing_allowance' => $this->formatMoney($earnedHousing),
            'transport_allowance' => $this->formatMoney($earnedTransport),
            'other_allowances' => $this->formatMoney($earnedOther),
            'overtime_pay' => $this->formatMoney(0.0),
            'bonus' => $this->formatMoney($additionalAmount),
            'other_deductions' => $this->formatMoney($deductionAmount),
            'unpaid_leave_deduction' => $this->formatMoney($unpaidLeaveDeduction),
            'late_deduction' => $this->formatMoney(0.0),
            'loan_deduction' => $this->formatMoney(0.0),
            'total_deductions' => $this->formatMoney($totalDeductions),
            'gross_salary' => $this->formatMoney($grossSalary),
            'net_salary' => $this->formatMoney($netSalary),
            'working_days' => $workingDays,
            'present_days' => $presentDays,
            'absent_days' => $absentDays,
            'leave_days' => $absentDays,
            'overtime_hours' => 0.0,
            'calculation_breakdown' => [
                'salary_structure' => 'monthly',
                'unpaid_leave_days' => $unpaidLeaveDays,
                'working_days' => $workingDays,
                'present_days' => $presentDays,
                'leave_days' => $absentDays,
                'absent_days' => $absentDays,
                'rates' => [
                    'basic_monthly' => $monthlyBasic,
                    'housing_monthly' => $monthlyHousing,
                    'transport_monthly' => $monthlyTransport,
                    'other_monthly' => $monthlyOther,
                    'daily_rate' => round($dailyRate, 2),
                ],
                'lines' => [
                    'basic' => $earnedBasic,
                    'housing' => $earnedHousing,
                    'transport' => $earnedTransport,
                    'other' => $earnedOther,
                    'overtime' => 0.0,
                    'bonus' => $additionalAmount,
                    'unpaid_leave_deduction' => $unpaidLeaveDeduction,
                    'late_deduction' => 0.0,
                    'loan_deduction' => 0.0,
                    'other_deduction' => $deductionAmount,
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
