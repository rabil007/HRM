<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\Rank;
use App\Models\User;
use App\Support\CrewMovements\CrewArrivalResolver;
use App\Support\CrewMovements\CrewMovementService;

/**
 * @return array{user: User, company: Company, employee: Employee, rank: Rank}
 */
function makeCrewMovementActionFixtures(): array
{
    $fixtures = makeCrewAssignmentFixtures();

    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.assignments.update',
        'crew_operations.movements.perform',
        'crew_operations.assignments.cancel',
    ]);
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);

    return $fixtures;
}

test('users without movement permission cannot perform actions', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementActionFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
    ]);

    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
    ], $user->id);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::ApproveMobilisation->value,
            'occurred_at' => '2026-01-01 08:00:00',
        ])
        ->assertForbidden();
});

test('approve mobilisation advances draft to travel in', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementActionFixtures();

    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
    ], $user->id);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::ApproveMobilisation->value,
            'occurred_at' => '2026-01-01 08:00:00',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment))
        ->assertSessionHas('success', 'Start Assignment completed successfully.');

    $assignment->refresh()->load('currentPhase');

    expect($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreMobilisation);
});

test('transfer vessel redirects to the new destination assignment', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementActionFixtures();
    $sourceVessel = makeCrewMovementVessel('Transfer Source Vessel');
    $destinationVessel = makeCrewMovementVessel('Transfer Destination Vessel');
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $sourceVessel);

    $response = $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::TransferVessel->value,
            'occurred_at' => '2026-06-01 08:00:00',
            'vessel_id' => $destinationVessel->id,
            'rank_id' => $rank->id,
        ]);

    $destination = CrewAssignment::query()
        ->where('company_id', $company->id)
        ->where('employee_id', $employee->id)
        ->where('previous_assignment_id', $assignment->id)
        ->first();

    expect($destination)->not->toBeNull();

    $response->assertRedirect(route('organization.crew-assignments.show', $destination));
});

test('plan signoff does not close on-vessel phase', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementActionFixtures();
    $vessel = makeCrewMovementVessel('Plan Signoff Vessel');
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::PlanSignoff->value,
            'planned_signoff_at' => '2026-06-15',
            'planned_signoff_override_reason' => 'Operational crew change plan updated',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment));

    $assignment->refresh()->load('currentPhase');

    expect($assignment->planned_signoff_at?->toDateString())->toBe('2026-06-15')
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel)
        ->and($assignment->status)->toBe(CrewAssignmentStatus::Active);
});

test('confirm disembarkation creates sea service', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementActionFixtures();
    $vessel = makeCrewMovementVessel('Disembark Action Vessel');
    $service = app(CrewMovementService::class);

    $assignment = $service->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
    ], $user->id);

    $id = $assignment->id;
    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-01-01 08:00:00',
    ], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-01-02 08:00:00',
        'next_phase' => 'p3',
    ], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-01-03 08:00:00',
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
    ], $user->id);

    $assignment = CrewAssignment::query()->findOrFail($id);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::ConfirmDisembarkation->value,
            'occurred_at' => '2026-03-01 08:00:00',
            'next_phase' => 'p6',
        ])
        ->assertRedirect();

    expect(EmployeeSeaService::query()->where('employee_id', $employee->id)->exists())->toBeTrue();
});

test('cross-company movement action does not leak phase validation details', function () {
    ['user' => $user] = makeCrewMovementActionFixtures();
    ['company' => $otherCompany, 'employee' => $otherEmployee, 'rank' => $otherRank] = makeCrewAssignmentFixtures();

    $foreign = makeActiveOnVesselAssignment($otherCompany, $otherEmployee, $otherRank, makeCrewMovementVessel('Foreign Vessel'));

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.show', $foreign))
        ->post(route('organization.crew-assignments.perform-action', $foreign), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2026-01-01 08:00:00',
        ])
        ->assertNotFound()
        ->assertSessionDoesntHaveErrors(['action', 'occurred_at', 'next_phase']);
});

test('users without movement permission do not receive action availability validation', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementActionFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
    ]);

    $assignment = app(CrewMovementService::class)->startAssignment($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'stage_started_at' => '2026-01-01 08:00:00',
    ], $user->id);

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2026-01-02 08:00:00',
        ])
        ->assertForbidden()
        ->assertSessionDoesntHaveErrors(['action', 'occurred_at', 'next_phase']);
});

test('cross-company movement action is rejected', function () {
    ['user' => $user] = makeCrewMovementActionFixtures();
    ['company' => $otherCompany, 'employee' => $otherEmployee, 'rank' => $otherRank] = makeCrewAssignmentFixtures();

    $foreign = app(CrewMovementService::class)->createDraft($otherCompany->id, $otherEmployee->id, [
        'rank_id' => $otherRank->id,
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $foreign), [
            'action' => CrewMovementAction::ApproveMobilisation->value,
            'occurred_at' => '2026-01-01 08:00:00',
        ])
        ->assertNotFound();
});

test('users without cancel permission cannot cancel', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementActionFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.movements.perform',
    ]);

    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
    ], $user->id);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::CancelAssignment->value,
            'occurred_at' => '2026-01-01 08:00:00',
            'reason' => 'No longer needed',
        ])
        ->assertForbidden();
});

test('cancel assignment succeeds with reason', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementActionFixtures();

    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
    ], $user->id);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::CancelAssignment->value,
            'occurred_at' => '2026-01-01 08:00:00',
            'reason' => 'Client cancelled',
        ])
        ->assertRedirect();

    expect($assignment->fresh()->status)->toBe(CrewAssignmentStatus::Cancelled);
});

test('record arrival rejects invalid next phase', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementActionFixtures();

    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
    ], $user->id);
    app(CrewMovementService::class)->perform($company->id, $assignment->id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-01-01 08:00:00',
    ], $user->id);

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2026-01-05 10:00:00',
            'next_phase' => 'p4',
        ])
        ->assertSessionHasErrors('next_phase');
});

test('disembarkation before actual join is rejected', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementActionFixtures();
    $vessel = makeCrewMovementVessel('Early Disembark Vessel');
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::ConfirmDisembarkation->value,
            'occurred_at' => '2025-12-01 08:00:00',
            'next_phase' => 'p5',
        ])
        ->assertSessionHasErrors('occurred_at');
});

test('planned signoff before actual join is rejected', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementActionFixtures();
    $vessel = makeCrewMovementVessel('Early Signoff Vessel');
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::PlanSignoff->value,
            'planned_signoff_at' => '2025-12-01',
            'planned_signoff_override_reason' => 'Operational crew change plan updated',
        ])
        ->assertSessionHasErrors('planned_signoff_at');
});

test('plan signoff requires override reason', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementActionFixtures();
    $vessel = makeCrewMovementVessel('Missing Reason Vessel');
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::PlanSignoff->value,
            'planned_signoff_at' => '2026-06-15',
        ])
        ->assertSessionHasErrors('planned_signoff_override_reason');
});

test('active p0 rejects crafted approve mobilisation at http boundary without creating p1', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementActionFixtures();
    $assignment = app(CrewMovementService::class)->startAssignment($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'stage_started_at' => '2026-01-01 08:00:00',
    ], $user->id);

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::ApproveMobilisation->value,
            'occurred_at' => '2026-01-02 08:00:00',
        ])
        ->assertSessionHasErrors('action');

    $assignment->refresh()->load('phases');
    expect($assignment->phases)->toHaveCount(1)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreMobilisation)
        ->and(CrewAssignmentPhase::query()->where('crew_assignment_id', $assignment->id)->where('phase_code', CrewPhaseCode::TravelIn)->count())->toBe(0);
});

test('redeploy with starting phase p1 is rejected at http boundary', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementActionFixtures();
    $vessel = makeCrewMovementVessel('Completed Tour Vessel');
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    app(CrewMovementService::class)->perform($company->id, $assignment->id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-02-01 08:00:00',
        'next_phase' => 'p5',
    ], $user->id);

    $newVessel = makeCrewMovementVessel('Redeploy Target Vessel');

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::Redeploy->value,
            'vessel_id' => $newVessel->id,
            'rank_id' => $rank->id,
            'starting_phase' => 'p1',
            'occurred_at' => '2026-02-02 08:00:00',
        ])
        ->assertSessionHasErrors('starting_phase');
});

test('redeploy with starting phase p3 is rejected at http boundary', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementActionFixtures();
    $vessel = makeCrewMovementVessel('Completed Tour Vessel');
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    app(CrewMovementService::class)->perform($company->id, $assignment->id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-02-01 08:00:00',
        'next_phase' => 'p5',
    ], $user->id);

    $newVessel = makeCrewMovementVessel('Redeploy Target Vessel');

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::Redeploy->value,
            'vessel_id' => $newVessel->id,
            'rank_id' => $rank->id,
            'starting_phase' => 'p3',
            'occurred_at' => '2026-02-02 08:00:00',
        ])
        ->assertSessionHasErrors('starting_phase');
});

test('record arrival on active p0 transitions to join standby and sets actual arrival date', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementActionFixtures();
    $assignment = app(CrewMovementService::class)->startAssignment($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'planned_arrival_at' => '2026-01-04 10:00:00',
        'planned_join_at' => '2026-01-05',
        'stage_started_at' => '2026-01-01 08:00:00',
    ], $user->id);

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2026-01-04 11:30:00',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment))
        ->assertSessionHas('success', 'Record Arrival completed successfully.');

    $assignment->refresh()->load(['phases', 'currentPhase']);

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby)
        ->and($assignment->currentPhase?->status)->toBe(CrewPhaseStatus::Active)
        ->and($assignment->phases)->toHaveCount(2);

    expect(CrewArrivalResolver::timestamp($assignment)?->timezone($company->timezone)->format('Y-m-d H:i'))->toBe('2026-01-04 11:30')
        ->and(CrewArrivalResolver::date($assignment, $company->timezone))->toBe('2026-01-04');
});

test('record arrival on legacy p1 transitions to join standby only', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementActionFixtures();
    $vessel = makeCrewMovementVessel('Legacy P1 Vessel');

    $assignment = makeCurrentCrewPhaseAssignment(
        $company,
        $employee,
        $rank,
        $vessel,
        CrewPhaseCode::TravelIn,
    );

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::TravelIn);

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2026-01-03 14:00:00',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment));

    $assignment->refresh()->load('currentPhase');
    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby);
});

test('record arrival on legacy p1 rejects crafted ready to join destination', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementActionFixtures();
    $vessel = makeCrewMovementVessel('Legacy P1 Reject P3');

    $assignment = makeCurrentCrewPhaseAssignment(
        $company,
        $employee,
        $rank,
        $vessel,
        CrewPhaseCode::TravelIn,
    );

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2026-01-03 14:00:00',
            'next_phase' => 'p3',
        ])
        ->assertSessionHasErrors('next_phase');
});
