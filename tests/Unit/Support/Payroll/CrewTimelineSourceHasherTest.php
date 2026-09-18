<?php

use App\Support\Payroll\CrewTimeline\CrewTimelineSourceHasher;
use Illuminate\Support\Facades\DB;

test('hashLockedSource performs no database queries', function () {
    $fixtures = makeDailyCrewTimelineFixtures();

    DB::transaction(function () use ($fixtures): void {
        $locker = app(\App\Support\Payroll\CrewTimeline\CrewTimelineSourceLocker::class);
        $hasher = app(CrewTimelineSourceHasher::class);
        $phaseQuery = app(\App\Support\Payroll\CrewTimeline\CrewTimelinePhaseQuery::class);

        $preparation = app(\App\Support\Payroll\CrewTimeline\PrepareCrewTimesheetTimeline::class)->handle(
            $fixtures['period'],
            (int) $fixtures['company']->id,
            (int) $fixtures['user']->id,
        );

        $lockedSource = $locker->lockSource(
            $fixtures['period'],
            $preparation,
            (int) $fixtures['company']->id,
        );
        $effectiveCutoff = $phaseQuery->resolveEffectiveCutoffDate(
            $fixtures['period'],
            $preparation->cutoff_date,
            $lockedSource->phases,
        );

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $hash = $hasher->hashLockedSource(
            $fixtures['period'],
            $preparation->cutoff_date,
            $lockedSource,
            $effectiveCutoff,
        );

        expect($hash)->toBeString()
            ->and($queries)->toBeEmpty();
    });
});

test('hashLockedSource matches ordinary hash for unchanged representative locked source', function () {
    $fixtures = makeDailyCrewTimelineFixtures();

    DB::transaction(function () use ($fixtures): void {
        $checker = app(\App\Support\Payroll\CrewTimeline\CrewTimelineFreshnessChecker::class);
        $locker = app(\App\Support\Payroll\CrewTimeline\CrewTimelineSourceLocker::class);
        $hasher = app(CrewTimelineSourceHasher::class);
        $phaseQuery = app(\App\Support\Payroll\CrewTimeline\CrewTimelinePhaseQuery::class);

        $preparation = app(\App\Support\Payroll\CrewTimeline\PrepareCrewTimesheetTimeline::class)->handle(
            $fixtures['period'],
            (int) $fixtures['company']->id,
            (int) $fixtures['user']->id,
        );

        $ordinaryHash = $checker->currentHash($preparation, $fixtures['period']);
        $lockedSource = $locker->lockSource(
            $fixtures['period'],
            $preparation,
            (int) $fixtures['company']->id,
        );
        $effectiveCutoff = $phaseQuery->resolveEffectiveCutoffDate(
            $fixtures['period'],
            $preparation->cutoff_date,
            $lockedSource->phases,
        );

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $lockedHash = $hasher->hashLockedSource(
            $fixtures['period'],
            $preparation->cutoff_date,
            $lockedSource,
            $effectiveCutoff,
        );

        expect($lockedHash)->toBe($ordinaryHash)
            ->and($queries)->toBeEmpty();
    });
});
