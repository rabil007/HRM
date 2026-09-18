<?php

namespace App\Support\Payroll;

use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\User;

final class PayslipSummary
{
    /**
     * @return array{total: int, generated: int, pending: int}
     */
    public static function forPeriod(PayrollPeriod $period, ?User $user = null): array
    {
        $recordsQuery = PayrollRecord::query()
            ->where('company_id', $period->company_id)
            ->where('period_id', $period->id);

        PayrollRecordAccess::apply($recordsQuery, $user, (int) $period->company_id);

        $total = (clone $recordsQuery)->count();

        if ($total === 0) {
            return [
                'total' => 0,
                'generated' => 0,
                'pending' => 0,
            ];
        }

        $generated = (clone $recordsQuery)
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
