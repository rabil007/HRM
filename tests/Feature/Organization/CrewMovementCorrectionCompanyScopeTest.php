<?php

use App\Enums\CrewMovementCorrectionStatus;
use App\Support\CrewMovements\Corrections\RequestCrewMovementCorrection;

test('foreign company cannot view or decide another company correction', function () {
    $ownerFixtures = makeCrewAssignmentFixtures();
    $owner = $ownerFixtures['user'];
    $owner->update(['current_company_id' => $ownerFixtures['company']->id]);
    grantCompanyPermissions($owner, $ownerFixtures['company'], [
        'crew_operations.corrections.view',
        'crew_operations.corrections.request',
        'crew_operations.corrections.approve',
        'crew_operations.corrections.override',
    ]);

    $vessel = makeCrewMovementVessel('Owner Vessel');
    $assignment = makeActiveOnVesselAssignment(
        $ownerFixtures['company'],
        $ownerFixtures['employee'],
        $ownerFixtures['rank'],
        $vessel,
    );
    $phase = $assignment->currentPhase;
    $correction = app(RequestCrewMovementCorrection::class)->handle(
        $assignment,
        $phase,
        $owner,
        [
            'actual_start_at' => $phase->actual_start_at->copy()->addDay()->timezone($ownerFixtures['company']->timezone)->format('Y-m-d H:i'),
        ],
        'Owner request',
    );

    $intruderFixtures = makeCrewAssignmentFixtures();
    $intruder = $intruderFixtures['user'];
    $intruder->update(['current_company_id' => $intruderFixtures['company']->id]);
    grantCompanyPermissions($intruder, $intruderFixtures['company'], [
        'crew_operations.corrections.view',
        'crew_operations.corrections.approve',
    ]);

    $this->actingAs($intruder)
        ->get(route('organization.crew-movement-corrections.show', $correction))
        ->assertNotFound();

    $this->actingAs($intruder)
        ->post(route('organization.crew-movement-corrections.approve', $correction))
        ->assertNotFound();

    expect($correction->fresh()->status)->toBe(CrewMovementCorrectionStatus::Pending);
});
