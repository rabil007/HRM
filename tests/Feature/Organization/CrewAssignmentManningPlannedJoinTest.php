<?php

use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\Employee;
use App\Models\VesselManning;
use App\Support\CrewOperations\CrewAssignmentManningQuery;
use Carbon\CarbonImmutable;

function makePlannedJoinManningAssignment(
    int $companyId,
    Employee $employee,
    int $rankId,
    int $vesselId,
    CrewPhaseCode $phaseCode,
    string $plannedJoinAt,
): CrewAssignment {
    $started = CarbonImmutable::parse('2026-07-01 08:00:00', 'Asia/Dubai');

    $assignment = CrewAssignment::query()->create([
        'company_id' => $companyId,
        'assignment_no' => 'CA-PJ-'.fake()->unique()->numerify('######'),
        'employee_id' => $employee->id,
        'rank_id' => $rankId,
        'vessel_id' => $vesselId,
        'status' => 'active',
        'started_at' => $started,
        'planned_join_at' => CarbonImmutable::parse($plannedJoinAt, 'Asia/Dubai'),
        'source' => 'manual',
    ]);

    $phase = CrewAssignmentPhase::query()->create([
        'company_id' => $companyId,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => $phaseCode,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => $started,
    ]);

    $assignment->update(['current_phase_id' => $phase->id]);

    return $assignment->fresh(['currentPhase']);
}

function manningVesselRankKey(int $vesselId, int $rankId): string
{
    return $vesselId.'|'.$rankId;
}

test('planned join forecast counts modern pre-vessel phases with future planned join', function (
    CrewPhaseCode $phaseCode,
) {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Planned Join Vessel', $company);

    makePlannedJoinManningAssignment(
        $company->id,
        $employee,
        $rank->id,
        $vessel->id,
        $phaseCode,
        '2026-08-01 10:00:00',
    );

    $today = CarbonImmutable::parse('2026-07-15', 'Asia/Dubai');
    $counts = CrewAssignmentManningQuery::plannedJoinCountsByVesselRank($company->id, $today);
    $key = manningVesselRankKey($vessel->id, $rank->id);

    expect($counts[$key] ?? 0)->toBe(1);
})->with([
    'p0 pre-mobilisation' => [CrewPhaseCode::PreMobilisation],
    'p2a join standby' => [CrewPhaseCode::JoinStandby],
    'p2b training' => [CrewPhaseCode::Training],
    'legacy p1 travel in' => [CrewPhaseCode::TravelIn],
    'legacy p3 ready to join' => [CrewPhaseCode::ReadyToJoin],
]);

test('planned join forecast excludes onboard and post-vessel phases even with future planned join', function (
    CrewPhaseCode $phaseCode,
) {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Excluded Planned Join Vessel', $company);

    makePlannedJoinManningAssignment(
        $company->id,
        $employee,
        $rank->id,
        $vessel->id,
        $phaseCode,
        '2026-08-01 10:00:00',
    );

    $today = CarbonImmutable::parse('2026-07-15', 'Asia/Dubai');
    $counts = CrewAssignmentManningQuery::plannedJoinCountsByVesselRank($company->id, $today);
    $key = manningVesselRankKey($vessel->id, $rank->id);

    expect($counts[$key] ?? 0)->toBe(0);
})->with([
    'p4 on vessel' => [CrewPhaseCode::OnVessel],
    'p5 demob standby' => [CrewPhaseCode::DemobStandby],
    'p6 home redeploy' => [CrewPhaseCode::HomeRedeploy],
]);

test('onboard manning counts only active p4 assignments', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Onboard Manning Vessel', $company);

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'required_count' => 2,
    ]);

    makeActiveOnVesselAssignment($company, $employee, $rank, $vessel, [
        'planned_join_at' => CarbonImmutable::parse('2026-08-01 10:00:00', 'Asia/Dubai'),
    ]);

    makePlannedJoinManningAssignment(
        $company->id,
        Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id]),
        $rank->id,
        $vessel->id,
        CrewPhaseCode::JoinStandby,
        '2026-08-05 10:00:00',
    );

    $today = CarbonImmutable::parse('2026-07-15', 'Asia/Dubai');
    $key = manningVesselRankKey($vessel->id, $rank->id);

    expect(CrewAssignmentManningQuery::onboardCountsByVesselRank($company->id)[$key] ?? 0)->toBe(1)
        ->and(CrewAssignmentManningQuery::plannedJoinCountsByVesselRank($company->id, $today)[$key] ?? 0)->toBe(1)
        ->and(CrewAssignmentManningQuery::forCompany($company->id, $today)['items'][0]['actual_count'])->toBe(1);
});
