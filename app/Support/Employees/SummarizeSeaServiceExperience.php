<?php

namespace App\Support\Employees;

final class SummarizeSeaServiceExperience
{
    /**
     * @param  list<array{total_months?: int|null, total_days?: int|null, start_date?: string|null, end_date?: string|null}>  $rows
     */
    public static function formatYmd(array $rows): string
    {
        $periodDays = 0;

        foreach ($rows as $row) {
            if (! empty($row['start_date']) && ! empty($row['end_date'])) {
                $periodDays += (int) ($row['total_days'] ?? 0);

                continue;
            }

            $periodDays += ((int) ($row['total_months'] ?? 0) * 30) + (int) ($row['total_days'] ?? 0);
        }

        $months = intdiv($periodDays, 30);
        $days = $periodDays % 30;
        $years = intdiv($months, 12);
        $months %= 12;

        return "{$years}Y/{$months}M/{$days}D";
    }
}
