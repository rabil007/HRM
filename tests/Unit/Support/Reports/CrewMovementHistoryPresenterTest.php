<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewMovementCorrection;
use App\Support\Reports\CrewMovementHistoryPresenter;
use Carbon\CarbonImmutable;

test('it preserves repeated phases and calculates elapsed whole days in company timezone', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-25 00:30:00', 'Asia/Dubai'));
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $assignment = CrewAssignment::factory()
        ->forEmployee($employee)
        ->create([
            'rank_id' => $rank->id,
            'status' => CrewAssignmentStatus::Active,
            'started_at' => '2026-07-14 20:00:00',
            'planned_signoff_at' => '2026-08-31 00:00:00',
        ]);

    $phases = [
        [CrewPhaseCode::JoinStandby, 1, '2026-07-15 08:00:00', '2026-07-17 08:00:00', null],
        [CrewPhaseCode::Training, 2, '2026-07-17 08:00:00', '2026-07-19 08:00:00', ['provider' => 'ABC Training', 'course' => 'BOSIET']],
        [CrewPhaseCode::JoinStandby, 3, '2026-07-19 08:00:00', '2026-07-22 08:00:00', null],
        [CrewPhaseCode::Training, 4, '2026-07-22 08:00:00', '2026-07-24 08:00:00', ['provider' => 'XYZ Academy', 'course' => 'Refresher']],
    ];

    foreach ($phases as [$code, $sequence, $start, $end, $details]) {
        CrewAssignmentPhase::factory()
            ->forAssignment($assignment)
            ->create([
                'phase_code' => $code,
                'sequence' => $sequence,
                'status' => CrewPhaseStatus::Completed,
                'actual_start_at' => $start,
                'actual_end_at' => $end,
                'details' => $details,
            ]);
    }

    $onVessel = CrewAssignmentPhase::factory()
        ->forAssignment($assignment)
        ->create([
            'phase_code' => CrewPhaseCode::OnVessel,
            'sequence' => 5,
            'status' => CrewPhaseStatus::Active,
            'actual_start_at' => CarbonImmutable::parse('2026-07-24 20:30:00', 'UTC'),
            'actual_end_at' => null,
        ]);
    $assignment->update(['current_phase_id' => $onVessel->id]);

    $row = CrewMovementHistoryPresenter::toArray(
        $assignment->fresh([
            'company',
            'employee',
            'rank',
            'vessel',
            'client',
            'companyVisaType',
            'currentPhase',
            'phases',
        ]),
    );

    expect($row['join_standby']['periods'])->toHaveCount(2)
        ->and($row['join_standby']['total_days'])->toBe(5)
        ->and($row['training']['periods'])->toHaveCount(2)
        ->and($row['training']['total_days'])->toBe(4)
        ->and($row['training']['details'])->toBe([
            'ABC Training — BOSIET',
            'XYZ Academy — Refresher',
        ])
        ->and($row['on_vessel']['actual_join'])->toBe('2026-07-24')
        ->and($row['on_vessel']['actual_disembarkation'])->toBeNull()
        ->and($row['on_vessel']['total_days'])->toBe(1)
        ->and($row['planned_signoff'])->toBe('2026-08-31');

    CarbonImmutable::setTestNow();
});

test('it maps every lifecycle phase and keeps planned and actual dates separate', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();
    $assignment = CrewAssignment::factory()
        ->forEmployee($employee)
        ->completed()
        ->create([
            'planned_join_at' => '2026-01-10',
            'planned_signoff_at' => '2026-02-09',
            'planned_travel_at' => '2026-02-12',
            'started_at' => '2026-01-01',
            'closed_at' => '2026-02-15',
        ]);

    $phaseData = [
        [CrewPhaseCode::PreMobilisation, 1, '2026-01-01', '2026-01-03'],
        [CrewPhaseCode::TravelIn, 2, '2026-01-03', '2026-01-04'],
        [CrewPhaseCode::ReadyToJoin, 3, '2026-01-04', '2026-01-10'],
        [CrewPhaseCode::OnVessel, 4, '2026-01-10', '2026-02-10'],
        [CrewPhaseCode::DemobStandby, 5, '2026-02-10', '2026-02-12'],
        [CrewPhaseCode::HomeRedeploy, 6, '2026-02-12', '2026-02-15'],
    ];

    foreach ($phaseData as [$code, $sequence, $start, $end]) {
        CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
            'phase_code' => $code,
            'sequence' => $sequence,
            'status' => CrewPhaseStatus::Completed,
            'planned_start_at' => $code === CrewPhaseCode::TravelIn ? '2026-01-02' : null,
            'actual_start_at' => $start,
            'actual_end_at' => $end,
        ]);
    }

    $row = CrewMovementHistoryPresenter::toArray(
        $assignment->fresh([
            'company',
            'employee',
            'rank',
            'vessel',
            'client',
            'companyVisaType',
            'currentPhase',
            'phases',
        ]),
    );

    expect($row['planned_travel_in'])->toBe('2026-01-02')
        ->and($row['planned_travel_home'])->toBe('2026-02-12')
        ->and($row['pre_mobilisation']['from'])->toBe('2026-01-01')
        ->and($row['travel_in']['to'])->toBe('2026-01-04')
        ->and($row['ready_to_join']['total_days'])->toBe(6)
        ->and($row['on_vessel']['actual_join'])->toBe('2026-01-10')
        ->and($row['on_vessel']['actual_disembarkation'])->toBe('2026-02-10')
        ->and($row['on_vessel']['actual_disembarkation'])->not->toBe($row['planned_signoff'])
        ->and($row['demob_standby']['total_days'])->toBe(2)
        ->and($row['home_redeploy']['total_days'])->toBe(3)
        ->and($row['assignment_closed'])->toBe('2026-02-15')
        ->and($row['total_assignment_days'])->toBe(45);
});

test('planned only phases do not report actual duration', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();
    $assignment = CrewAssignment::factory()->forEmployee($employee)->create();
    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::PreMobilisation,
        'status' => CrewPhaseStatus::Planned,
        'planned_start_at' => '2026-07-01',
        'planned_end_at' => '2026-07-10',
        'actual_start_at' => null,
        'actual_end_at' => null,
    ]);

    $row = CrewMovementHistoryPresenter::toArray(
        $assignment->fresh([
            'company',
            'employee',
            'rank',
            'vessel',
            'client',
            'companyVisaType',
            'currentPhase',
            'phases',
        ]),
    );

    expect($row['pre_mobilisation']['total_days'])->toBeNull()
        ->and($row['pre_mobilisation']['total_days_label'])->toBe('Not recorded');
});

test('it exposes approved correction metadata without treating pending as official corrections', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();
    $assignment = CrewAssignment::factory()->forEmployee($employee)->create();

    CrewMovementCorrection::factory()
        ->forAssignment($assignment)
        ->approved()
        ->create([
            'requested_by' => $user->id,
            'decided_by' => $user->id,
            'decided_at' => '2026-07-10 12:00:00',
        ]);

    CrewMovementCorrection::factory()
        ->forAssignment($assignment)
        ->pending()
        ->create([
            'requested_by' => $user->id,
        ]);

    $row = CrewMovementHistoryPresenter::toArray(
        $assignment->fresh([
            'company',
            'employee',
            'rank',
            'vessel',
            'client',
            'companyVisaType',
            'currentPhase',
            'phases',
            'corrections',
        ]),
    );

    expect($row['has_corrections'])->toBeTrue()
        ->and($row['correction_count'])->toBe(1)
        ->and($row['last_corrected_at'])->toBe('2026-07-10')
        ->and($row['has_pending_corrections'])->toBeTrue();
});
