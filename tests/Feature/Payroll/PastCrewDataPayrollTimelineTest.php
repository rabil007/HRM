<?php

use App\Enums\CrewTimesheetPayCategory;
use App\Models\CrewAssignment;
use App\Support\CrewMovements\Historical\HistoricalCrewAssignmentData;
use App\Support\CrewMovements\Historical\HistoricalCrewAssignmentService;
use App\Support\Payroll\CrewTimeline\PrepareCrewTimesheetTimeline;

test('past crew same-day handoffs map to payroll categories without duplicate calendar days', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['assignment']->forceDelete();

    $data = HistoricalCrewAssignmentData::fromArray([
        'employee_id' => $fixtures['employee']->id,
        'vessel_id' => $fixtures['vessel']->id,
        'rank_id' => $fixtures['rank']->id,
        'sign_on_standby_from' => '2026-07-01',
        'sign_on_standby_to' => '2026-07-05',
        'onsite_from' => '2026-07-05',
        'onsite_to' => '2026-07-20',
        'sign_off_standby_from' => '2026-07-20',
    ], (int) $fixtures['company']->id, 'Asia/Dubai');

    $assignment = app(HistoricalCrewAssignmentService::class)->create(
        $data,
        (int) $fixtures['user']->id,
    );

    expect($assignment)->toBeInstanceOf(CrewAssignment::class);

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $julyFive = payableLinesCovering($preparation->id, '2026-07-05');
    $julyTwenty = payableLinesCovering($preparation->id, '2026-07-20');
    $julyOne = payableLinesCovering($preparation->id, '2026-07-01');
    $julyTen = payableLinesCovering($preparation->id, '2026-07-10');
    $julyTwentyOne = payableLinesCovering($preparation->id, '2026-07-21');

    expect(overlapWarningExists($preparation->id))->toBeFalse()
        ->and($julyFive)->toHaveCount(1)
        ->and($julyFive->first()->pay_category)->toBe(CrewTimesheetPayCategory::Onsite)
        ->and($julyTwenty)->toHaveCount(1)
        // Onsite outranks Sign-Off Standby on the shared handoff calendar day.
        ->and($julyTwenty->first()->pay_category)->toBe(CrewTimesheetPayCategory::Onsite)
        ->and($julyOne->first()->pay_category)->toBe(CrewTimesheetPayCategory::SignOnStandby)
        ->and($julyTen->first()->pay_category)->toBe(CrewTimesheetPayCategory::Onsite)
        ->and($julyTwentyOne->first()->pay_category)->toBe(CrewTimesheetPayCategory::SignOffStandby);
});
