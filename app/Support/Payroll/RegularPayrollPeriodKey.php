<?php

namespace App\Support\Payroll;

use App\Enums\PayrollCategory;
use App\Models\PayrollPeriod;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class RegularPayrollPeriodKey
{
    public static function for(
        int $companyId,
        PayrollCategory $category,
        CarbonInterface $monthStart,
    ): string {
        $month = CarbonImmutable::parse($monthStart->toDateString())->startOfMonth();

        return sprintf(
            'company:%d:%s:%s',
            $companyId,
            $category->value,
            $month->format('Y-m'),
        );
    }

    public static function tryFromDates(
        int $companyId,
        PayrollCategory $category,
        CarbonInterface|string $startDate,
        CarbonInterface|string $endDate,
    ): ?string {
        $start = CarbonImmutable::parse(
            $startDate instanceof CarbonInterface ? $startDate->toDateString() : $startDate,
        )->startOfDay();
        $end = CarbonImmutable::parse(
            $endDate instanceof CarbonInterface ? $endDate->toDateString() : $endDate,
        )->startOfDay();

        if (! $start->equalTo($start->startOfMonth())) {
            return null;
        }

        if (! $end->equalTo($start->endOfMonth()->startOfDay())) {
            return null;
        }

        return self::for($companyId, $category, $start);
    }

    public static function findExisting(
        int $companyId,
        string $regularPeriodKey,
        ?int $exceptPeriodId = null,
    ): ?PayrollPeriod {
        $query = PayrollPeriod::query()
            ->where('company_id', $companyId)
            ->where('regular_period_key', $regularPeriodKey);

        if ($exceptPeriodId !== null) {
            $query->whereKeyNot($exceptPeriodId);
        }

        return $query->first();
    }
}
