<?php

use App\Enums\CrewPhaseCode;
use App\Enums\CrewTimesheetPayCategory;
use App\Support\Payroll\CrewTimeline\CrewPhasePayCategoryResolver;

test('crew payroll mapping uses phase actuals categories without changing p0 p1 exclusion', function (
    CrewPhaseCode $phase,
    CrewTimesheetPayCategory $expected,
) {
    expect((new CrewPhasePayCategoryResolver)->resolve($phase))->toBe($expected);
})->with([
    'p0 excluded' => [CrewPhaseCode::PreMobilisation, CrewTimesheetPayCategory::Excluded],
    'p1 excluded' => [CrewPhaseCode::TravelIn, CrewTimesheetPayCategory::Excluded],
    'p2a sign-on standby' => [CrewPhaseCode::JoinStandby, CrewTimesheetPayCategory::SignOnStandby],
    'p2b sign-on standby' => [CrewPhaseCode::Training, CrewTimesheetPayCategory::SignOnStandby],
    'p3 sign-on standby' => [CrewPhaseCode::ReadyToJoin, CrewTimesheetPayCategory::SignOnStandby],
    'p4 onsite' => [CrewPhaseCode::OnVessel, CrewTimesheetPayCategory::Onsite],
    'p5 sign-off standby' => [CrewPhaseCode::DemobStandby, CrewTimesheetPayCategory::SignOffStandby],
    'p6 excluded' => [CrewPhaseCode::HomeRedeploy, CrewTimesheetPayCategory::Excluded],
]);
