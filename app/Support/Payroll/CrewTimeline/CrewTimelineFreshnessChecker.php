<?php

namespace App\Support\Payroll\CrewTimeline;

use App\Models\CrewTimesheetPreparation;
use App\Models\PayrollPeriod;
use Illuminate\Validation\ValidationException;

final class CrewTimelineFreshnessChecker
{
    public const STALE_MESSAGE = 'Crew Assignment data changed after this preparation was created. Prepare a new version before continuing.';

    public const APPLY_STALE_MESSAGE = 'Crew Assignment data changed after this preparation was approved. Prepare and approve a new version before applying it to payroll.';

    public const TIMELINE_ADVANCED_MESSAGE = 'The active crew timeline has advanced beyond this preparation’s effective cutoff. Prepare a new version before continuing.';

    public const APPLY_TIMELINE_ADVANCED_MESSAGE = 'The active crew timeline has advanced beyond this preparation’s effective cutoff. Prepare and approve a new version before applying it to payroll.';

    public const APPLIED_LIVE_TIMELINE_ADVANCED_MESSAGE = 'Live crew timeline has advanced since this snapshot. This historical payroll snapshot remains unchanged.';

    public function __construct(
        private readonly CrewTimelinePhaseQuery $phaseQuery,
        private readonly CrewTimelineSourceHasher $sourceHasher,
        private readonly CrewTimelineSourceLocker $sourceLocker,
    ) {}

    public function currentHash(
        CrewTimesheetPreparation $preparation,
        PayrollPeriod $period,
    ): string {
        $effectiveEnd = $this->phaseQuery->effectiveEndDate($period, $preparation->cutoff_date);
        $phases = $this->phaseQuery->issuePhases($period, $effectiveEnd);
        $effectiveCutoff = $this->phaseQuery->resolveEffectiveCutoffDate($period, $preparation->cutoff_date, $phases);

        return $this->sourceHasher->hash($period, $preparation->cutoff_date, $phases, $effectiveCutoff);
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

    public function isSnapshotConsistent(
        CrewTimesheetPreparation $preparation,
        PayrollPeriod $period,
    ): bool {
        if ($preparation->source_hash === null || $preparation->source_hash === '') {
            return false;
        }

        $effectiveEnd = $this->phaseQuery->effectiveEndDate($period, $preparation->cutoff_date);
        $phases = $this->phaseQuery->issuePhases($period, $effectiveEnd);
        $hashWithPrepCutoff = $this->sourceHasher->hash(
            $period,
            $preparation->cutoff_date,
            $phases,
            $preparation->resolveEffectiveCutoffDate($period),
        );

        return hash_equals((string) $preparation->source_hash, $hashWithPrepCutoff);
    }

    public function liveTimelineAdvanced(
        CrewTimesheetPreparation $preparation,
        PayrollPeriod $period,
    ): bool {
        if ($this->isFresh($preparation, $period)) {
            return false;
        }

        return $this->isSnapshotConsistent($preparation, $period);
    }

    public function staleReason(
        CrewTimesheetPreparation $preparation,
        PayrollPeriod $period,
        bool $isApplyContext = false,
    ): ?string {
        if ($this->isFresh($preparation, $period)) {
            return null;
        }

        if ($this->isSnapshotConsistent($preparation, $period)) {
            return $isApplyContext ? self::APPLY_TIMELINE_ADVANCED_MESSAGE : self::TIMELINE_ADVANCED_MESSAGE;
        }

        return $isApplyContext ? self::APPLY_STALE_MESSAGE : self::STALE_MESSAGE;
    }

    public function assertFreshAfterLockingSource(
        CrewTimesheetPreparation $preparation,
        PayrollPeriod $period,
        int $companyId,
        ?string $message = null,
    ): void {
        $this->sourceLocker->lockAndReloadIssuePhases($period, $preparation, $companyId);
        $this->assertFresh($preparation, $period, $message);
    }

    public function assertFresh(
        CrewTimesheetPreparation $preparation,
        PayrollPeriod $period,
        ?string $message = null,
    ): void {
        if (! $this->isFresh($preparation, $period)) {
            $isApply = ($message === self::APPLY_STALE_MESSAGE);
            $reason = $message !== null && ! in_array($message, [self::STALE_MESSAGE, self::APPLY_STALE_MESSAGE], true)
                ? $message
                : $this->staleReason($preparation, $period, $isApply);

            throw ValidationException::withMessages([
                'preparation' => $reason ?? self::STALE_MESSAGE,
            ]);
        }
    }
}
