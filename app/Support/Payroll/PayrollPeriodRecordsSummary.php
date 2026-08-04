<?php

namespace App\Support\Payroll;

use App\Enums\PayrollCategory;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\SalaryInput;

final class PayrollPeriodRecordsSummary
{
    /**
     * @return array{
     *     employee_count: int,
     *     daily_employee_count: int,
     *     monthly_employee_count: int,
     *     total_gross: string,
     *     total_net: string,
     *     total_additions: string,
     *     total_deductions: string,
     *     total_overtime_pay: string,
     *     total_overtime_hours: string,
     * }
     */
    public static function forPeriod(PayrollPeriod $period): array
    {
        $row = PayrollRecord::query()
            ->where('company_id', $period->company_id)
            ->where('period_id', $period->id)
            ->selectRaw('COUNT(*) as employee_count, COALESCE(SUM(gross_salary), 0) as total_gross, COALESCE(SUM(net_salary), 0) as total_net, COALESCE(SUM(total_deductions), 0) as total_deductions, COALESCE(SUM(overtime_pay), 0) as total_overtime_pay, COALESCE(SUM(overtime_hours), 0) as total_overtime_hours')
            ->first();

        $dailyEmployeeCount = 0;
        $monthlyEmployeeCount = 0;

        if ($period->payroll_category === PayrollCategory::Crew) {
            $base = PayrollRecord::query()
                ->where('company_id', $period->company_id)
                ->where('period_id', $period->id);

            $dailyEmployeeCount = (clone $base)->crewDaily()->count();
            $monthlyEmployeeCount = (clone $base)->crewMonthly()->count();
        }

        $totalAdditions = SalaryInput::query()
            ->where('company_id', $period->company_id)
            ->where('period_id', $period->id)
            ->whereHas('salaryInputType', fn ($query) => $query->where('is_addition', true))
            ->sum('amount');

        return [
            'employee_count' => (int) ($row->employee_count ?? 0),
            'daily_employee_count' => $dailyEmployeeCount,
            'monthly_employee_count' => $monthlyEmployeeCount,
            'total_gross' => number_format((float) ($row->total_gross ?? 0), 2, '.', ''),
            'total_net' => number_format((float) ($row->total_net ?? 0), 2, '.', ''),
            'total_additions' => number_format((float) $totalAdditions, 2, '.', ''),
            'total_deductions' => number_format((float) ($row->total_deductions ?? 0), 2, '.', ''),
            'total_overtime_pay' => number_format((float) ($row->total_overtime_pay ?? 0), 2, '.', ''),
            'total_overtime_hours' => number_format((float) ($row->total_overtime_hours ?? 0), 2, '.', ''),
        ];
    }
}
