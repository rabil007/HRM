<?php

use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewOperationsSetting;
use App\Models\EmployeeSeaService;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\CrewMovements\SeaServiceSyncService;
use App\Support\CrewOperations\CrewOperationsSettings;
use Carbon\CarbonImmutable;

test('sea service sync defaults to enabled when company has no settings row', function () {
    ['company' => $company] = makeCrewAssignmentFixtures();

    expect(CrewOperationsSettings::syncSeaServiceEnabled((int) $company->id))->toBeTrue()
        ->and(CrewOperationsSettings::CONFIG_SYNC_SEA_SERVICE)->toBe('crew_operations.sync_sea_service');
});

test('sea service is created when synchronization is enabled', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Enabled Sync Vessel');
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);
    $phase = $assignment->currentPhase;
    $phase->update([
        'status' => CrewPhaseStatus::Completed,
        'actual_end_at' => CarbonImmutable::parse('2026-06-01 08:00:00'),
    ]);

    CrewOperationsSetting::query()->create([
        'company_id' => $company->id,
        'sync_sea_service' => true,
        'max_home_days' => 30,
    ]);

    $seaService = app(SeaServiceSyncService::class)->syncFromPhase($phase->fresh());

    expect($seaService)->not->toBeNull()
        ->and($seaService->crew_assignment_phase_id)->toBe($phase->id)
        ->and($seaService->vessel_id)->toBe($vessel->id)
        ->and($seaService->start_date->toDateString())->toBe($phase->actual_start_at->toDateString())
        ->and($seaService->end_date->toDateString())->toBe('2026-06-01');
});

test('sea service is updated when assignment details change while sync is enabled', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Update Sync Vessel');
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);
    $phase = $assignment->currentPhase;
    $phase->update([
        'status' => CrewPhaseStatus::Completed,
        'actual_end_at' => CarbonImmutable::parse('2026-04-01'),
    ]);

    $sync = app(SeaServiceSyncService::class);
    $first = $sync->syncFromPhase($phase->fresh());
    $phase->update(['actual_end_at' => CarbonImmutable::parse('2026-04-15')]);
    $second = $sync->syncFromPhase($phase->fresh());

    expect($second->id)->toBe($first->id)
        ->and($second->end_date->toDateString())->toBe('2026-04-15')
        ->and(EmployeeSeaService::query()->where('crew_assignment_phase_id', $phase->id)->count())->toBe(1);
});

test('sign-off details are synchronized through disembarkation when enabled', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Sign Off Sync Vessel');
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
    $service->perform($company->id, $id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-03-01 08:00:00',
        'next_phase' => 'p6',
    ], $user->id);

    $seaService = EmployeeSeaService::query()->where('employee_id', $employee->id)->first();

    expect($seaService)->not->toBeNull()
        ->and($seaService->end_date->toDateString())->toBe('2026-03-01');
});

test('no sea service record is created when synchronization is disabled', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Disabled Sync Vessel');
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);
    $phase = $assignment->currentPhase;
    $phase->update([
        'status' => CrewPhaseStatus::Completed,
        'actual_end_at' => CarbonImmutable::parse('2026-06-01 08:00:00'),
    ]);

    CrewOperationsSetting::query()->create([
        'company_id' => $company->id,
        'sync_sea_service' => false,
        'max_home_days' => 30,
    ]);

    $result = app(SeaServiceSyncService::class)->syncFromPhase($phase->fresh());

    expect($result)->toBeNull()
        ->and(EmployeeSeaService::query()->where('crew_assignment_phase_id', $phase->id)->count())->toBe(0);
});

test('existing sea service records remain unchanged when sync is disabled', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Preserve Sync Vessel');
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);
    $phase = $assignment->currentPhase;
    $phase->update([
        'status' => CrewPhaseStatus::Completed,
        'actual_end_at' => CarbonImmutable::parse('2026-06-01 08:00:00'),
    ]);

    $sync = app(SeaServiceSyncService::class);
    $existing = $sync->syncFromPhase($phase->fresh());
    expect($existing)->not->toBeNull();

    CrewOperationsSettings::saveSettings((int) $company->id, [], 30, false);

    $phase->update(['actual_end_at' => CarbonImmutable::parse('2026-06-20 08:00:00')]);
    $phase->update(['status' => CrewPhaseStatus::Cancelled]);

    $result = $sync->syncFromPhase($phase->fresh());

    expect($result->id)->toBe($existing->id)
        ->and($result->end_date->toDateString())->toBe('2026-06-01')
        ->and(EmployeeSeaService::withTrashed()->where('crew_assignment_phase_id', $phase->id)->count())->toBe(1);
});

test('re-enabling synchronization affects future assignment changes only', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Reenable Sync Vessel');
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);
    $phase = $assignment->currentPhase;
    $phase->update([
        'status' => CrewPhaseStatus::Completed,
        'actual_end_at' => CarbonImmutable::parse('2026-05-01 08:00:00'),
    ]);

    CrewOperationsSettings::saveSettings((int) $company->id, [], 30, false);
    $sync = app(SeaServiceSyncService::class);

    expect($sync->syncFromPhase($phase->fresh()))->toBeNull()
        ->and(EmployeeSeaService::query()->where('crew_assignment_phase_id', $phase->id)->count())->toBe(0);

    CrewOperationsSettings::saveSettings((int) $company->id, [], 30, true);
    $created = $sync->syncFromPhase($phase->fresh());

    expect($created)->not->toBeNull()
        ->and($created->end_date->toDateString())->toBe('2026-05-01');
});

test('duplicate sea service records are not created for the same phase', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Duplicate Sync Vessel');
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);
    $phase = $assignment->currentPhase;
    $phase->update([
        'status' => CrewPhaseStatus::Completed,
        'actual_end_at' => CarbonImmutable::parse('2026-07-01 08:00:00'),
    ]);

    $sync = app(SeaServiceSyncService::class);
    $first = $sync->syncFromPhase($phase->fresh());
    $second = $sync->syncFromPhase($phase->fresh());

    expect($second->id)->toBe($first->id)
        ->and(EmployeeSeaService::query()->where('crew_assignment_phase_id', $phase->id)->count())->toBe(1);
});

test('company a sync setting does not affect company b', function () {
    ['company' => $companyA, 'employee' => $employeeA, 'rank' => $rankA] = makeCrewAssignmentFixtures();
    ['company' => $companyB, 'employee' => $employeeB, 'rank' => $rankB] = makeCrewAssignmentFixtures();

    CrewOperationsSettings::saveSettings((int) $companyA->id, [], 30, false);
    CrewOperationsSettings::saveSettings((int) $companyB->id, [], 30, true);

    $vesselA = makeCrewMovementVessel('Company A Vessel');
    $vesselB = makeCrewMovementVessel('Company B Vessel');

    $assignmentA = makeActiveOnVesselAssignment($companyA, $employeeA, $rankA, $vesselA);
    $assignmentB = makeActiveOnVesselAssignment($companyB, $employeeB, $rankB, $vesselB);

    foreach ([$assignmentA->currentPhase, $assignmentB->currentPhase] as $phase) {
        $phase->update([
            'status' => CrewPhaseStatus::Completed,
            'actual_end_at' => CarbonImmutable::parse('2026-08-01 08:00:00'),
        ]);
    }

    $sync = app(SeaServiceSyncService::class);

    expect($sync->syncFromPhase($assignmentA->currentPhase->fresh()))->toBeNull()
        ->and($sync->syncFromPhase($assignmentB->currentPhase->fresh()))->not->toBeNull()
        ->and(EmployeeSeaService::query()->where('crew_assignment_phase_id', $assignmentA->current_phase_id)->count())->toBe(0)
        ->and(EmployeeSeaService::query()->where('crew_assignment_phase_id', $assignmentB->current_phase_id)->count())->toBe(1);
});

test('cancelling a completed phase removes sea service only when sync is enabled', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Cancel Sync Vessel');
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);
    $phase = $assignment->currentPhase;
    $phase->update([
        'status' => CrewPhaseStatus::Completed,
        'actual_end_at' => CarbonImmutable::parse('2026-06-01 08:00:00'),
    ]);

    $sync = app(SeaServiceSyncService::class);
    expect($sync->syncFromPhase($phase->fresh()))->not->toBeNull();

    $phase->update(['status' => CrewPhaseStatus::Cancelled]);
    expect($sync->syncFromPhase($phase->fresh()))->toBeNull()
        ->and(EmployeeSeaService::withTrashed()->where('crew_assignment_phase_id', $phase->id)->count())->toBe(0);
});

test('cancelling a completed phase leaves sea service unchanged when sync is disabled', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Cancel Disabled Sync Vessel');
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);
    $phase = $assignment->currentPhase;
    $phase->update([
        'status' => CrewPhaseStatus::Completed,
        'actual_end_at' => CarbonImmutable::parse('2026-06-01 08:00:00'),
    ]);

    $sync = app(SeaServiceSyncService::class);
    $existing = $sync->syncFromPhase($phase->fresh());
    expect($existing)->not->toBeNull();

    CrewOperationsSettings::saveSettings((int) $company->id, [], 30, false);

    $phase->update(['status' => CrewPhaseStatus::Cancelled]);
    $result = $sync->syncFromPhase($phase->fresh());

    expect($result?->id)->toBe($existing->id)
        ->and(EmployeeSeaService::query()->whereKey($existing->id)->exists())->toBeTrue();
});
