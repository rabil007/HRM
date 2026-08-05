<?php

namespace App\Support\Payroll;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Splits a movement date range across a payroll period boundary (preview helpers).
 *
 * @phpstan-type SplitPortion array{from_date: string, to_date: string, days: int, classification: 'prior'|'current'}
 */
final class SplitCrewMovementRangeAcrossPeriod
{
    /**
     * @return array{prior: ?SplitPortion, current: ?SplitPortion}
     */
    public function handle(
        CarbonInterface|string $fromDate,
        CarbonInterface|string $toDate,
        CarbonInterface|string $periodStart,
        CarbonInterface|string $periodEnd,
    ): array {
        $from = CarbonImmutable::parse($fromDate)->startOfDay();
        $to = CarbonImmutable::parse($toDate)->startOfDay();
        $start = CarbonImmutable::parse($periodStart)->startOfDay();
        $end = CarbonImmutable::parse($periodEnd)->startOfDay();

        if ($from->greaterThan($to)) {
            return ['prior' => null, 'current' => null];
        }

        if ($from->greaterThan($end)) {
            return ['prior' => null, 'current' => null];
        }

        $prior = null;
        $current = null;

        if ($from->lessThan($start)) {
            $priorTo = $to->lessThan($start) ? $to : $start->subDay();

            if ($from->lessThanOrEqualTo($priorTo)) {
                $prior = $this->portion($from, $priorTo, 'prior');
            }
        }

        $currentFrom = $from->greaterThanOrEqualTo($start) ? $from : $start;
        $currentTo = $to->lessThanOrEqualTo($end) ? $to : $end;

        if ($currentFrom->lessThanOrEqualTo($currentTo) && $currentFrom->greaterThanOrEqualTo($start) && $currentTo->lessThanOrEqualTo($end)) {
            $current = $this->portion($currentFrom, $currentTo, 'current');
        }

        return ['prior' => $prior, 'current' => $current];
    }

    /**
     * @return SplitPortion
     */
    private function portion(CarbonImmutable $from, CarbonImmutable $to, string $classification): array
    {
        return [
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'days' => $from->diffInDays($to) + 1,
            'classification' => $classification,
        ];
    }
}
