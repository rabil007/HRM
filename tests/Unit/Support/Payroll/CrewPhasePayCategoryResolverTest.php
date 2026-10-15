<?php

use App\Enums\CrewPhaseCode;
use App\Enums\CrewTimesheetPayCategory;
use App\Support\Payroll\CrewTimeline\CrewPhasePayCategoryResolver;

test('crew payroll mapping uses six-phase lifecycle categories', function (
    CrewPhaseCode $phase,
    CrewTimesheetPayCategory $expected,
) {
    expect((new CrewPhasePayCategoryResolver)->resolve($phase))->toBe($expected);
})->with([
    'p0 excluded' => [CrewPhaseCode::PreArrival, CrewTimesheetPayCategory::Excluded],
    'p1a sign-on standby' => [CrewPhaseCode::JoinStandby, CrewTimesheetPayCategory::SignOnStandby],
    'p1b sign-on standby' => [CrewPhaseCode::Training, CrewTimesheetPayCategory::SignOnStandby],
    'p2 onsite' => [CrewPhaseCode::OnVessel, CrewTimesheetPayCategory::Onsite],
    'p3 sign-off standby' => [CrewPhaseCode::DemobStandby, CrewTimesheetPayCategory::SignOffStandby],
    'p4 home excluded' => [CrewPhaseCode::HomeRedeploy, CrewTimesheetPayCategory::Excluded],
]);
