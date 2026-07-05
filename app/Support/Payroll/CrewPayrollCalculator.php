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
     *     calculation_breakdown: array<string, mixed>
     * }
     */
    public function calculate(
        CrewTimesheet $timesheet,
        Collection $components,
        ?float $overtimeMonthlySalary = null,
    ): array {
        $basicRate = $this->activeAmount($components, SalaryComponentCode::Basic);
        $siteRate = $this->activeAmount($components, SalaryComponentCode::SiteAllowance);
        $supplementaryRate = $this->activeAmount($components, SalaryComponentCode::SupplementaryAllowance);

        if ($basicRate === null) {
            throw ValidationException::withMessages([
                'employee_id' => 'Active basic daily rate is required on the crew contract.',
            ]);
        }

        $standbyDays = (float) ($timesheet->standby_days ?? 0);
        $onsiteDays = (float) ($timesheet->onsite_days ?? 0);
        $standbyPay = round($standbyDays * ($basicRate + ($supplementaryRate ?? 0)), 2);
        $onsitePay = round($onsiteDays * $basicRate, 2);
        $siteAllowancePay = round($onsiteDays * ($siteRate ?? 0), 2);
        $supplementaryPay = round($onsiteDays * ($supplementaryRate ?? 0), 2);

        $overtimeHours = (float) ($timesheet->overtime_hours ?? 0);
        $overtimeBreakdown = $this->resolveOvertimePay($overtimeHours, $overtimeMonthlySalary);
        $overtimePay = $overtimeBreakdown['overtime_pay'];

        $additionalAmount = round((float) ($timesheet->additional_amount ?? 0), 2);
        $deductionAmount = round((float) ($timesheet->deduction_amount ?? 0), 2);

        $grossSalary = round(
            $standbyPay + $onsitePay + $siteAllowancePay + $supplementaryPay + $overtimePay + $additionalAmount,
            2,
        );
        $netSalary = round($grossSalary - $deductionAmount, 2);

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
            'calculation_breakdown' => [
                'standby_days' => $standbyDays,
                'onsite_days' => $onsiteDays,
                'rates' => [
                    'basic_daily' => $basicRate,
                    'site_allowance_daily' => $siteRate ?? 0,
                    'supplementary_allowance_daily' => $supplementaryRate ?? 0,
                ],
                'overtime' => $overtimeBreakdown,
                'lines' => [
                    'standby_pay' => $standbyPay,
                    'onsite_pay' => $onsitePay,
                    'site_allowance' => $siteAllowancePay,
                    'supplementary_allowance' => $supplementaryPay,
                    'overtime' => $overtimePay,
                    'additional' => $additionalAmount,
                    'deduction' => $deductionAmount,
                ],
                'gross_salary' => $grossSalary,
                'net_salary' => $netSalary,
            ],
        ];
    }

    /**
     * @return array{
     *     hours: float,
     *     monthly_salary: float,
     *     hour_rate: float,
     *     overtime_hourly_rate: float,
     *     overtime_pay: float
     * }
     */
    private function resolveOvertimePay(float $overtimeHours, ?float $overtimeMonthlySalary): array
    {
        if ($overtimeHours <= 0) {
            return [
                'hours' => 0.0,
                'monthly_salary' => 0.0,
                'hour_rate' => 0.0,
                'overtime_hourly_rate' => 0.0,
                'overtime_pay' => 0.0,
            ];
        }

        if ($overtimeMonthlySalary === null || $overtimeMonthlySalary <= 0) {
            throw ValidationException::withMessages([
                'employee_id' => 'Overtime monthly salary is required on the crew contract when overtime hours are entered.',
            ]);
        }

        return $this->overtimePay->calculate($overtimeHours, $overtimeMonthlySalary);
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
