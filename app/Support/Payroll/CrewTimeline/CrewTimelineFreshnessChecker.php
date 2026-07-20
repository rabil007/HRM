<?php

namespace App\Support\Payroll\CrewTimeline;

use App\Models\CrewTimesheetPreparation;
use App\Models\PayrollPeriod;
use Illuminate\Validation\ValidationException;

final class CrewTimelineFreshnessChecker
{
    public const STALE_MESSAGE = 'The Crew Operations timeline changed after this preparation was created. Prepare a new version before continuing.';

    public function __construct(
        private readonly CrewTimelinePhaseQuery $phaseQuery,
        private readonly CrewTimelineSourceHasher $sourceHasher,
    ) {}

    public function currentHash(
        CrewTimesheetPreparation $preparation,
        PayrollPeriod $period,
    ): string {
        $effectiveEnd = $this->phaseQuery->effectiveEndDate($period, $preparation->cutoff_date);
        $phases = $this->phaseQuery->overlappingPhases($period, $effectiveEnd);

        return $this->sourceHasher->hash($period, $preparation->cutoff_date, $phases);
    }

    public function isFresh(
        CrewTimesheetPreparation $preparation,
        PayrollPeriod $period,
    ): bool {
        if ($preparation->source_hash === null || $preparation->source_hash === '') {
            return false;
        }

        return hash_equals(
            $preparation->source_hash,
            $this->currentHash($preparation, $period),
        );
    }

    public function assertFresh(
        CrewTimesheetPreparation $preparation,
        PayrollPeriod $period,
    ): void {
        if (! $this->isFresh($preparation, $period)) {
            throw ValidationException::withMessages([
                'preparation' => self::STALE_MESSAGE,
            ]);
        }
    }
}
