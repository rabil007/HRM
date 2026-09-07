<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Support\CrewMovements\CrewMovementService;
use Inertia\Testing\AssertableInertia as Assert;

it('keeps other allowed actions when a recommendation is present', function () {
    $fixtures = makeCrewAssignmentFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.assignments.view',
        'crew_operations.movements.perform',
        'crew_operations.assignments.cancel',
    ]);
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);

    $assignment = app(CrewMovementService::class)->createDraft(
        $fixtures['company']->id,
        $fixtures['employee']->id,
        ['rank_id' => $fixtures['rank']->id],
        $fixtures['user']->id,
    );

    $assignment->update(['status' => CrewAssignmentStatus::Active]);
    $assignment->currentPhase->update([
        'phase_code' => CrewPhaseCode::JoinStandby,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => now(),
    ]);

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-assignments.show', $assignment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('assignment.recommended_action.action', CrewMovementAction::JoinVessel->value)
            ->where('assignment.available_actions.0', CrewMovementAction::SendToTraining->value)
            ->where('assignment.available_actions.3', CrewMovementAction::CancelAssignment->value)
        );
});

it('allows a non-recommended valid movement', function () {
    $fixtures = makeCrewAssignmentFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.assignments.view',
        'crew_operations.movements.perform',
        'crew_operations.assignments.cancel',
    ]);
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);

    $assignment = app(CrewMovementService::class)->createDraft(
        $fixtures['company']->id,
        $fixtures['employee']->id,
        ['rank_id' => $fixtures['rank']->id],
        $fixtures['user']->id,
    );

    $service = app(CrewMovementService::class);
    $service->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-01-01 08:00:00',
    ], $fixtures['user']->id);
    $service->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-01-02 08:00:00',
        'next_phase' => 'p2a',
    ], $fixtures['user']->id);

    $this->actingAs($fixtures['user'])
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::MarkReady->value,
            'occurred_at' => '2026-01-05 08:00:00',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment));

    $assignment->refresh()->load('currentPhase');

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::ReadyToJoin);
});

it('still forbids movement without permission when a recommendation exists', function () {
    $fixtures = makeCrewAssignmentFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.assignments.view',
    ]);
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);

    $assignment = app(CrewMovementService::class)->createDraft(
        $fixtures['company']->id,
        $fixtures['employee']->id,
        ['rank_id' => $fixtures['rank']->id],
        $fixtures['user']->id,
    );

    $this->actingAs($fixtures['user'])
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::ApproveMobilisation->value,
            'occurred_at' => '2026-01-01 08:00:00',
        ])
        ->assertForbidden();
});
