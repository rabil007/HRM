<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Support\CrewMovements\CrewDateProvenance;
use App\Support\Reports\CrewMovementHistoryPresenter;

test('vessel transfer planned join copied from actual is excluded from planned join display', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();

    $assignment = CrewAssignment::factory()
        ->forEmployee($employee)
        ->create([
            'source' => 'vessel_transfer',
            'status' => CrewAssignmentStatus::Active,
            'started_at' => '2026-08-12 06:42:00',
            'planned_join_at' => '2026-08-12 06:42:00',
            'planned_signoff_at' => null,
        ]);

    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => '2026-08-12 06:42:00',
        'planned_start_at' => null,
    ]);

    $planned = CrewDateProvenance::plannedJoin(
        $assignment->fresh(['phases']),
        'Asia/Dubai',
    );

    expect($planned['origin'])->toBe(CrewDateProvenance::MovementActual)
        ->and($planned['value'])->toBeNull();

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

    expect($row['planned_join'])->toBeNull()
        ->and($row['planned_join_origin'])->toBe('movement_actual')
        ->and($row['on_vessel']['actual_join'])->toBe('2026-08-12')
        ->and($row['on_vessel']['actual_disembarkation'])->toBeNull();
});

test('redeployment planned join copied from actual is excluded from planned join display', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();

    $assignment = CrewAssignment::factory()
        ->forEmployee($employee)
        ->create([
            'source' => 'redeployment',
            'status' => CrewAssignmentStatus::Active,
            'started_at' => '2026-09-01 10:00:00',
            'planned_join_at' => '2026-09-01 10:00:00',
        ]);

    $planned = CrewDateProvenance::plannedJoin($assignment->fresh(['phases']), 'Asia/Dubai');

    expect($planned['value'])->toBeNull()
        ->and($planned['origin'])->toBe(CrewDateProvenance::MovementActual);
});

test('crew planning planned join retains crew planning provenance', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();

    $assignment = CrewAssignment::factory()
        ->forEmployee($employee)
        ->create([
            'source' => 'crew_planning',
            'planned_join_at' => '2026-08-01 00:00:00',
            'planned_signoff_at' => '2026-11-01 00:00:00',
            'started_at' => null,
        ]);

    $join = CrewDateProvenance::plannedJoin($assignment, 'Asia/Dubai');
    $signoff = CrewDateProvenance::plannedSignoff($assignment, 'Asia/Dubai');

    expect($join['value'])->toBe('2026-08-01')
        ->and($join['origin'])->toBe(CrewDateProvenance::CrewPlanning)
        ->and($signoff['value'])->toBe('2026-11-01')
        ->and($signoff['origin'])->toBe(CrewDateProvenance::CrewPlanning);
});

test('manual user entered planned join remains visible as planned', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();

    $assignment = CrewAssignment::factory()
        ->forEmployee($employee)
        ->create([
            'source' => 'manual',
            'planned_join_at' => '2026-08-20 00:00:00',
            'started_at' => '2026-08-22 08:00:00',
        ]);

    $join = CrewDateProvenance::plannedJoin($assignment, 'Asia/Dubai');

    expect($join['value'])->toBe('2026-08-20')
        ->and($join['origin'])->toBe(CrewDateProvenance::UserEntered);
});

test('blank planned dates stay blank and never fall back to actual dates', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();

    $assignment = CrewAssignment::factory()
        ->forEmployee($employee)
        ->create([
            'source' => 'manual',
            'planned_join_at' => null,
            'planned_signoff_at' => null,
            'started_at' => '2026-08-05 08:00:00',
        ]);

    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'status' => CrewPhaseStatus::Completed,
        'planned_start_at' => null,
        'planned_end_at' => null,
        'actual_start_at' => '2026-08-05 08:00:00',
        'actual_end_at' => '2026-08-15 18:00:00',
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

    expect($row['planned_join'])->toBeNull()
        ->and($row['planned_signoff'])->toBeNull()
        ->and($row['on_vessel']['actual_join'])->toBe('2026-08-05')
        ->and($row['on_vessel']['actual_disembarkation'])->toBe('2026-08-15')
        ->and($row['on_vessel']['actual_disembarkation'])->not->toBe($row['planned_signoff']);
});
