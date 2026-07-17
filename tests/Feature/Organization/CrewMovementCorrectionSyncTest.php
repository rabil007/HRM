<?php

use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewPlanningAssignment;
use App\Models\EmployeeSeaService;
use App\Support\CrewMovements\Corrections\ApproveCrewMovementCorrection;
use App\Support\CrewMovements\Corrections\RequestCrewMovementCorrection;
use App\Support\CrewMovements\SyncSeaServiceFromCrewAssignment;
use App\Support\CrewPlanning\SyncPlanningAssignmentFromCrewAssignment;

test('approved p4 date correction re-syncs planning join date', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $requester = $fixtures['user'];
    $requester->update(['current_company_id' => $fixtures['company']->id]);
    grantCompanyPermissions($requester, $fixtures['company'], [
        'crew_operations.corrections.request',
        'crew_operations.corrections.approve',
        'crew_operations.corrections.override',
    ]);

    $vessel = makeCrewMovementVessel('Planning Sync Vessel');
    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
    );
    $phase = $assignment->currentPhase;
    app(SyncPlanningAssignmentFromCrewAssignment::class)->sync($assignment);

    $proposedStart = $phase->actual_start_at->copy()->addDays(3)->timezone($fixtures['company']->timezone)->format('Y-m-d H:i');
    $correction = app(RequestCrewMovementCorrection::class)->handle(
        $assignment,
        $phase,
        $requester,
        ['actual_start_at' => $proposedStart],
        'Adjust join',
    );

    app(ApproveCrewMovementCorrection::class)->handle(
        $correction,
        $requester,
        (int) $fixtures['company']->id,
    );

    $phase->refresh();
    $planning = CrewPlanningAssignment::query()->where('crew_assignment_id', $assignment->id)->first();

    expect($planning)->not->toBeNull()
        ->and($planning->planned_join_date->toDateString())->toBe(
            $phase->actual_start_at->timezone($fixtures['company']->timezone)->toDateString()
        );
});

test('approved completed p4 correction updates existing sea service instead of deleting it', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $requester = $fixtures['user'];
    $requester->update(['current_company_id' => $fixtures['company']->id]);
    grantCompanyPermissions($requester, $fixtures['company'], [
        'crew_operations.corrections.request',
        'crew_operations.corrections.approve',
        'crew_operations.corrections.override',
    ]);

    $vessel = makeCrewMovementVessel('Sea Sync Vessel');
    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
    );
    $phase = $assignment->currentPhase;
    $phase->update([
        'status' => CrewPhaseStatus::Completed,
        'actual_end_at' => $phase->actual_start_at->copy()->addDays(20),
    ]);

    $home = CrewAssignmentPhase::query()->create([
        'company_id' => $fixtures['company']->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::HomeRedeploy,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => $phase->actual_end_at,
    ]);
    $assignment->update(['current_phase_id' => $home->id]);

    $sea = app(SyncSeaServiceFromCrewAssignment::class)->syncFromPhase($phase->fresh(['assignment.employee', 'assignment.vessel']));
    expect($sea)->not->toBeNull();
    $originalId = $sea->id;

    $proposedStart = $phase->actual_start_at->copy()->addDay()->timezone($fixtures['company']->timezone)->format('Y-m-d H:i');
    $proposedEnd = $phase->actual_end_at->copy()->timezone($fixtures['company']->timezone)->format('Y-m-d H:i');

    $correction = app(RequestCrewMovementCorrection::class)->handle(
        $assignment->fresh(),
        $phase->fresh(),
        $requester,
        [
            'actual_start_at' => $proposedStart,
            'actual_end_at' => $proposedEnd,
        ],
        'Adjust sea dates',
    );

    app(ApproveCrewMovementCorrection::class)->handle(
        $correction,
        $requester,
        (int) $fixtures['company']->id,
    );

    $phase->refresh();
    $updated = EmployeeSeaService::query()->whereKey($originalId)->first();

    expect($updated)->not->toBeNull()
        ->and($updated->start_date->toDateString())->toBe($phase->actual_start_at->timezone($fixtures['company']->timezone)->toDateString())
        ->and(EmployeeSeaService::query()->where('crew_assignment_phase_id', $phase->id)->count())->toBe(1);
});
