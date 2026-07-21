<?php

namespace App\Support\Payroll\Actions;

final class EnsureFuturePayrollPeriodsResult
{
    /**
     * @param  list<int>  $createdPeriodIds
     * @param  list<array{month: string, category: string}>  $createdSummary
     */
    public function __construct(
        public readonly int $createdCount = 0,
        public readonly int $skippedCount = 0,
        public readonly array $createdPeriodIds = [],
        public readonly array $createdSummary = [],
    ) {}
}
