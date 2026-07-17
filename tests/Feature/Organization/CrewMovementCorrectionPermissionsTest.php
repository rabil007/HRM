<?php

use App\Models\User;
use App\Support\CrewMovements\Corrections\RequestCrewMovementCorrection;
use Illuminate\Support\Facades\DB;

test('users without request permission cannot store corrections', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.assignments.view',
        'crew_operations.corrections.view',
    ]);

    $vessel = makeCrewMovementVessel('Perm Vessel');
    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
    );

    $this->actingAs($fixtures['user'])
        ->post(route('organization.crew-assignments.corrections.store', $assignment), [
            'crew_assignment_phase_id' => $assignment->currentPhase->id,
            'proposed_values' => [
                'remarks' => 'Nope',
            ],
            'reason' => 'Unauthorized',
        ])
        ->assertForbidden();
});

test('self approval is denied without override permission', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $user = $fixtures['user'];
    $user->update(['current_company_id' => $fixtures['company']->id]);
    grantCompanyPermissions($user, $fixtures['company'], [
        'crew_operations.corrections.view',
        'crew_operations.corrections.request',
        'crew_operations.corrections.approve',
    ]);

    $vessel = makeCrewMovementVessel('Self Approve Vessel');
    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
    );
    $phase = $assignment->currentPhase;
    $proposedStart = $phase->actual_start_at->copy()->addDay()->timezone($fixtures['company']->timezone)->format('Y-m-d H:i');

    $correction = app(RequestCrewMovementCorrection::class)->handle(
        $assignment,
        $phase,
        $user,
        ['actual_start_at' => $proposedStart],
        'Self request',
    );

    $this->actingAs($user)
        ->from(route('organization.crew-movement-corrections.show', $correction))
        ->post(route('organization.crew-movement-corrections.approve', $correction))
        ->assertSessionHasErrors('correction');
});

test('self approval is allowed with override permission', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $user = $fixtures['user'];
    $user->update(['current_company_id' => $fixtures['company']->id]);
    grantCompanyPermissions($user, $fixtures['company'], [
        'crew_operations.corrections.view',
        'crew_operations.corrections.request',
        'crew_operations.corrections.approve',
        'crew_operations.corrections.override',
    ]);

    $vessel = makeCrewMovementVessel('Override Vessel');
    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
    );
    $phase = $assignment->currentPhase;
    $proposedStart = $phase->actual_start_at->copy()->addDay()->timezone($fixtures['company']->timezone)->format('Y-m-d H:i');

    $correction = app(RequestCrewMovementCorrection::class)->handle(
        $assignment,
        $phase,
        $user,
        ['actual_start_at' => $proposedStart],
        'Override request',
    );

    $this->actingAs($user)
        ->post(route('organization.crew-movement-corrections.approve', $correction))
        ->assertRedirect(route('organization.crew-movement-corrections.show', $correction));

    expect($correction->fresh()->status->value)->toBe('approved');
});

test('approve permission is required to reject', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $requester = $fixtures['user'];
    $requester->update(['current_company_id' => $fixtures['company']->id]);
    grantCompanyPermissions($requester, $fixtures['company'], [
        'crew_operations.corrections.view',
        'crew_operations.corrections.request',
    ]);

    $viewer = User::factory()->create();
    DB::table('company_user')->insert([
        'company_id' => $fixtures['company']->id,
        'user_id' => $viewer->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $viewer->update(['current_company_id' => $fixtures['company']->id]);
    grantCompanyPermissions($viewer, $fixtures['company'], [
        'crew_operations.corrections.view',
    ]);

    $vessel = makeCrewMovementVessel('Reject Perm Vessel');
    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
    );
    $phase = $assignment->currentPhase;
    $correction = app(RequestCrewMovementCorrection::class)->handle(
        $assignment,
        $phase,
        $requester,
        [
            'actual_start_at' => $phase->actual_start_at->copy()->addDay()->timezone($fixtures['company']->timezone)->format('Y-m-d H:i'),
        ],
        'Need reject auth',
    );

    $this->actingAs($viewer)
        ->post(route('organization.crew-movement-corrections.reject', $correction), [
            'decision_notes' => 'Nope',
        ])
        ->assertForbidden();
});
