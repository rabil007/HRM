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
