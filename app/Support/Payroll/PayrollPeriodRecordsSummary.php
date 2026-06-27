<?php

namespace App\Support\Payroll;

use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;

final class PayrollPeriodRecordsSummary
{
    /**
     * @return array{
     *     employee_count: int,
     *     total_gross: string,
     *     total_net: string,
     *     total_deductions: string,
     * }
     */
    public static function forPeriod(PayrollPeriod $period): array
    {
        $row = PayrollRecord::query()
            ->where('company_id', $period->company_id)
            ->where('period_id', $period->id)
            ->selectRaw('COUNT(*) as employee_count, COALESCE(SUM(gross_salary), 0) as total_gross, COALESCE(SUM(net_salary), 0) as total_net, COALESCE(SUM(total_deductions), 0) as total_deductions')
            ->first();

        return [
            'employee_count' => (int) ($row->employee_count ?? 0),
            'total_gross' => number_format((float) ($row->total_gross ?? 0), 2, '.', ''),
            'total_net' => number_format((float) ($row->total_net ?? 0), 2, '.', ''),
            'total_deductions' => number_format((float) ($row->total_deductions ?? 0), 2, '.', ''),
        ];
    }
}
