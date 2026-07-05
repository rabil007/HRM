<?php

namespace App\Support\Payroll;

final class CrewOvertimeMonthlySalary
{
    /**
     * Full-month on-site equivalent used as the OT base:
     * period days × (basic daily + site daily + supplementary daily).
     */
    public static function fromDailyRates(
        int $periodDays,
        float $basicDaily,
        float $siteDaily = 0.0,
        float $supplementaryDaily = 0.0,
    ): float {
        if ($periodDays <= 0) {
            return 0.0;
        }

        $dailyOnsiteRate = $basicDaily + $siteDaily + $supplementaryDaily;

        return round($periodDays * $dailyOnsiteRate, 2);
    }
}
