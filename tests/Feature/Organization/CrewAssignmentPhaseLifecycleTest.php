<?php

use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Support\CrewMovements\CrewMovementAvailableActions;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\CrewMovements\CrewMovementTransitionMap;

function makePhaseLifecycleFixtures(): array
{
    $fixtures = makeCrewAssignmentFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.assignments.create',
        'crew_operations.assignments.view',
        'crew_operations.movements.perform',
        'crew_operations.assignments.cancel',
    ]);
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);

    return $fixtures;
}

test('six-phase enum values and obsolete codes', function () {
    expect(CrewPhaseCode::values())->toBe(['p0', 'p1a', 'p1b', 'p2', 'p3', 'p4'])
        ->and(CrewPhaseCode::tryFrom('p1'))->toBeNull()
        ->and(CrewPhaseCode::tryFrom('p2a'))->toBeNull()
        ->and(CrewPhaseCode::tryFrom('p2b'))->toBeNull()
        ->and(CrewPhaseCode::tryFrom('p5'))->toBeNull()
        ->and(CrewPhaseCode::tryFrom('p6'))->toBeNull()
        ->and(CrewPhaseCode::OnVessel->value)->toBe('p2')
        ->and(CrewPhaseCode::DemobStandby->value)->toBe('p3')
        ->and(CrewPhaseCode::DemobStandby->label())->toBe('Demobilisation Standby')
        ->and(CrewPhaseCode::HomeRedeploy->value)->toBe('p4')
        ->and(CrewPhaseCode::HomeRedeploy->label())->toBe('Home / Redeployment');
});

test('standard lifecycle transitions p0 through p4 home', function () {
    expect(CrewMovementTransitionMap::canTransitionWithinAssignment(
        CrewPhaseCode::PreArrival,
        CrewPhaseCode::JoinStandby,
    ))->toBeTrue()
        ->and(CrewMovementTransitionMap::canTransitionWithinAssignment(
            CrewPhaseCode::JoinStandby,
            CrewPhaseCode::OnVessel,
        ))->toBeTrue()
        ->and(CrewMovementTransitionMap::canTransitionWithinAssignment(
            CrewPhaseCode::OnVessel,
            CrewPhaseCode::DemobStandby,
        ))->toBeTrue()
        ->and(CrewMovementTransitionMap::canTransitionWithinAssignment(
            CrewPhaseCode::DemobStandby,
            CrewPhaseCode::HomeRedeploy,
        ))->toBeTrue();
});

test('training loop p1a p1b p1a and join vessel', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makePhaseLifecycleFixtures();
    $vessel = makeCrewMovementVessel('Lifecycle Training', $company);
    $service = app(CrewMovementService::class);

    $assignment = $service->startAssignment($company->id, $employee->id, [
        'position_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'stage_started_at' => '2026-01-01 08:00:00',
    ], $user->id);

    $service->perform($company->id, $assignment->id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-01-02 08:00:00',
    ], $user->id);
    $service->perform($company->id, $assignment->id, CrewMovementAction::SendToTraining, [
        'occurred_at' => '2026-01-03 08:00:00',
    ], $user->id);
    $service->perform($company->id, $assignment->id, CrewMovementAction::CompleteTraining, [
        'occurred_at' => '2026-01-05 08:00:00',
        'next_phase' => 'p1a',
    ], $user->id);

    $assignment->refresh()->load('phases');
    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby)
        ->and($assignment->phases->where('phase_code', CrewPhaseCode::Training)->count())->toBe(1);

    $service->perform($company->id, $assignment->id, CrewMovementAction::SendToTraining, [
        'occurred_at' => '2026-01-06 08:00:00',
    ], $user->id);
    $service->perform($company->id, $assignment->id, CrewMovementAction::CompleteTraining, [
        'occurred_at' => '2026-01-08 08:00:00',
        'next_phase' => 'p1a',
    ], $user->id);

    expect($assignment->fresh('phases')->phases->where('phase_code', CrewPhaseCode::Training)->count())->toBe(2);

    $service->perform($company->id, $assignment->id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-01-10 08:00:00',
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
    ], $user->id);

    expect($assignment->fresh('currentPhase')->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel);
});

test('already arrived starts at p1a without p0 history', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makePhaseLifecycleFixtures();
    $vessel = makeCrewMovementVessel('Already Arrived', $company);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_arrival_at' => '2026-02-01',
            'already_arrived' => true,
            'accommodation_status' => 'no_accommodation',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->latest('id')->firstOrFail();
    $assignment->load('phases');

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby)
        ->and($assignment->phases->pluck('phase_code')->map->value->all())->toBe(['p1a']);
});

test('plan sign-off does not leave on vessel phase', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makePhaseLifecycleFixtures();
    $vessel = makeCrewMovementVessel('Plan Signoff', $company);
    $service = app(CrewMovementService::class);

    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);
    $beforePhaseId = $assignment->current_phase_id;

    $service->perform($company->id, $assignment->id, CrewMovementAction::PlanSignoff, [
        'planned_signoff_at' => '2026-12-01',
        'planned_signoff_override_reason' => 'Updated forecast for lifecycle regression test',
    ], $user->id);

    $assignment->refresh();
    expect($assignment->current_phase_id)->toBe($beforePhaseId)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel);
});

test('mark ready and start join standby actions are unavailable', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makePhaseLifecycleFixtures();
    $vessel = makeCrewMovementVessel('Retired Actions', $company);
    $service = app(CrewMovementService::class);

    $assignment = $service->startAssignment($company->id, $employee->id, [
        'position_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'stage_started_at' => '2026-01-01 08:00:00',
    ], $user->id);
    $service->perform($company->id, $assignment->id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-01-02 08:00:00',
    ], $user->id);

    $assignment = $assignment->fresh(['currentPhase']);
    $available = CrewMovementAvailableActions::for($assignment);

    expect($available)->not->toContain(CrewMovementAction::MarkReady->value)
        ->not->toContain(CrewMovementAction::StartJoinStandby->value);
});

test('p2 disembark directly to p4 home', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makePhaseLifecycleFixtures();
    $vessel = makeCrewMovementVessel('Direct Home', $company);
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);
    $service = app(CrewMovementService::class);

    $service->perform($company->id, $assignment->id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-03-01 18:00:00',
        'next_phase' => 'p4',
        'accommodation_status' => 'no_accommodation',
    ], $user->id);

    expect($assignment->fresh('currentPhase')->currentPhase?->phase_code)->toBe(CrewPhaseCode::HomeRedeploy)
        ->and($assignment->fresh('currentPhase')->currentPhase?->status)->toBe(CrewPhaseStatus::Active);
});

test('current movement api rejects obsolete next_phase codes', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makePhaseLifecycleFixtures();
    $vessel = makeCrewMovementVessel('Obsolete Next Phase', $company);
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::ConfirmDisembarkation->value,
            'occurred_at' => '2026-03-01T18:00',
            'next_phase' => 'p5',
            'accommodation_status' => 'no_accommodation',
        ])
        ->assertSessionHasErrors('next_phase');

    expect($assignment->fresh('currentPhase')->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel);
});
