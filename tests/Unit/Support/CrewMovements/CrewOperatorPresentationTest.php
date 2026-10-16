<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMobilisationReadinessStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewReliefRisk;
use App\Enums\CrewReliefStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Support\CrewMovements\CrewMobilisationReadinessResult;
use App\Support\CrewMovements\CrewOperatorPresentation;
use App\Support\CrewMovements\CrewReliefReadinessResult;
use Carbon\CarbonImmutable;

function operatorPresentation(CrewAssignment $assignment, array $warnings = [], ?CrewMobilisationReadinessResult $readiness = null, ?CrewReliefReadinessResult $relief = null): array
{
    return CrewOperatorPresentation::forAssignment(
        $assignment->fresh(['currentPhase', 'phases']),
        'Asia/Dubai',
        $warnings,
        $readiness,
        $relief,
    );
}

function operatorPhase(CrewAssignment $assignment, CrewPhaseCode $code, array $attributes = []): CrewAssignmentPhase
{
    $phase = CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => $code,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Active,
        ...$attributes,
    ]);

    $assignment->update(['current_phase_id' => $phase->id]);

    return $phase;
}

it('labels an active pre-arrival assignment as awaiting arrival', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();

    $assignment = CrewAssignment::factory()->forEmployee($employee)->active()->create([
        'planned_arrival_at' => CarbonImmutable::parse('2026-10-20 08:00:00', 'Asia/Dubai'),
    ]);
    operatorPhase($assignment, CrewPhaseCode::PreArrival, [
        'actual_start_at' => CarbonImmutable::parse('2026-10-14 08:00:00', 'Asia/Dubai'),
    ]);

    $presentation = operatorPresentation($assignment);

    expect($presentation['operator_stage']['label'])->toBe('Awaiting Arrival')
        ->and($presentation['operator_stage']['secondary'])->toBe('P0 · Pre-Arrival')
        ->and($presentation['key_operational_date'])->toBe([
            'label' => 'Expected Arrival',
            'date' => '2026-10-20',
            'kind' => 'planned',
        ]);
});

it('uses the recorded phase start when the expected arrival is missing', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();

    $assignment = CrewAssignment::factory()->forEmployee($employee)->active()->create([
        'planned_arrival_at' => null,
    ]);
    operatorPhase($assignment, CrewPhaseCode::PreArrival, [
        'actual_start_at' => CarbonImmutable::parse('2026-10-14 08:00:00', 'Asia/Dubai'),
    ]);

    $date = operatorPresentation($assignment)['key_operational_date'];

    expect($date['label'])->toBe('P0 Started')
        ->and($date['date'])->toBe('2026-10-14')
        ->and($date['kind'])->toBe('actual');
});

it('shows planned sign-off for on vessel and does not invent one', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();

    $planned = CrewAssignment::factory()->forEmployee($employee)->active()->create([
        'planned_signoff_at' => CarbonImmutable::parse('2026-10-25 08:00:00', 'Asia/Dubai'),
    ]);
    operatorPhase($planned, CrewPhaseCode::OnVessel, [
        'actual_start_at' => CarbonImmutable::parse('2026-10-10 08:00:00', 'Asia/Dubai'),
    ]);

    $missing = CrewAssignment::factory()->forEmployee($employee)->active()->create([
        'planned_signoff_at' => null,
    ]);
    operatorPhase($missing, CrewPhaseCode::OnVessel, [
        'actual_start_at' => CarbonImmutable::parse('2026-10-10 08:00:00', 'Asia/Dubai'),
    ]);

    expect(operatorPresentation($planned)['operator_stage']['label'])->toBe('On Vessel')
        ->and(operatorPresentation($planned)['key_operational_date'])->toBe([
            'label' => 'Planned Sign-Off',
            'date' => '2026-10-25',
            'kind' => 'planned',
        ])
        ->and(operatorPresentation($missing)['key_operational_date'])->toBe([
            'label' => 'Joined Vessel',
            'date' => '2026-10-10',
            'kind' => 'actual',
        ]);
});

it('lets completed and cancelled states take precedence over the current phase', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();

    $completed = CrewAssignment::factory()->forEmployee($employee)->completed()->create([
        'closed_at' => CarbonImmutable::parse('2026-10-16 08:00:00', 'Asia/Dubai'),
    ]);
    operatorPhase($completed, CrewPhaseCode::PreArrival, [
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => CarbonImmutable::parse('2026-10-01 08:00:00', 'Asia/Dubai'),
        'actual_end_at' => CarbonImmutable::parse('2026-10-02 08:00:00', 'Asia/Dubai'),
    ]);

    $cancelled = CrewAssignment::factory()->forEmployee($employee)->create([
        'status' => CrewAssignmentStatus::Cancelled,
        'closed_at' => CarbonImmutable::parse('2026-10-12 08:00:00', 'Asia/Dubai'),
    ]);
    operatorPhase($cancelled, CrewPhaseCode::OnVessel, [
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => CarbonImmutable::parse('2026-10-01 08:00:00', 'Asia/Dubai'),
    ]);

    $completedPresentation = operatorPresentation($completed);
    $cancelledPresentation = operatorPresentation($cancelled);

    expect($completedPresentation['operator_stage']['label'])->toBe('Completed')
        ->and($completedPresentation['operator_stage']['secondary'])->toBeNull()
        ->and($completedPresentation['key_operational_date']['label'])->toBe('Assignment Completed')
        ->and($completedPresentation['key_operational_date']['date'])->toBe('2026-10-16')
        ->and($completedPresentation['key_operational_date']['kind'])->toBe('actual')
        ->and($cancelledPresentation['operator_stage']['label'])->toBe('Cancelled')
        ->and($cancelledPresentation['operator_stage']['secondary'])->toBeNull()
        ->and($cancelledPresentation['key_operational_date'])->toBe([
            'label' => 'Cancelled',
            'date' => '2026-10-12',
            'kind' => 'actual',
        ]);
});

it('ranks sign-off overdue ahead of document and relief attention', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();

    $assignment = CrewAssignment::factory()->forEmployee($employee)->active()->create();
    operatorPhase($assignment, CrewPhaseCode::OnVessel, [
        'actual_start_at' => CarbonImmutable::parse('2026-10-01 08:00:00', 'Asia/Dubai'),
    ]);

    $readiness = new CrewMobilisationReadinessResult(
        status: CrewMobilisationReadinessStatus::NotReady,
        checksClear: 1,
        checksTotal: 2,
        checks: [],
        problems: [[
            'code' => 'document_missing',
            'severity' => 'critical',
            'label' => 'Seaman Book missing',
            'message' => 'Seaman Book is required and has no upload.',
            'document_type_id' => 1,
        ]],
        documentsHref: null,
        applies: true,
    );
    $relief = new CrewReliefReadinessResult(
        status: CrewReliefStatus::NoRelief,
        risk: CrewReliefRisk::Warning,
        reliefEmployee: null,
        reliefPlanningAssignmentId: null,
        reliefCrewAssignmentId: null,
        reliefPlannedJoinDate: null,
        reliefPhase: null,
    );

    $attention = operatorPresentation($assignment, [[
        'code' => 'planned_signoff_overdue',
        'severity' => 'critical',
        'label' => 'Planned sign-off overdue',
        'message' => 'The planned sign-off date has passed.',
        'date' => '2026-10-10',
    ]], $readiness, $relief)['operator_attention'];

    expect($attention['label'])->toBe('Sign-Off Overdue')
        ->and($attention['tone'])->toBe('critical')
        ->and($attention['extra_count'])->toBe(2)
        ->and($attention['items'][1]['label'])->toBe('Documents Missing / Expired')
        ->and($attention['items'][2]['label'])->toBe('Relief Not Assigned');
});

it('keeps clear document checks advisory and names missing configuration', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();

    $clear = CrewAssignment::factory()->forEmployee($employee)->active()->create();
    operatorPhase($clear, CrewPhaseCode::PreArrival);

    $unconfigured = CrewAssignment::factory()->forEmployee($employee)->active()->create();
    operatorPhase($unconfigured, CrewPhaseCode::PreArrival);

    $clearAttention = operatorPresentation($clear, readiness: new CrewMobilisationReadinessResult(
        status: CrewMobilisationReadinessStatus::Ready,
        checksClear: 2,
        checksTotal: 2,
        checks: [],
        problems: [],
        documentsHref: null,
        applies: true,
    ))['operator_attention'];

    $unconfiguredAttention = operatorPresentation($unconfigured, readiness: new CrewMobilisationReadinessResult(
        status: CrewMobilisationReadinessStatus::Ready,
        checksClear: 0,
        checksTotal: 0,
        checks: [],
        problems: [],
        documentsHref: null,
        applies: true,
    ))['operator_attention'];

    expect($clearAttention['label'])->toBe('No Action Required')
        ->and($clearAttention['tone'])->toBe('clear')
        ->and($unconfiguredAttention['label'])->toBe('No Checks Configured')
        ->and($unconfiguredAttention['tone'])->toBe('info');
});

it('labels a sign-off due today separately from overdue and due soon', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();

    $assignment = CrewAssignment::factory()->forEmployee($employee)->active()->create();
    operatorPhase($assignment, CrewPhaseCode::OnVessel, [
        'actual_start_at' => CarbonImmutable::parse('2026-10-01 08:00:00', 'Asia/Dubai'),
    ]);

    $overdue = operatorPresentation($assignment, [[
        'code' => 'tour_overdue',
        'severity' => 'critical',
        'label' => 'Tour Overdue',
        'message' => 'Tour of Duty overdue by 2 day(s)',
        'date' => '2026-10-14',
    ]])['operator_attention'];

    $dueToday = operatorPresentation($assignment, [[
        'code' => 'tour_due_today',
        'severity' => 'critical',
        'label' => 'Tour Due Today',
        'message' => 'Planned Sign-Off is due today',
        'date' => '2026-10-16',
    ]])['operator_attention'];

    $dueSoon = operatorPresentation($assignment, [[
        'code' => 'tour_due_within_7_days',
        'severity' => 'critical',
        'label' => 'Tour Due Soon',
        'message' => 'Planned Sign-Off in 4 day(s)',
        'date' => '2026-10-20',
    ]])['operator_attention'];

    $plannedOverdue = operatorPresentation($assignment, [[
        'code' => 'planned_signoff_overdue',
        'severity' => 'critical',
        'label' => 'Planned sign-off overdue',
        'message' => 'The planned sign-off date has passed.',
        'date' => '2026-10-10',
    ]])['operator_attention'];

    expect($overdue['label'])->toBe('Sign-Off Overdue')
        ->and($overdue['tone'])->toBe('critical')
        ->and($overdue['items'][0]['code'])->toBe('tour_overdue')
        ->and($dueToday['label'])->toBe('Sign-Off Due Today')
        ->and($dueToday['label'])->not->toBe('Sign-Off Overdue')
        ->and($dueToday['tone'])->toBe('warning')
        ->and($dueToday['items'][0]['code'])->toBe('tour_due_today')
        ->and($dueSoon['label'])->toBe('Sign-Off Due Soon')
        ->and($dueSoon['items'][0]['code'])->toBe('tour_due_within_7_days')
        ->and($plannedOverdue['label'])->toBe('Sign-Off Overdue')
        ->and($plannedOverdue['items'][0]['code'])->toBe('planned_signoff_overdue');
});

it('classifies draft arrival as planned and the creation date as recorded', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();

    $withArrival = CrewAssignment::factory()->forEmployee($employee)->draft()->create([
        'planned_arrival_at' => CarbonImmutable::parse('2026-10-20 08:00:00', 'Asia/Dubai'),
        'created_at' => CarbonImmutable::parse('2026-10-16 09:00:00', 'Asia/Dubai'),
    ]);
    $withoutArrival = CrewAssignment::factory()->forEmployee($employee)->draft()->create([
        'planned_arrival_at' => null,
        'created_at' => CarbonImmutable::parse('2026-10-16 09:00:00', 'Asia/Dubai'),
    ]);

    $planned = operatorPresentation($withArrival);
    $recorded = operatorPresentation($withoutArrival);

    expect($planned['operator_stage']['label'])->toBe('Not Started')
        ->and($planned['key_operational_date'])->toBe([
            'label' => 'Expected Arrival',
            'date' => '2026-10-20',
            'kind' => 'planned',
        ])
        ->and($recorded['key_operational_date'])->toBe([
            'label' => 'Created',
            'date' => '2026-10-16',
            'kind' => 'recorded',
        ])
        ->and($recorded['key_operational_date']['kind'])->not->toBe('actual');
});

it('keeps completed movement and closure dates as actual movements', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();

    $completed = CrewAssignment::factory()->forEmployee($employee)->completed()->create([
        'created_at' => CarbonImmutable::parse('2026-09-01 09:00:00', 'Asia/Dubai'),
        'closed_at' => CarbonImmutable::parse('2026-10-16 08:00:00', 'Asia/Dubai'),
    ]);
    operatorPhase($completed, CrewPhaseCode::OnVessel, [
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => CarbonImmutable::parse('2026-10-05 08:00:00', 'Asia/Dubai'),
        'actual_end_at' => CarbonImmutable::parse('2026-10-10 08:00:00', 'Asia/Dubai'),
    ]);

    $date = operatorPresentation($completed)['key_operational_date'];

    expect($date['label'])->toBe('Assignment Completed')
        ->and($date['date'])->toBe('2026-10-16')
        ->and($date['kind'])->toBe('actual');
});
