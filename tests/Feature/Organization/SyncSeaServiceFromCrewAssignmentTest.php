<?php

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
        'actual_end_at' => CarbonImmutable::today(),
    ]);

    $sync = new SyncSeaServiceFromCrewAssignment;
    $seaService = $sync->syncFromPhase($phase->fresh());

    expect($seaService)->not->toBeNull()
        ->and($seaService->company_id)->toBe($company->id)
        ->and($seaService->employee_id)->toBe($employee->id)
        ->and($seaService->crew_assignment_phase_id)->toBe($phase->id)
        ->and($seaService->vessel_id)->toBe($vessel->id)
        ->and($seaService->rank_id)->toBe($rank->id)
        ->and($seaService->start_date->toDateString())->toBe($phase->actual_start_at->toDateString())
        ->and($seaService->end_date->toDateString())->toBe($phase->actual_end_at->toDateString());
});

test('sync does not create sea service for active P4 phase', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $vessel = makeCrewMovementVessel('Active Vessel');

    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $phase = $assignment->currentPhase;

    $sync = new SyncSeaServiceFromCrewAssignment;
    $seaService = $sync->syncFromPhase($phase);

    expect($seaService)->toBeNull();

    $count = EmployeeSeaService::query()
        ->where('crew_assignment_phase_id', $phase->id)
        ->count();

    expect($count)->toBe(0);
});

test('sync via crew movement service creates sea service on disembarkation confirmation', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $vessel = makeCrewMovementVessel('Disembarked Vessel');

    $service = app(CrewMovementService::class);

    $assignment = $service->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => CarbonImmutable::today()->subDays(10)->toDateTimeString(),
        'source' => 'manual',
    ]);

    $assignment = $service->confirmJoinVessel($assignment->id, [
        'actual_join_at' => CarbonImmutable::today()->subDays(8)->toDateTimeString(),
    ]);

    $assignment = $service->confirmDisembarkation($assignment->id, [
        'actual_disembark_at' => CarbonImmutable::today()->subDays(1)->toDateTimeString(),
    ]);

    $seaServices = EmployeeSeaService::query()
        ->where('employee_id', $employee->id)
        ->where('vessel_id', $vessel->id)
        ->get();

    expect($seaServices)->toHaveCount(1)
        ->and($seaServices[0]->crew_assignment_phase_id)->not->toBeNull()
        ->and($seaServices[0]->start_date->toDateString())->toBe(CarbonImmutable::today()->subDays(8)->toDateString())
        ->and($seaServices[0]->end_date->toDateString())->toBe(CarbonImmutable::today()->subDays(1)->toDateString());
});

test('sync is idempotent and updates existing sea service', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $vessel = makeCrewMovementVessel('Idempotent Vessel');

    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $phase = $assignment->currentPhase;
    $phase->update([
        'status' => CrewPhaseStatus::Completed,
        'actual_end_at' => CarbonImmutable::today(),
    ]);

    $sync = new SyncSeaServiceFromCrewAssignment;
    $firstService = $sync->syncFromPhase($phase->fresh());

    expect($firstService)->not->toBeNull();

    $phase->update([
        'actual_end_at' => CarbonImmutable::today()->addDays(2),
    ]);

    $secondService = $sync->syncFromPhase($phase->fresh());

    expect($secondService->id)->toBe($firstService->id)
        ->and($secondService->end_date->toDateString())->toBe(CarbonImmutable::today()->addDays(2)->toDateString());

    $count = EmployeeSeaService::query()
        ->where('crew_assignment_phase_id', $phase->id)
        ->count();

    expect($count)->toBe(1);
});

test('sync removes sea service when phase is cancelled', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $vessel = makeCrewMovementVessel('Cancelled Vessel');

    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $phase = $assignment->currentPhase;
    $phase->update([
        'status' => CrewPhaseStatus::Completed,
        'actual_end_at' => CarbonImmutable::today(),
    ]);

    $sync = new SyncSeaServiceFromCrewAssignment;
    $seaService = $sync->syncFromPhase($phase->fresh());

    expect($seaService)->not->toBeNull();

    $phase->update([
        'status' => CrewPhaseStatus::Cancelled,
    ]);

    $result = $sync->syncFromPhase($phase->fresh());

    expect($result)->toBeNull();

    $count = EmployeeSeaService::query()
        ->withTrashed()
        ->where('crew_assignment_phase_id', $phase->id)
        ->count();

    expect($count)->toBe(0);
});

test('sync only creates sea service for P4 on vessel phase', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $vessel = makeCrewMovementVessel('Phase Types Vessel');

    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $travelPhase = CrewAssignmentPhase::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::TravelIn,
        'sequence' => 0,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => CarbonImmutable::today()->subDays(5),
        'actual_end_at' => CarbonImmutable::today()->subDays(4),
    ]);

    $sync = new SyncSeaServiceFromCrewAssignment;
    $seaService = $sync->syncFromPhase($travelPhase);

    expect($seaService)->toBeNull();

    $count = EmployeeSeaService::query()
        ->where('crew_assignment_phase_id', $travelPhase->id)
        ->count();

    expect($count)->toBe(0);
});

test('sync is scoped to company', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    ['company' => $otherCompany, 'employee' => $otherEmployee] = makeCrewAssignmentFixtures();

    $vessel = makeCrewMovementVessel('Company Scoped Vessel');

    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);
    $otherAssignment = makeActiveOnVesselAssignment($otherCompany, $otherEmployee, $rank, $vessel);

    $phase = $assignment->currentPhase;
    $phase->update([
        'status' => CrewPhaseStatus::Completed,
        'actual_end_at' => CarbonImmutable::today(),
    ]);

    $otherPhase = $otherAssignment->currentPhase;
    $otherPhase->update([
        'status' => CrewPhaseStatus::Completed,
        'actual_end_at' => CarbonImmutable::today(),
    ]);

    $sync = new SyncSeaServiceFromCrewAssignment;
    $seaService = $sync->syncFromPhase($phase->fresh());
    $otherService = $sync->syncFromPhase($otherPhase->fresh());

    expect($seaService->company_id)->toBe($company->id)
        ->and($otherService->company_id)->toBe($otherCompany->id);

    $companyCount = EmployeeSeaService::query()
        ->where('company_id', $company->id)
        ->where('vessel_id', $vessel->id)
        ->count();

    expect($companyCount)->toBe(1);

    $otherCount = EmployeeSeaService::query()
        ->where('company_id', $otherCompany->id)
        ->where('vessel_id', $vessel->id)
        ->count();

    expect($otherCount)->toBe(1);
});
