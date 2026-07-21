<?php

namespace App\Support\Payroll;

use App\Enums\SalaryComponentCode;
use App\Enums\SalaryComponentStatus;
use App\Models\ContractSalaryComponent;
use App\Models\CrewTimesheet;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class CrewPayrollCalculator
{
    public function __construct(
        private readonly CrewOvertimePay $overtimePay,
    ) {}

    /**
     * @param  Collection<int, ContractSalaryComponent>  $components
     * @return array{
     *     basic_salary: string,
     *     other_allowances: string,
     *     overtime_pay: string,
     *     overtime_hours: float,
     *     bonus: string,
     *     other_deductions: string,
     *     total_deductions: string,
     *     gross_salary: string,
     *     net_salary: string,
     *     working_days: int,
     *     present_days: float,
     *     leave_days: float,
     *     calculation_breakdown: array<string, mixed>
     * }
     */
    public function calculate(
        CrewTimesheet $timesheet,
        Collection $components,
        int $overtimePeriodDays,
        int $workingDaysInPeriod,
    ): array {
        $basicRate = $this->activeAmount($components, SalaryComponentCode::Basic);
        $siteRate = $this->activeAmount($components, SalaryComponentCode::SiteAllowance);
        $supplementaryRate = $this->activeAmount($components, SalaryComponentCode::SupplementaryAllowance);

        $signOnStandbyDays = (float) ($timesheet->sign_on_standby_days ?? 0);
        $signOffStandbyDays = (float) ($timesheet->sign_off_standby_days ?? 0);
        $standbyDays = round($signOnStandbyDays + $signOffStandbyDays, 2);
        $onsiteDays = (float) ($timesheet->onsite_days ?? 0);
        $overtimeHours = (float) ($timesheet->overtime_hours ?? 0);
        $hasPayableActivity = $standbyDays > 0 || $onsiteDays > 0 || $overtimeHours > 0;

        if ($basicRate === null && $hasPayableActivity) {
            throw ValidationException::withMessages([
                'employee_id' => 'Active basic daily rate is required on the crew contract.',
            ]);
        }

        $basicRate ??= 0.0;
        $standbyDailyRate = $basicRate + ($supplementaryRate ?? 0);
        $signOnStandbyPay = round($signOnStandbyDays * $standbyDailyRate, 2);
        $signOffStandbyPay = round($signOffStandbyDays * $standbyDailyRate, 2);
        $standbyPay = round($signOnStandbyPay + $signOffStandbyPay, 2);

        $onsitePay = round($onsiteDays * $basicRate, 2);
        $siteAllowancePay = round($onsiteDays * ($siteRate ?? 0), 2);
        $supplementaryPay = round($onsiteDays * ($supplementaryRate ?? 0), 2);

        $overtimeBreakdown = $this->resolveOvertimePay(
            $overtimeHours,
            $overtimePeriodDays,
            $basicRate,
            $siteRate ?? 0.0,
            $supplementaryRate ?? 0.0,
        );
        $overtimePay = $overtimeBreakdown['overtime_pay'];

        $additionalAmount = round((float) ($timesheet->additional_amount ?? 0), 2);
        $deductionAmount = round((float) ($timesheet->deduction_amount ?? 0), 2);

        $grossSalary = round(
            $standbyPay + $onsitePay + $siteAllowancePay + $supplementaryPay + $overtimePay + $additionalAmount,
            2,
        );
        $netSalary = round($grossSalary - $deductionAmount, 2);
        $presentDays = round($standbyDays + $onsiteDays, 2);
        $leaveDays = round(max(0, $workingDaysInPeriod - $presentDays), 2);

        $lines = [
            'sign_on_standby_pay' => $signOnStandbyPay,
            'onsite_pay' => $onsitePay,
            'sign_off_standby_pay' => $signOffStandbyPay,
            'total_standby_pay' => $standbyPay,
            'site_allowance' => $siteAllowancePay,
            'supplementary_allowance' => $supplementaryPay,
            'overtime' => $overtimePay,
            'additional' => $additionalAmount,
            'deduction' => $deductionAmount,
        ];

        $breakdown = [
            'salary_structure' => 'daily',
            'sign_on_standby_days' => $signOnStandbyDays,
            'sign_on_standby_pay' => $signOnStandbyPay,
            'onsite_days' => $onsiteDays,
            'onsite_pay' => $onsitePay,
            'sign_off_standby_days' => $signOffStandbyDays,
            'sign_off_standby_pay' => $signOffStandbyPay,
            'total_standby_days' => $standbyDays,
            'total_standby_pay' => $standbyPay,
            'working_days' => $workingDaysInPeriod,
            'present_days' => $presentDays,
            'leave_days' => $leaveDays,
            'rates' => [
                'basic_daily' => $basicRate,
                'site_allowance_daily' => $siteRate ?? 0,
                'supplementary_allowance_daily' => $supplementaryRate ?? 0,
            ],
            'overtime' => $overtimeBreakdown,
            'lines' => $lines,
            'gross_salary' => $grossSalary,
            'net_salary' => $netSalary,
        ];

        return [
            'basic_salary' => $this->formatMoney($standbyPay + $onsitePay),
            'other_allowances' => $this->formatMoney($siteAllowancePay + $supplementaryPay),
            'overtime_pay' => $this->formatMoney($overtimePay),
            'overtime_hours' => $overtimeHours,
            'bonus' => $this->formatMoney($additionalAmount),
            'other_deductions' => $this->formatMoney($deductionAmount),
            'total_deductions' => $this->formatMoney($deductionAmount),
            'gross_salary' => $this->formatMoney($grossSalary),
            'net_salary' => $this->formatMoney($netSalary),
            'working_days' => $workingDaysInPeriod,
            'present_days' => $presentDays,
            'leave_days' => $leaveDays,
            'calculation_breakdown' => $breakdown,
        ];
    }

    /**
     * @return array{
     *     hours: float,
     *     period_days: int,
     *     daily_onsite_rate: float,
     *     monthly_salary: float,
     *     hour_rate: float,
     *     overtime_hourly_rate: float,
     *     overtime_pay: float
     * }
     */
    private function resolveOvertimePay(
        float $overtimeHours,
        int $periodDays,
        float $basicDaily,
        float $siteDaily,
        float $supplementaryDaily,
    ): array {
        if ($overtimeHours <= 0) {
            return [
                'hours' => 0.0,
                'period_days' => $periodDays,
                'daily_onsite_rate' => 0.0,
                'monthly_salary' => 0.0,
                'hour_rate' => 0.0,
                'overtime_hourly_rate' => 0.0,
                'overtime_pay' => 0.0,
            ];
        }

        $dailyOnsiteRate = round($basicDaily + $siteDaily + $supplementaryDaily, 2);
        $overtimeMonthlySalary = CrewOvertimeMonthlySalary::fromDailyRates(
            $periodDays,
            $basicDaily,
            $siteDaily,
            $supplementaryDaily,
        );

        if ($periodDays <= 0 || $overtimeMonthlySalary <= 0) {
            throw ValidationException::withMessages([
                'employee_id' => 'Pay period days and active crew daily rates are required when overtime hours are entered.',
            ]);
        }

        return array_merge(
            $this->overtimePay->calculate($overtimeHours, $overtimeMonthlySalary),
            [
                'period_days' => $periodDays,
                'daily_onsite_rate' => $dailyOnsiteRate,
            ],
        );
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
