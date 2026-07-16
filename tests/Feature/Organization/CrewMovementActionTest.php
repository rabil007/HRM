<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\Rank;
use App\Models\User;
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
        ->assertRedirect(route('organization.crew-assignments.show', $assignment));

    $assignment->refresh()->load('currentPhase');

    expect($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::TravelIn);
});

test('unsupported transfer action is rejected', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementActionFixtures();
    $vessel = makeCrewMovementVessel('Transfer Reject Vessel');
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::TransferVessel->value,
            'occurred_at' => '2026-01-01 08:00:00',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('error');
});

test('plan signoff does not close on-vessel phase', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementActionFixtures();
    $vessel = makeCrewMovementVessel('Plan Signoff Vessel');
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::PlanSignoff->value,
            'planned_signoff_at' => '2026-06-15',
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
