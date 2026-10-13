<?php

use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
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
        [CrewPhaseCode::PreMobilisation, CrewPhaseStatus::Completed, 1, '2026-10-01 08:00:00', '2026-10-03 08:00:00'],
        [CrewPhaseCode::TravelIn, CrewPhaseStatus::Completed, 2, '2026-10-03 08:00:00', '2026-10-04 08:00:00'],
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
    // P0, P1, and P6 dates are excluded even where they share a calendar day.
    expect($summary['recorded'])->toBeTrue()
        ->and($summary['future_actual_only'])->toBeFalse()
        ->and($summary['has_future_actual'])->toBeFalse()
        ->and($summary['vessel_service_days'])->toBe(4)
        ->and($summary['standby_service_days'])->toBe(3);

    CarbonImmutable::setTestNow();
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
        'standby_service_days' => null,
    ])
        ->and($summarize($unfinished))->toBe([
            'recorded' => false,
            'future_actual_only' => false,
            'has_future_actual' => false,
            'vessel_service_days' => null,
            'standby_service_days' => null,
        ])
        ->and($summarize($zeroLength)['recorded'])->toBeTrue()
        ->and($summarize($zeroLength)['vessel_service_days'])->toBe(0)
        ->and($summarize($zeroLength)['standby_service_days'])->toBe(1);

    CarbonImmutable::setTestNow();
});
