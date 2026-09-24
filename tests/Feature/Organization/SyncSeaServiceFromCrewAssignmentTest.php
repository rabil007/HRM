<?php

use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignmentPhase;
use App\Models\EmployeeSeaService;
use App\Support\CrewMovements\Corrections\ApplyCrewMovementCorrectionPipeline;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\CrewMovements\SeaServiceSyncService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    freezeCrewMovementTestClock();
});

afterEach(function (): void {
    restoreCrewMovementTestClock();
});

test('sync creates sea service from completed P4 phase', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Sea Service Vessel');
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $phase = $assignment->currentPhase;
    $phase->update([
        'status' => CrewPhaseStatus::Completed,
        'actual_end_at' => CarbonImmutable::parse('2026-06-01 08:00:00'),
    ]);

    $seaService = app(SeaServiceSyncService::class)->syncFromPhase($phase->fresh());

    expect($seaService)->not->toBeNull()
        ->and($seaService->crew_assignment_phase_id)->toBe($phase->id)
        ->and($seaService->vessel_id)->toBe($vessel->id)
        ->and($seaService->employee_id)->toBe($employee->id);
});

test('sync creates open sea service for active P4 phase with null end date and zero duration', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Active Vessel');
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $seaService = app(SeaServiceSyncService::class)->syncFromPhase($assignment->currentPhase);

    expect($seaService)->not->toBeNull()
        ->and($seaService->crew_assignment_phase_id)->toBe($assignment->current_phase_id)
        ->and($seaService->vessel_id)->toBe($vessel->id)
        ->and($seaService->employee_id)->toBe($employee->id)
        ->and($seaService->start_date->toDateString())->toBe($assignment->currentPhase->actual_start_at->toDateString())
        ->and($seaService->end_date)->toBeNull()
        ->and($seaService->total_months)->toBe(0)
        ->and($seaService->total_days)->toBe(0);
});

test('join vessel movement creates open sea service immediately inside transaction', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Join Vessel Real');
    $service = app(CrewMovementService::class);

    $assignment = $service->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
    ], $user->id);

    $id = $assignment->id;
    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-09-01 08:00:00',
    ], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-09-02 08:00:00',
        'next_phase' => 'p3',
    ], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-09-03 08:00:00',
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
    ], $user->id);

    $activePhase = $assignment->fresh()->currentPhase;

    $seaService = EmployeeSeaService::query()->where('employee_id', $employee->id)->first();

    expect($seaService)->not->toBeNull()
        ->and($seaService->crew_assignment_phase_id)->toBe($activePhase->id)
        ->and($seaService->start_date->toDateString())->toBe('2026-09-03')
        ->and($seaService->end_date)->toBeNull()
        ->and($seaService->total_months)->toBe(0)
        ->and($seaService->total_days)->toBe(0);
});

test('planned sign-off keeps sea service open and actual disembarkation updates the same record', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Planned Signoff Vessel');
    $service = app(CrewMovementService::class);

    $assignment = $service->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
    ], $user->id);

    $id = $assignment->id;
    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-09-01 08:00:00',
    ], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-09-02 08:00:00',
        'next_phase' => 'p3',
    ], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-09-03 08:00:00',
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
    ], $user->id);

    $seaService = EmployeeSeaService::query()->where('employee_id', $employee->id)->first();
    expect($seaService)->not->toBeNull()
        ->and($seaService->end_date)->toBeNull();

    $initialSeaServiceId = $seaService->id;

    // Plan Sign-Off: must NOT set end_date
    $service->perform($company->id, $id, CrewMovementAction::PlanSignoff, [
        'planned_signoff_at' => '2026-12-15 00:00:00',
        'planned_signoff_override_reason' => 'Planned signoff date set',
    ], $user->id);

    $seaServiceAfterPlan = EmployeeSeaService::query()->find($initialSeaServiceId);
    expect($seaServiceAfterPlan->end_date)->toBeNull()
        ->and(EmployeeSeaService::query()->where('employee_id', $employee->id)->count())->toBe(1);

    // Confirm Disembarkation: updates same record
    $service->perform($company->id, $id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-12-20 08:00:00',
        'next_phase' => 'p6',
    ], $user->id);

    $seaServiceAfterDisembark = EmployeeSeaService::query()->where('employee_id', $employee->id)->first();
    expect($seaServiceAfterDisembark->id)->toBe($initialSeaServiceId)
        ->and($seaServiceAfterDisembark->start_date->toDateString())->toBe('2026-09-03')
        ->and($seaServiceAfterDisembark->end_date->toDateString())->toBe('2026-12-20')
        ->and($seaServiceAfterDisembark->total_days)->toBeGreaterThan(0)
        ->and($seaServiceAfterDisembark->total_months)->toBeGreaterThan(0)
        ->and(EmployeeSeaService::query()->where('employee_id', $employee->id)->count())->toBe(1);
});

test('sync is idempotent for both active and completed P4 phases', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Idempotent Vessel');
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);
    $phase = $assignment->currentPhase;

    $sync = app(SeaServiceSyncService::class);
    $first = $sync->syncFromPhase($phase->fresh());
    $second = $sync->syncFromPhase($phase->fresh());

    expect($second->id)->toBe($first->id)
        ->and($second->end_date)->toBeNull()
        ->and(EmployeeSeaService::query()->where('crew_assignment_phase_id', $phase->id)->count())->toBe(1);

    $phase->update([
        'status' => CrewPhaseStatus::Completed,
        'actual_end_at' => CarbonImmutable::parse('2026-04-01'),
    ]);

    $third = $sync->syncFromPhase($phase->fresh());
    $phase->update(['actual_end_at' => CarbonImmutable::parse('2026-04-10')]);
    $fourth = $sync->syncFromPhase($phase->fresh());

    expect($third->id)->toBe($first->id)
        ->and($fourth->id)->toBe($first->id)
        ->and($fourth->end_date->toDateString())->toBe('2026-04-10')
        ->and(EmployeeSeaService::query()->where('crew_assignment_phase_id', $phase->id)->count())->toBe(1);
});

test('sync removes sea service when phase is cancelled', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Cancelled Vessel');
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);
    $phase = $assignment->currentPhase;

    $sync = app(SeaServiceSyncService::class);
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

    expect(app(SeaServiceSyncService::class)->syncFromPhase($travelPhase))->toBeNull();
});

test('transfer closes source sea service and opens destination sea service', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    $vesselA = makeCrewMovementVessel('Transfer Vessel A', $company);
    $vesselB = makeCrewMovementVessel('Transfer Vessel B', $company);
    $service = app(CrewMovementService::class);

    $assignment = $service->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vesselA->id,
    ], $user->id);

    $id = $assignment->id;
    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-09-01 08:00:00',
    ], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-09-02 08:00:00',
        'next_phase' => 'p3',
    ], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-09-03 08:00:00',
        'vessel_id' => $vesselA->id,
        'rank_id' => $rank->id,
    ], $user->id);

    $sourcePhase = $assignment->fresh()->currentPhase;
    $sourceSea = EmployeeSeaService::query()->where('crew_assignment_phase_id', $sourcePhase->id)->first();
    expect($sourceSea)->not->toBeNull()
        ->and($sourceSea->end_date)->toBeNull();

    // Perform vessel transfer on 2026-10-15
    $destination = $service->perform($company->id, $id, CrewMovementAction::TransferVessel, [
        'occurred_at' => '2026-10-15 10:00:00',
        'vessel_id' => $vesselB->id,
        'rank_id' => $rank->id,
    ], $user->id);

    $sourceSea->refresh();
    expect($sourceSea->vessel_id)->toBe($vesselA->id)
        ->and($sourceSea->start_date->toDateString())->toBe('2026-09-03')
        ->and($sourceSea->end_date->toDateString())->toBe('2026-10-15')
        ->and($sourceSea->total_days)->toBeGreaterThan(0);

    $destinationPhase = $destination->currentPhase;
    $destSea = EmployeeSeaService::query()->where('crew_assignment_phase_id', $destinationPhase->id)->first();
    expect($destSea)->not->toBeNull()
        ->and($destSea->id)->not->toBe($sourceSea->id)
        ->and($destSea->vessel_id)->toBe($vesselB->id)
        ->and($destSea->start_date->toDateString())->toBe('2026-10-15')
        ->and($destSea->end_date)->toBeNull()
        ->and($destSea->total_months)->toBe(0)
        ->and($destSea->total_days)->toBe(0)
        ->and(EmployeeSeaService::query()->where('employee_id', $employee->id)->count())->toBe(2);
});

test('sea service sync respects company boundaries and settings', function () {
    ['company' => $companyA, 'employee' => $employeeA, 'rank' => $rankA] = makeCrewAssignmentFixtures();
    ['company' => $companyB, 'employee' => $employeeB, 'rank' => $rankB] = makeCrewAssignmentFixtures();
    $vesselA = makeCrewMovementVessel('Tenant Vessel A', $companyA);
    $vesselB = makeCrewMovementVessel('Tenant Vessel B', $companyB);

    $assignmentA = makeActiveOnVesselAssignment($companyA, $employeeA, $rankA, $vesselA);
    $assignmentB = makeActiveOnVesselAssignment($companyB, $employeeB, $rankB, $vesselB);

    $sync = app(SeaServiceSyncService::class);
    $seaA = $sync->syncFromPhase($assignmentA->currentPhase->fresh());
    $seaB = $sync->syncFromPhase($assignmentB->currentPhase->fresh());

    expect($seaA->company_id)->toBe($companyA->id)
        ->and($seaB->company_id)->toBe($companyB->id)
        ->and(EmployeeSeaService::query()->where('company_id', $companyA->id)->where('employee_id', $employeeB->id)->count())->toBe(0)
        ->and(EmployeeSeaService::query()->where('company_id', $companyB->id)->where('employee_id', $employeeA->id)->count())->toBe(0);
});

test('approved correction on active P4 join date updates existing open sea service', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Correction Vessel', $company);
    $service = app(CrewMovementService::class);

    $assignment = $service->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
    ], $user->id);

    $id = $assignment->id;
    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-09-01 08:00:00',
    ], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-09-02 08:00:00',
        'next_phase' => 'p3',
    ], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-09-03 08:00:00',
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
    ], $user->id);

    $seaService = EmployeeSeaService::query()->where('employee_id', $employee->id)->first();
    expect($seaService->start_date->toDateString())->toBe('2026-09-03')
        ->and($seaService->end_date)->toBeNull();

    $initialId = $seaService->id;
    $phase = $assignment->fresh()->currentPhase;

    // Apply correction to actual_start_at
    app(ApplyCrewMovementCorrectionPipeline::class)->execute(
        $assignment->fresh(),
        $phase,
        ['actual_start_at' => CarbonImmutable::parse('2026-09-05 08:00:00')],
        $user,
        $company->id,
    );

    $updatedSeaService = EmployeeSeaService::query()->where('employee_id', $employee->id)->first();
    expect($updatedSeaService->id)->toBe($initialId)
        ->and($updatedSeaService->start_date->toDateString())->toBe('2026-09-05')
        ->and($updatedSeaService->end_date)->toBeNull()
        ->and(EmployeeSeaService::query()->where('employee_id', $employee->id)->count())->toBe(1);
});
