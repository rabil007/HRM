<?php

use App\Enums\CrewMovementCorrectionStatus;
use App\Support\CrewMovements\Corrections\RequestCrewMovementCorrection;

test('pending reject and cancel leave official assignment and phase untouched', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $requester = $fixtures['user'];
    $requester->update(['current_company_id' => $fixtures['company']->id]);
    grantCompanyPermissions($requester, $fixtures['company'], [
        'crew_operations.corrections.request',
        'crew_operations.corrections.approve',
        'crew_operations.corrections.override',
    ]);

    $vessel = makeCrewMovementVessel('Lifecycle Vessel');
    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
    );
    $phase = $assignment->currentPhase;
    $snapshot = [
        'actual_start_at' => $phase->actual_start_at?->toIso8601String(),
        'actual_end_at' => $phase->actual_end_at?->toIso8601String(),
        'remarks' => $phase->remarks,
        'vessel_id' => $assignment->vessel_id,
        'rank_id' => $assignment->rank_id,
        'status' => $assignment->status->value,
        'phase_status' => $phase->status->value,
    ];

    $correction = app(RequestCrewMovementCorrection::class)->handle(
        $assignment,
        $phase,
        $requester,
        [
            'actual_start_at' => $phase->actual_start_at->copy()->addDay()->timezone($fixtures['company']->timezone)->format('Y-m-d H:i'),
            'remarks' => 'Should not apply yet',
        ],
        'Lifecycle',
    );

    expect($correction->status)->toBe(CrewMovementCorrectionStatus::Pending);
    $phase->refresh();
    $assignment->refresh();
    expect($phase->actual_start_at?->toIso8601String())->toBe($snapshot['actual_start_at'])
        ->and($phase->remarks)->toBe($snapshot['remarks'])
        ->and($assignment->vessel_id)->toBe($snapshot['vessel_id']);

    $this->actingAs($requester)
        ->post(route('organization.crew-movement-corrections.reject', $correction), [
            'decision_notes' => 'Not yet',
        ]);

    $phase->refresh();
    expect($phase->actual_start_at?->toIso8601String())->toBe($snapshot['actual_start_at'])
        ->and($phase->status->value)->toBe($snapshot['phase_status']);

    $second = app(RequestCrewMovementCorrection::class)->handle(
        $assignment->fresh(),
        $phase->fresh(),
        $requester,
        [
            'remarks' => 'Cancel me',
        ],
        'Cancel path',
    );

    $this->actingAs($requester)
        ->post(route('organization.crew-movement-corrections.cancel', $second));

    $phase->refresh();
    $assignment->refresh();
    expect($phase->remarks)->toBe($snapshot['remarks'])
        ->and($assignment->rank_id)->toBe($snapshot['rank_id'])
        ->and($assignment->status->value)->toBe($snapshot['status']);
});
