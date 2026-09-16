<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Support\CrewMovements\CrewMovementAvailableActions;
use App\Support\CrewMovements\CrewMovementService;
use Carbon\Carbon;

function makeLegacyPhaseCleanupFixtures(): array
{
    $fixtures = makeCrewAssignmentFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.movements.perform',
        'crew_operations.assignments.cancel',
    ]);
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);

    return $fixtures;
}

test('start assignment creates active p0 only', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeLegacyPhaseCleanupFixtures();
    $vessel = makeCrewMovementVessel('Stage1 Start', $company);
    Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'current_stage' => 'p1',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->latest('id')->first();

    expect($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreMobilisation)
        ->and($assignment->phases)->toHaveCount(1)
        ->and($assignment->phases->pluck('phase_code')->map->value->all())->toBe(['p0']);
});

test('p0 record arrival transitions to join standby', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeLegacyPhaseCleanupFixtures();
    $assignment = app(CrewMovementService::class)->startAssignment($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'stage_started_at' => '2026-01-01 08:00:00',
    ], $user->id);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2026-01-04 11:30:00',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment));

    $assignment->refresh()->load('currentPhase');

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby);
});

test('p2a available actions exclude mark ready', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeLegacyPhaseCleanupFixtures();
    $vessel = makeCrewMovementVessel('Stage1 P2A', $company);
    $service = app(CrewMovementService::class);

    $assignment = $service->startAssignment($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'stage_started_at' => '2026-01-01 08:00:00',
    ], $user->id);
    $service->perform($company->id, $assignment->id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-01-02 08:00:00',
        'next_phase' => 'p2a',
    ], $user->id);

    $assignment = $assignment->fresh(['currentPhase']);

    expect(CrewMovementAvailableActions::for($assignment))
        ->toBe([
            CrewMovementAction::SendToTraining->value,
            CrewMovementAction::JoinVessel->value,
            CrewMovementAction::CancelAssignment->value,
        ])
        ->not->toContain(CrewMovementAction::MarkReady->value);
});

test('crafted mark ready from p2a is rejected at http boundary', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeLegacyPhaseCleanupFixtures();
    $vessel = makeCrewMovementVessel('Stage1 Mark Ready', $company);
    $service = app(CrewMovementService::class);

    $assignment = $service->startAssignment($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'stage_started_at' => '2026-01-01 08:00:00',
    ], $user->id);
    $service->perform($company->id, $assignment->id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-01-02 08:00:00',
        'next_phase' => 'p2a',
    ], $user->id);

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::MarkReady->value,
            'occurred_at' => '2026-01-05 08:00:00',
        ])
        ->assertSessionHasErrors('action');

    expect($assignment->fresh('currentPhase')->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby);
});

test('p2a join vessel reaches on vessel', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeLegacyPhaseCleanupFixtures();
    $vessel = makeCrewMovementVessel('Stage1 Join', $company);
    $service = app(CrewMovementService::class);

    $assignment = $service->startAssignment($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'stage_started_at' => '2026-01-01 08:00:00',
    ], $user->id);
    $service->perform($company->id, $assignment->id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-01-02 08:00:00',
        'next_phase' => 'p2a',
    ], $user->id);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::JoinVessel->value,
            'occurred_at' => '2026-01-10 12:00:00',
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment));

    expect($assignment->fresh('currentPhase')->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel);
});

test('training loop returns to join standby and rejects crafted p3 completion', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeLegacyPhaseCleanupFixtures();
    $vessel = makeCrewMovementVessel('Stage1 Training', $company);
    $service = app(CrewMovementService::class);

    $assignment = $service->startAssignment($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'stage_started_at' => '2026-01-01 08:00:00',
    ], $user->id);
    $service->perform($company->id, $assignment->id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-01-02 08:00:00',
        'next_phase' => 'p2a',
    ], $user->id);
    $service->perform($company->id, $assignment->id, CrewMovementAction::SendToTraining, [
        'occurred_at' => '2026-01-03 08:00:00',
        'provider' => 'Academy',
        'course' => 'BOSIET',
    ], $user->id);

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::CompleteTraining->value,
            'occurred_at' => '2026-01-08 08:00:00',
            'next_phase' => 'p3',
        ])
        ->assertSessionHasErrors('next_phase');

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::CompleteTraining->value,
            'occurred_at' => '2026-01-08 08:00:00',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment));

    $assignment->refresh()->load('currentPhase');

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby)
        ->and($assignment->currentPhase?->status)->toBe(CrewPhaseStatus::Active);
});

test('legacy p1 record arrival reaches join standby', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeLegacyPhaseCleanupFixtures();
    $vessel = makeCrewMovementVessel('Stage1 Legacy P1', $company);

    $assignment = makeCurrentCrewPhaseAssignment(
        $company,
        $employee,
        $rank,
        $vessel,
        CrewPhaseCode::TravelIn,
    );

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2026-01-03 14:00:00',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment));

    expect($assignment->fresh('currentPhase')->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby);
});

test('legacy p3 join vessel reaches on vessel', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeLegacyPhaseCleanupFixtures();
    $vessel = makeCrewMovementVessel('Stage1 Legacy P3', $company);

    $assignment = makeCurrentCrewPhaseAssignment(
        $company,
        $employee,
        $rank,
        $vessel,
        CrewPhaseCode::ReadyToJoin,
    );

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::JoinVessel->value,
            'occurred_at' => '2026-01-10 12:00:00',
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment));

    expect($assignment->fresh('currentPhase')->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel);
});
