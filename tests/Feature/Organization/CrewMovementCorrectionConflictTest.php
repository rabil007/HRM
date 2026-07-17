<?php

use App\Enums\CrewMovementCorrectionStatus;
use App\Support\CrewMovements\Corrections\RequestCrewMovementCorrection;

test('approval fails with conflict when originals no longer match live data', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $requester = $fixtures['user'];
    $requester->update(['current_company_id' => $fixtures['company']->id]);
    grantCompanyPermissions($requester, $fixtures['company'], [
        'crew_operations.corrections.request',
        'crew_operations.corrections.approve',
        'crew_operations.corrections.override',
    ]);

    $vessel = makeCrewMovementVessel('Conflict Vessel');
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
        'Will conflict',
    );

    $phase->update([
        'actual_start_at' => $phase->actual_start_at->copy()->subDay(),
    ]);

    $this->actingAs($requester)
        ->from(route('organization.crew-movement-corrections.show', $correction))
        ->post(route('organization.crew-movement-corrections.approve', $correction))
        ->assertSessionHasErrors('correction');

    expect($correction->fresh()->status)->toBe(CrewMovementCorrectionStatus::Pending)
        ->and($phase->fresh()->actual_start_at->toIso8601String())
        ->not->toBe($correction->proposed_values['actual_start_at']['value'] ?? null);
});
