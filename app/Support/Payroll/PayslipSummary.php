<?php

namespace App\Support\Payroll;

use App\Models\PayrollPeriod;

final class PayslipSummary
{
    /**
     * @return array{total: int, generated: int, pending: int}
     */
    public static function forPeriod(PayrollPeriod $period): array
    {
        $total = (int) $period->payroll_records_count;

        if ($total === 0) {
            return [
                'total' => 0,
                'generated' => 0,
                'pending' => 0,
            ];
        }

        $generated = $period->payrollRecords()
            ->whereNotNull('payslip_path')
            ->where('payslip_path', '!=', '')
            ->count();

        return [
            'total' => $total,
            'generated' => $generated,
            'pending' => max(0, $total - $generated),
        ];
    }
}
