<?php

use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Exceptions\CrewMovementException;
use App\Models\CrewAssignmentPhase;
use App\Support\CrewMovements\Corrections\CrewMovementCorrectionFieldCatalog;
use App\Support\CrewMovements\Corrections\ValidateCrewMovementCorrection;

it('rejects adding actual end to an active phase', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Validator Vessel');
    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
    );
    $phase = $assignment->currentPhase;

    expect(fn () => app(ValidateCrewMovementCorrection::class)->validateProposed(
        $assignment,
        $phase,
        [
            'actual_end_at' => $phase->actual_start_at->copy()->addDays(5)->format('Y-m-d H:i'),
        ],
    ))->toThrow(CrewMovementException::class);
});

it('rejects nulling an existing value', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Null Vessel');
    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
    );
    $phase = $assignment->currentPhase;
    $phase->update(['remarks' => 'Keep me']);

    expect(fn () => app(ValidateCrewMovementCorrection::class)->validateProposed(
        $assignment->fresh(),
        $phase->fresh(),
        ['remarks' => null],
    ))->toThrow(CrewMovementException::class);
});

it('rejects unsupported topology fields', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Topology Vessel');
    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
    );

    expect(fn () => app(ValidateCrewMovementCorrection::class)->validateProposed(
        $assignment,
        $assignment->currentPhase,
        ['phase_code' => 'p5'],
    ))->toThrow(CrewMovementException::class);
});

it('allows assignment master fields only on on-vessel phases', function () {
    $catalog = new CrewMovementCorrectionFieldCatalog;
    $fixtures = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Catalog Vessel');
    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
    );
    $phase = $assignment->currentPhase;

    expect($catalog->assignmentFields($phase))->toContain('vessel_id')
        ->and($catalog->phaseFields($phase))->not->toContain('actual_end_at');

    $phase->update(['status' => CrewPhaseStatus::Completed, 'actual_end_at' => now()]);

    expect($catalog->phaseFields($phase->fresh()))->toContain('actual_end_at');
});

it('enforces neighbor phase boundaries', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Boundary Vessel');
    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
    );
    $p4 = $assignment->currentPhase;
    $p4->update([
        'status' => CrewPhaseStatus::Completed,
        'actual_end_at' => $p4->actual_start_at->copy()->addDays(10),
    ]);

    $p5 = CrewAssignmentPhase::query()->create([
        'company_id' => $fixtures['company']->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::DemobStandby,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => $p4->actual_end_at,
    ]);
    $assignment->update(['current_phase_id' => $p5->id]);
    $assignment->load('phases');

    expect(fn () => app(ValidateCrewMovementCorrection::class)->validateProposed(
        $assignment->fresh(['phases']),
        $p4->fresh(),
        [
            'actual_end_at' => $p5->actual_start_at->copy()->addDay()->timezone($fixtures['company']->timezone)->format('Y-m-d H:i'),
        ],
    ))->toThrow(CrewMovementException::class);
});
