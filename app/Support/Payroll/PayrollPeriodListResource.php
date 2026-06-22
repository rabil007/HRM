<?php

namespace App\Support\Payroll;

use App\Models\PayrollPeriod;

final class PayrollPeriodListResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(PayrollPeriod $period, int $crewEmployeeCount): array
    {
        $filledCount = (int) ($period->crew_timesheets_count ?? $period->crewTimesheets()->count());

        return [
            ...PayrollPeriodResource::toArray($period),
            'run_label' => $period->name.' · Crew',
            'crew_employee_count' => $crewEmployeeCount,
            'timesheets_filled_count' => $filledCount,
            'timesheets_progress_label' => $crewEmployeeCount > 0
                ? "{$filledCount}/{$crewEmployeeCount}"
                : '0/0',
        ];
    }
}
