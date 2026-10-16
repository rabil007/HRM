<?php

use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Support\Payroll\CrewTimeline\CrewPayableDateOwnership;
use App\Support\Reports\CrewMovementHistoryPayrollDays;
use App\Support\Reports\CrewMovementServiceDays;
use Carbon\CarbonImmutable;

test('has_future_actual is set when actual end is after company-local today', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-13 12:00:00', 'Asia/Dubai'));
    ['employee' => $employee] = makeCrewAssignmentFixtures();
    $assignment = CrewAssignment::factory()->forEmployee($employee)->active()->create();
    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-10-04 08:00:00',
        'actual_end_at' => '2026-10-20 18:00:00',
    ]);

    $summary = CrewMovementServiceDays::summarize(
        $assignment->phases,
        'Asia/Dubai',
        CarbonImmutable::parse('2026-10-13', 'Asia/Dubai'),
    );

    expect($summary['has_future_actual'])->toBeTrue()
        ->and($summary['vessel_service_days'])->toBe(10);

    CarbonImmutable::setTestNow();
});

test('excluded phases never take a service date from onsite or standby', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-13 12:00:00', 'Asia/Dubai'));
    ['employee' => $employee] = makeCrewAssignmentFixtures();
    $assignment = CrewAssignment::factory()->forEmployee($employee)->completed()->create();
    $today = CarbonImmutable::parse('2026-10-13', 'Asia/Dubai');

    foreach ([
        [CrewPhaseCode::PreArrival, CrewPhaseStatus::Completed, 1, '2026-10-01 08:00:00', '2026-10-03 08:00:00'],
        [CrewPhaseCode::JoinStandby, CrewPhaseStatus::Completed, 2, '2026-10-03 08:00:00', '2026-10-04 08:00:00'],
        [CrewPhaseCode::OnVessel, CrewPhaseStatus::Completed, 3, '2026-10-04 08:00:00', '2026-10-07 08:00:00'],
        [CrewPhaseCode::DemobStandby, CrewPhaseStatus::Completed, 4, '2026-10-07 08:00:00', '2026-10-10 08:00:00'],
        [CrewPhaseCode::HomeRedeploy, CrewPhaseStatus::Completed, 5, '2026-10-10 08:00:00', '2026-10-12 18:00:00'],
    ] as [$code, $status, $sequence, $start, $end]) {
        CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
            'phase_code' => $code,
            'sequence' => $sequence,
            'status' => $status,
            'actual_start_at' => $start,
            'actual_end_at' => $end,
        ]);
    }

    $summary = CrewMovementServiceDays::summarize(
        $assignment->phases()->orderBy('sequence')->get(),
        'Asia/Dubai',
        $today,
    );

    // 4–7 Oct stay onsite, including the 7 Oct handoff. 8–10 Oct stay sign-off standby.
    // 3 Oct is pre-joining standby after the P0 handoff. P0 and P4 Home dates are excluded.
    expect($summary['recorded'])->toBeTrue()
        ->and($summary['future_actual_only'])->toBeFalse()
        ->and($summary['has_future_actual'])->toBeFalse()
        ->and($summary['vessel_service_days'])->toBe(4)
        ->and($summary['pre_joining_standby_days'])->toBe(1)
        ->and($summary['demobilisation_standby_days'])->toBe(3)
        ->and($summary['standby_service_days'])->toBe(4);

    CarbonImmutable::setTestNow();
});

test('example A allocates join handoff day to on vessel and keeps hotel inclusive of standby span', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Dubai'));
    ['employee' => $employee] = makeCrewAssignmentFixtures();
    $assignment = CrewAssignment::factory()->forEmployee($employee)->completed()->create();

    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::JoinStandby,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-10-01 08:00:00',
        'actual_end_at' => '2026-10-05 08:00:00',
    ]);
    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-10-05 08:00:00',
        'actual_end_at' => '2026-10-10 18:00:00',
    ]);

    $summary = CrewMovementServiceDays::summarize(
        $assignment->phases()->orderBy('sequence')->get(),
        'Asia/Dubai',
        CarbonImmutable::parse('2026-10-20', 'Asia/Dubai'),
    );

    // Standby 1–5 Oct, joined 5 Oct → allocated standby 4 days; 5 Oct belongs to On Vessel.
    expect($summary['standby_service_days'])->toBe(4)
        ->and($summary['vessel_service_days'])->toBe(6);
});

test('example B allocates disembarkation day to on vessel for demobilisation standby', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Dubai'));
    ['employee' => $employee] = makeCrewAssignmentFixtures();
    $assignment = CrewAssignment::factory()->forEmployee($employee)->completed()->create();

    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-10-01 08:00:00',
        'actual_end_at' => '2026-10-10 08:00:00',
    ]);
    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::DemobStandby,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-10-10 08:00:00',
        'actual_end_at' => '2026-10-14 08:00:00',
    ]);
    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::HomeRedeploy,
        'sequence' => 3,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-10-14 08:00:00',
        'actual_end_at' => '2026-10-14 18:00:00',
    ]);

    $summary = CrewMovementServiceDays::summarize(
        $assignment->phases()->orderBy('sequence')->get(),
        'Asia/Dubai',
        CarbonImmutable::parse('2026-10-20', 'Asia/Dubai'),
    );

    // Disembarked 10 Oct, home 14 Oct → demob standby 4 days; 10 Oct stays On Vessel.
    expect($summary['standby_service_days'])->toBe(4)
        ->and($summary['vessel_service_days'])->toBe(10);
});

test('invalid or unfinished non-active phases are not counted as service', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-13 12:00:00', 'Asia/Dubai'));
    ['employee' => $employee] = makeCrewAssignmentFixtures();
    $today = CarbonImmutable::parse('2026-10-13', 'Asia/Dubai');

    $inverted = CrewAssignment::factory()->forEmployee($employee)->completed()->create();
    CrewAssignmentPhase::factory()->forAssignment($inverted)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-10-10 08:00:00',
        'actual_end_at' => '2026-10-04 08:00:00',
    ]);

    $unfinished = CrewAssignment::factory()->forEmployee($employee)->completed()->create();
    CrewAssignmentPhase::factory()->forAssignment($unfinished)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-10-04 08:00:00',
        'actual_end_at' => null,
    ]);

    $zeroLength = CrewAssignment::factory()->forEmployee($employee)->completed()->create();
    CrewAssignmentPhase::factory()->forAssignment($zeroLength)->create([
        'phase_code' => CrewPhaseCode::JoinStandby,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-10-04 08:00:00',
        'actual_end_at' => '2026-10-04 08:00:00',
    ]);

    $summarize = fn (CrewAssignment $assignment): array => CrewMovementServiceDays::summarize(
        $assignment->phases()->get(),
        'Asia/Dubai',
        $today,
    );

    expect($summarize($inverted))->toBe([
        'recorded' => false,
        'future_actual_only' => false,
        'has_future_actual' => false,
        'vessel_service_days' => null,
        'pre_joining_standby_days' => null,
        'demobilisation_standby_days' => null,
        'standby_service_days' => null,
    ])
        ->and($summarize($unfinished))->toBe([
            'recorded' => false,
            'future_actual_only' => false,
            'has_future_actual' => false,
            'vessel_service_days' => null,
            'pre_joining_standby_days' => null,
            'demobilisation_standby_days' => null,
            'standby_service_days' => null,
        ])
        ->and($summarize($zeroLength)['recorded'])->toBeTrue()
        ->and($summarize($zeroLength)['vessel_service_days'])->toBe(0)
        ->and($summarize($zeroLength)['pre_joining_standby_days'])->toBe(1)
        ->and($summarize($zeroLength)['demobilisation_standby_days'])->toBe(0)
        ->and($summarize($zeroLength)['standby_service_days'])->toBe(1);

    CarbonImmutable::setTestNow();
});

test('pre-joining standby gives the vessel join date to onsite and keeps four standby days', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-15 12:00:00', 'Asia/Dubai'));
    ['employee' => $employee] = makeCrewAssignmentFixtures();
    $assignment = CrewAssignment::factory()->forEmployee($employee)->completed()->create();

    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::JoinStandby,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-10-01 08:00:00',
        'actual_end_at' => '2026-10-05 08:00:00',
    ]);
    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-10-05 08:00:00',
        'actual_end_at' => '2026-10-06 18:00:00',
    ]);

    $summary = CrewMovementServiceDays::summarize(
        $assignment->phases()->orderBy('sequence')->get(),
        'Asia/Dubai',
        CarbonImmutable::parse('2026-10-15', 'Asia/Dubai'),
    );

    expect($summary['pre_joining_standby_days'])->toBe(4)
        ->and($summary['demobilisation_standby_days'])->toBe(0)
        ->and($summary['standby_service_days'])->toBe(4)
        ->and($summary['vessel_service_days'])->toBe(2);

    CarbonImmutable::setTestNow();
});

test('demobilisation standby keeps the return-home date and gives disembarkation to the vessel', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Dubai'));
    ['employee' => $employee] = makeCrewAssignmentFixtures();
    $assignment = CrewAssignment::factory()->forEmployee($employee)->completed()->create();

    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-10-08 08:00:00',
        'actual_end_at' => '2026-10-10 08:00:00',
    ]);
    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::DemobStandby,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-10-10 08:00:00',
        'actual_end_at' => '2026-10-14 12:00:00',
    ]);
    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::HomeRedeploy,
        'sequence' => 3,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-10-14 12:00:00',
        'actual_end_at' => '2026-10-14 18:00:00',
    ]);

    $summary = CrewMovementServiceDays::summarize(
        $assignment->phases()->orderBy('sequence')->get(),
        'Asia/Dubai',
        CarbonImmutable::parse('2026-10-20', 'Asia/Dubai'),
    );

    expect($summary['vessel_service_days'])->toBe(3)
        ->and($summary['pre_joining_standby_days'])->toBe(0)
        ->and($summary['demobilisation_standby_days'])->toBe(4)
        ->and($summary['standby_service_days'])->toBe(4);

    CarbonImmutable::setTestNow();
});

test('a same-day vessel transfer is one onsite day across linked assignments', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Dubai'));
    ['employee' => $employee] = makeCrewAssignmentFixtures();
    $source = CrewAssignment::factory()->forEmployee($employee)->completed()->create();
    $sourcePhase = CrewAssignmentPhase::factory()->forAssignment($source)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-10-01 08:00:00',
        'actual_end_at' => '2026-10-10 08:00:00',
    ]);
    $destination = CrewAssignment::factory()->forEmployee($employee)->active()->create([
        'source' => 'vessel_transfer',
        'previous_assignment_id' => $source->id,
    ]);
    $destinationPhase = CrewAssignmentPhase::factory()->forAssignment($destination)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => '2026-10-10 08:00:00',
        'actual_end_at' => null,
    ]);

    $phases = CrewAssignmentPhase::query()
        ->whereIn('id', [$sourcePhase->id, $destinationPhase->id])
        ->get();
    $today = CarbonImmutable::parse('2026-10-20', 'Asia/Dubai');

    $sourceSummary = CrewMovementServiceDays::summarize(
        $phases,
        'Asia/Dubai',
        $today,
        employeeId: (int) $employee->id,
        companyId: (int) $source->company_id,
        assignmentId: (int) $source->id,
    );
    $destinationSummary = CrewMovementServiceDays::summarize(
        $phases,
        'Asia/Dubai',
        $today,
        employeeId: (int) $employee->id,
        companyId: (int) $destination->company_id,
        assignmentId: (int) $destination->id,
    );

    expect($sourceSummary['vessel_service_days'] + $destinationSummary['vessel_service_days'])->toBe(20)
        ->and($sourceSummary['vessel_service_days'])->toBe(10)
        ->and($destinationSummary['vessel_service_days'])->toBe(10);

    CarbonImmutable::setTestNow();
});

test('only active and completed phases contribute payable service days', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Dubai'));
    ['employee' => $employee] = makeCrewAssignmentFixtures();
    $today = CarbonImmutable::parse('2026-10-20', 'Asia/Dubai');

    $completed = CrewAssignment::factory()->forEmployee($employee)->completed()->create();
    CrewAssignmentPhase::factory()->forAssignment($completed)->create([
        'phase_code' => CrewPhaseCode::JoinStandby,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-10-01 08:00:00',
        'actual_end_at' => '2026-10-05 08:00:00',
    ]);
    CrewAssignmentPhase::factory()->forAssignment($completed)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-10-05 08:00:00',
        'actual_end_at' => '2026-10-08 18:00:00',
    ]);

    $active = CrewAssignment::factory()->forEmployee($employee)->active()->create();
    CrewAssignmentPhase::factory()->forAssignment($active)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => '2026-10-10 08:00:00',
        'actual_end_at' => null,
    ]);

    $plannedOnly = CrewAssignment::factory()->forEmployee($employee)->active()->create();
    CrewAssignmentPhase::factory()->forAssignment($plannedOnly)->create([
        'phase_code' => CrewPhaseCode::JoinStandby,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Planned,
        'actual_start_at' => '2026-10-01 08:00:00',
        'actual_end_at' => '2026-10-05 08:00:00',
        'planned_start_at' => '2026-10-01 08:00:00',
        'planned_end_at' => '2026-10-05 08:00:00',
    ]);

    $cancelledPhase = CrewAssignment::factory()->forEmployee($employee)->active()->create();
    CrewAssignmentPhase::factory()->forAssignment($cancelledPhase)->create([
        'phase_code' => CrewPhaseCode::JoinStandby,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Cancelled,
        'actual_start_at' => '2026-10-01 08:00:00',
        'actual_end_at' => '2026-10-05 08:00:00',
    ]);

    $corrected = CrewAssignment::factory()->forEmployee($employee)->active()->create();
    CrewAssignmentPhase::factory()->forAssignment($corrected)->create([
        'phase_code' => CrewPhaseCode::JoinStandby,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Corrected,
        'actual_start_at' => '2026-10-01 08:00:00',
        'actual_end_at' => '2026-10-05 08:00:00',
    ]);

    $missingDates = CrewAssignment::factory()->forEmployee($employee)->active()->create();
    CrewAssignmentPhase::factory()->forAssignment($missingDates)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => null,
        'actual_end_at' => null,
    ]);

    $futureOnly = CrewAssignment::factory()->forEmployee($employee)->completed()->create();
    CrewAssignmentPhase::factory()->forAssignment($futureOnly)->create([
        'phase_code' => CrewPhaseCode::JoinStandby,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-10-25 08:00:00',
        'actual_end_at' => '2026-10-28 18:00:00',
    ]);

    $cancelledAssignmentWithCompletedStandby = CrewAssignment::factory()
        ->forEmployee($employee)
        ->cancelled()
        ->create();
    CrewAssignmentPhase::factory()->forAssignment($cancelledAssignmentWithCompletedStandby)->create([
        'phase_code' => CrewPhaseCode::JoinStandby,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-10-01 08:00:00',
        'actual_end_at' => '2026-10-05 08:00:00',
    ]);

    $summarize = fn (CrewAssignment $assignment): array => CrewMovementServiceDays::summarize(
        $assignment->phases()->get(),
        'Asia/Dubai',
        $today,
        employeeId: (int) $employee->id,
        companyId: (int) $assignment->company_id,
        assignmentId: (int) $assignment->id,
    );

    expect($summarize($completed)['standby_service_days'])->toBe(4)
        ->and($summarize($completed)['vessel_service_days'])->toBe(4)
        ->and($summarize($active)['vessel_service_days'])->toBe(11)
        ->and($summarize($plannedOnly)['recorded'])->toBeFalse()
        ->and($summarize($cancelledPhase)['recorded'])->toBeFalse()
        ->and($summarize($corrected)['recorded'])->toBeFalse()
        ->and($summarize($missingDates)['recorded'])->toBeFalse()
        ->and($summarize($futureOnly)['future_actual_only'])->toBeTrue()
        ->and($summarize($futureOnly)['standby_service_days'])->toBe(0)
        ->and($summarize($cancelledAssignmentWithCompletedStandby)['standby_service_days'])->toBe(5);

    CarbonImmutable::setTestNow();
});

test('three-assignment vessel transfer chain allocates each shared day once', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Dubai'));
    ['employee' => $employee] = makeCrewAssignmentFixtures();

    $assignmentA = CrewAssignment::factory()->forEmployee($employee)->completed()->create();
    $phaseA = CrewAssignmentPhase::factory()->forAssignment($assignmentA)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-10-01 08:00:00',
        'actual_end_at' => '2026-10-10 08:00:00',
    ]);

    $assignmentB = CrewAssignment::factory()->forEmployee($employee)->completed()->create([
        'source' => 'vessel_transfer',
        'previous_assignment_id' => $assignmentA->id,
    ]);
    $phaseB = CrewAssignmentPhase::factory()->forAssignment($assignmentB)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-10-10 08:00:00',
        'actual_end_at' => '2026-10-15 08:00:00',
    ]);

    $assignmentC = CrewAssignment::factory()->forEmployee($employee)->active()->create([
        'source' => 'vessel_transfer',
        'previous_assignment_id' => $assignmentB->id,
    ]);
    $phaseC = CrewAssignmentPhase::factory()->forAssignment($assignmentC)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => '2026-10-15 08:00:00',
        'actual_end_at' => null,
    ]);

    $allPhases = CrewAssignmentPhase::query()
        ->whereIn('id', [$phaseA->id, $phaseB->id, $phaseC->id])
        ->get();
    $today = CarbonImmutable::parse('2026-10-20', 'Asia/Dubai');
    $companyId = (int) $assignmentA->company_id;
    $employeeId = (int) $employee->id;

    $summaryA = CrewMovementServiceDays::summarize($allPhases, 'Asia/Dubai', $today, $employeeId, $companyId, (int) $assignmentA->id);
    $summaryB = CrewMovementServiceDays::summarize($allPhases, 'Asia/Dubai', $today, $employeeId, $companyId, (int) $assignmentB->id);
    $summaryC = CrewMovementServiceDays::summarize($allPhases, 'Asia/Dubai', $today, $employeeId, $companyId, (int) $assignmentC->id);

    expect($summaryA['vessel_service_days'] + $summaryB['vessel_service_days'] + $summaryC['vessel_service_days'])->toBe(20)
        ->and($summaryA['vessel_service_days'])->toBe(10)
        ->and($summaryB['vessel_service_days'])->toBe(5)
        ->and($summaryC['vessel_service_days'])->toBe(5);

    CarbonImmutable::setTestNow();
});

test('standby breakdown sums pre-joining and demobilisation independently', function (array $phases, array $expected) {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Dubai'));
    ['employee' => $employee] = makeCrewAssignmentFixtures();
    $assignment = CrewAssignment::factory()->forEmployee($employee)->completed()->create();
    $today = CarbonImmutable::parse('2026-10-20', 'Asia/Dubai');

    foreach ($phases as $index => [$code, $start, $end]) {
        CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
            'phase_code' => $code,
            'sequence' => $index + 1,
            'status' => CrewPhaseStatus::Completed,
            'actual_start_at' => $start,
            'actual_end_at' => $end,
        ]);
    }

    $summary = CrewMovementServiceDays::summarize(
        $assignment->phases()->orderBy('sequence')->get(),
        'Asia/Dubai',
        $today,
    );

    expect($summary['pre_joining_standby_days'])->toBe($expected['pre_joining'])
        ->and($summary['demobilisation_standby_days'])->toBe($expected['demobilisation'])
        ->and($summary['standby_service_days'])->toBe($expected['total'])
        ->and($summary['standby_service_days'])->toBe(
            ($summary['pre_joining_standby_days'] ?? 0) + ($summary['demobilisation_standby_days'] ?? 0),
        );

    CarbonImmutable::setTestNow();
})->with([
    'only pre-joining standby' => [
        [
            [CrewPhaseCode::JoinStandby, '2026-10-02 08:00:00', '2026-10-04 08:00:00'],
            [CrewPhaseCode::OnVessel, '2026-10-04 08:00:00', '2026-10-07 08:00:00'],
        ],
        ['pre_joining' => 2, 'demobilisation' => 0, 'total' => 2],
    ],
    'only demobilisation standby' => [
        [
            [CrewPhaseCode::OnVessel, '2026-10-04 08:00:00', '2026-10-07 08:00:00'],
            [CrewPhaseCode::DemobStandby, '2026-10-07 08:00:00', '2026-10-09 08:00:00'],
        ],
        ['pre_joining' => 0, 'demobilisation' => 2, 'total' => 2],
    ],
    'both standby phases' => [
        [
            [CrewPhaseCode::JoinStandby, '2026-10-02 08:00:00', '2026-10-04 08:00:00'],
            [CrewPhaseCode::OnVessel, '2026-10-04 08:00:00', '2026-10-07 08:00:00'],
            [CrewPhaseCode::DemobStandby, '2026-10-07 08:00:00', '2026-10-13 08:00:00'],
        ],
        ['pre_joining' => 2, 'demobilisation' => 6, 'total' => 8],
    ],
    'neither standby phase' => [
        [
            [CrewPhaseCode::OnVessel, '2026-10-04 08:00:00', '2026-10-07 08:00:00'],
            [CrewPhaseCode::HomeRedeploy, '2026-10-07 08:00:00', '2026-10-08 08:00:00'],
        ],
        ['pre_joining' => 0, 'demobilisation' => 0, 'total' => 0],
    ],
    'single-day pre-joining standby' => [
        [
            [CrewPhaseCode::JoinStandby, '2026-10-04 08:00:00', '2026-10-04 18:00:00'],
        ],
        ['pre_joining' => 1, 'demobilisation' => 0, 'total' => 1],
    ],
    'half-day demobilisation standby counts as one day' => [
        [
            [CrewPhaseCode::DemobStandby, '2026-10-10 08:00:00', '2026-10-10 12:00:00'],
        ],
        ['pre_joining' => 0, 'demobilisation' => 1, 'total' => 1],
    ],
    'skipped pre-joining with direct vessel start' => [
        [
            [CrewPhaseCode::OnVessel, '2026-10-04 08:00:00', '2026-10-07 08:00:00'],
            [CrewPhaseCode::DemobStandby, '2026-10-07 08:00:00', '2026-10-08 08:00:00'],
            [CrewPhaseCode::HomeRedeploy, '2026-10-08 08:00:00', '2026-10-09 08:00:00'],
        ],
        ['pre_joining' => 0, 'demobilisation' => 1, 'total' => 1],
    ],
    'training contributes to pre-joining standby bucket' => [
        [
            [CrewPhaseCode::JoinStandby, '2026-10-01 08:00:00', '2026-10-02 08:00:00'],
            [CrewPhaseCode::Training, '2026-10-02 08:00:00', '2026-10-04 08:00:00'],
            [CrewPhaseCode::OnVessel, '2026-10-04 08:00:00', '2026-10-05 08:00:00'],
        ],
        // 1–3 Oct pre-joining (handoff 4 Oct stays vessel); demob absent = 0; total 3.
        ['pre_joining' => 3, 'demobilisation' => 0, 'total' => 3],
    ],
]);

test('allocated dates by phase follow existing winners without changing service totals', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Dubai'));
    ['employee' => $employee] = makeCrewAssignmentFixtures();
    $assignment = CrewAssignment::factory()->forEmployee($employee)->completed()->create();

    $preJoin = CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::JoinStandby,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-10-03 08:00:00',
        'actual_end_at' => '2026-10-07 08:00:00',
    ]);
    $vessel = CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-10-07 08:00:00',
        'actual_end_at' => '2026-10-10 18:00:00',
    ]);
    $demob = CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::DemobStandby,
        'sequence' => 3,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-10-10 08:00:00',
        'actual_end_at' => '2026-10-14 18:00:00',
    ]);
    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::HomeRedeploy,
        'sequence' => 4,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-10-14 12:00:00',
        'actual_end_at' => '2026-10-14 18:00:00',
    ]);

    $phases = $assignment->phases()->get();
    $today = CarbonImmutable::parse('2026-10-20 12:00:00', 'Asia/Dubai');
    $summary = CrewMovementServiceDays::summarize($phases, 'Asia/Dubai', $today, (int) $employee->id, (int) $assignment->company_id, (int) $assignment->id);
    $analysis = CrewPayableDateOwnership::analyze($phases, 'Asia/Dubai', $today, true, (int) $employee->id, (int) $assignment->company_id, (int) $assignment->id);
    $payroll = CrewMovementHistoryPayrollDays::summarize($phases, 'Asia/Dubai', $today, (int) $employee->id, (int) $assignment->company_id, (int) $assignment->id);

    expect($analysis['allocated_dates_by_phase'][$preJoin->id])->toBe([
        '2026-10-03',
        '2026-10-04',
        '2026-10-05',
        '2026-10-06',
    ])
        ->and($analysis['allocated_dates_by_phase'][$vessel->id])->toBe([
            '2026-10-07',
            '2026-10-08',
            '2026-10-09',
            '2026-10-10',
        ])
        ->and($analysis['allocated_dates_by_phase'][$demob->id])->toBe([
            '2026-10-11',
            '2026-10-12',
            '2026-10-13',
            '2026-10-14',
        ])
        ->and($analysis['allocated_days_by_phase'][$preJoin->id])->toBe(4)
        ->and($analysis['allocated_days_by_phase'][$vessel->id])->toBe(4)
        ->and($analysis['allocated_days_by_phase'][$demob->id])->toBe(4)
        ->and($summary['pre_joining_standby_days'])->toBe(4)
        ->and($summary['demobilisation_standby_days'])->toBe(4)
        ->and($summary['vessel_service_days'])->toBe(4)
        ->and($summary['standby_service_days'])->toBe(8)
        ->and($payroll['sign_on_standby']['total_days'])->toBe(4)
        ->and($payroll['onsite']['total_days'])->toBe(4)
        ->and($payroll['sign_off_standby']['total_days'])->toBe(4)
        ->and($payroll['total_days'])->toBe(12)
        ->and($preJoin->fresh()->actual_end_at?->timezone('Asia/Dubai')->format('Y-m-d H:i:s'))->toBe('2026-10-07 08:00:00');

    CarbonImmutable::setTestNow();
});
