<?php

namespace App\Support\Payroll;

final class CrewOvertimeMonthlySalary
{
    /**
     * Fixed 30-day month used for crew OT base, matching the Excel payroll sheet.
     */
    public const STANDARD_PERIOD_DAYS = 30;

    /**
     * Full-month on-site equivalent used as the OT base:
     * standard period days × (basic daily + site daily + supplementary daily).
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
