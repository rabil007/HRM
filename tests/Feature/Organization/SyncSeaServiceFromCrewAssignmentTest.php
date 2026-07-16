<?php

use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignmentPhase;
use App\Models\EmployeeSeaService;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\CrewMovements\SyncSeaServiceFromCrewAssignment;
use Carbon\CarbonImmutable;

test('sync creates sea service from completed P4 phase', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Sea Service Vessel');
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $phase = $assignment->currentPhase;
    $phase->update([
        'status' => CrewPhaseStatus::Completed,
        'actual_end_at' => CarbonImmutable::parse('2026-06-01 08:00:00'),
    ]);

    $seaService = app(SyncSeaServiceFromCrewAssignment::class)->syncFromPhase($phase->fresh());

    expect($seaService)->not->toBeNull()
        ->and($seaService->crew_assignment_phase_id)->toBe($phase->id)
        ->and($seaService->vessel_id)->toBe($vessel->id)
        ->and($seaService->employee_id)->toBe($employee->id);
});

test('sync does not create sea service for active P4 phase', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Active Vessel');
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $seaService = app(SyncSeaServiceFromCrewAssignment::class)->syncFromPhase($assignment->currentPhase);

    expect($seaService)->toBeNull()
        ->and(EmployeeSeaService::query()->where('crew_assignment_phase_id', $assignment->current_phase_id)->count())->toBe(0);
});

test('confirm disembarkation syncs sea service inside movement transaction', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Disembarked Vessel');
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
        ->and($seaService->crew_assignment_phase_id)->not->toBeNull()
        ->and($seaService->start_date->toDateString())->toBe('2026-01-03')
        ->and($seaService->end_date->toDateString())->toBe('2026-03-01');
});

test('sync is idempotent and updates existing sea service', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Idempotent Vessel');
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);
    $phase = $assignment->currentPhase;
    $phase->update([
        'status' => CrewPhaseStatus::Completed,
        'actual_end_at' => CarbonImmutable::parse('2026-04-01'),
    ]);

    $sync = app(SyncSeaServiceFromCrewAssignment::class);
    $first = $sync->syncFromPhase($phase->fresh());
    $phase->update(['actual_end_at' => CarbonImmutable::parse('2026-04-10')]);
    $second = $sync->syncFromPhase($phase->fresh());

    expect($second->id)->toBe($first->id)
        ->and($second->end_date->toDateString())->toBe('2026-04-10')
        ->and(EmployeeSeaService::query()->where('crew_assignment_phase_id', $phase->id)->count())->toBe(1);
});

test('sync removes sea service when phase is cancelled', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Cancelled Vessel');
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);
    $phase = $assignment->currentPhase;
    $phase->update([
        'status' => CrewPhaseStatus::Completed,
        'actual_end_at' => CarbonImmutable::parse('2026-06-01 08:00:00'),
    ]);

    $sync = app(SyncSeaServiceFromCrewAssignment::class);
    expect($sync->syncFromPhase($phase->fresh()))->not->toBeNull();

    $phase->update(['status' => CrewPhaseStatus::Cancelled]);
    expect($sync->syncFromPhase($phase->fresh()))->toBeNull()
        ->and(EmployeeSeaService::withTrashed()->where('crew_assignment_phase_id', $phase->id)->count())->toBe(0);
});

test('sync ignores non-p4 phases', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Phase Types Vessel');
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $travelPhase = CrewAssignmentPhase::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::TravelIn,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => now()->subDays(5),
        'actual_end_at' => now()->subDays(4),
    ]);

    expect(app(SyncSeaServiceFromCrewAssignment::class)->syncFromPhase($travelPhase))->toBeNull();
});
