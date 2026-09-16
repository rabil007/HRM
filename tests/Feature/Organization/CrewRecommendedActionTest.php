<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Support\CrewMovements\CrewAssignmentRecommendedActionResolver;
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
            ->where('assignment.available_actions.2', CrewMovementAction::CancelAssignment->value)
        );
});

it('does not recommend legacy mark ready even when supplied as an allowed action', function () {
    $fixtures = makeCrewAssignmentFixtures();
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
    $assignment->load('currentPhase');

    $result = app(CrewAssignmentRecommendedActionResolver::class)->forAssignment(
        $assignment,
        [
            CrewMovementAction::MarkReady->value,
            CrewMovementAction::SendToTraining->value,
        ],
    );

    expect($result?->action)->toBe(CrewMovementAction::SendToTraining->value);
});

it('rejects crafted mark ready from join standby at the http boundary', function () {
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
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::MarkReady->value,
            'occurred_at' => '2026-01-05 08:00:00',
        ])
        ->assertSessionHasErrors('action');

    $assignment->refresh()->load('currentPhase');

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby);
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
