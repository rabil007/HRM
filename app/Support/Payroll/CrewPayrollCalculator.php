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
    /**
     * @param  Collection<int, ContractSalaryComponent>  $components
     * @return array{
     *     basic_salary: string,
     *     other_allowances: string,
     *     overtime_pay: string,
     *     bonus: string,
     *     other_deductions: string,
     *     total_deductions: string,
     *     gross_salary: string,
     *     net_salary: string,
     *     calculation_breakdown: array<string, mixed>
     * }
     */
    public function calculate(CrewTimesheet $timesheet, Collection $components): array
    {
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
        $standbyPay = round($standbyDays * $basicRate, 2);
        $onsitePay = round($onsiteDays * $basicRate, 2);
        $siteAllowancePay = round($onsiteDays * ($siteRate ?? 0), 2);
        $supplementaryPay = round($onsiteDays * ($supplementaryRate ?? 0), 2);
        $overtimePay = round((float) ($timesheet->overtime_amount ?? 0), 2);
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
