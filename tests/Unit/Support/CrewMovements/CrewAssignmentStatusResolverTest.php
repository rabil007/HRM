<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\Employee;
use App\Support\CrewMovements\CrewAssignmentStatusResolver;
use Carbon\CarbonImmutable;

test('forEmployee returns available when no assignment exists', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();

    $result = (new CrewAssignmentStatusResolver)->forEmployee($employee);

    expect($result['status'])->toBe('in_home')
        ->and($result['label'])->toBe('Available')
        ->and($result['assignment_id'])->toBeNull();
});

test('forEmployee returns pre mobilisation for draft assignment', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Draft Vessel');

    $assignment = CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-'.now()->year.'-DRAFT1',
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Draft,
        'source' => 'manual',
    ]);

    $phase = CrewAssignmentPhase::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::PreMobilisation,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Planned,
    ]);
    $assignment->update(['current_phase_id' => $phase->id]);

    $result = (new CrewAssignmentStatusResolver)->forEmployee($employee->fresh());

    expect($result['status'])->toBe('pre_mobilisation')
        ->and($result['current_phase'])->toBe('p0');
});

test('forEmployee returns on vessel for active p4', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('On Vessel');
    makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $result = (new CrewAssignmentStatusResolver)->forEmployee($employee->fresh());

    expect($result['status'])->toBe('on_vessel')
        ->and($result['current_vessel'])->toBe($vessel->name)
        ->and($result['vessel_name'])->toBe($vessel->name);
});

test('forEmployee returns in home for completed assignment', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Completed Vessel');

    CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-'.now()->year.'-DONE1',
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Completed,
        'started_at' => CarbonImmutable::today()->subDays(15),
        'closed_at' => CarbonImmutable::today()->subDays(5),
        'source' => 'manual',
    ]);

    $result = (new CrewAssignmentStatusResolver)->forEmployee($employee->fresh());

    expect($result['status'])->toBe('in_home')
        ->and($result['in_home_days'])->toBe(5);
});

test('forEmployee returns needs update when active assignment has no current phase', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Broken Vessel');

    CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-'.now()->year.'-BROKEN1',
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Active,
        'started_at' => now()->subDays(5),
        'source' => 'manual',
        'current_phase_id' => null,
    ]);

    $result = (new CrewAssignmentStatusResolver)->forEmployee($employee->fresh());

    expect($result['status'])->toBe('movement_update_required');
});

test('forEmployee prefers active assignment over draft', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $draftVessel = makeCrewMovementVessel('Draft Vessel');
    $activeVessel = makeCrewMovementVessel('Active Vessel');

    CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-'.now()->year.'-DRAFT2',
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $draftVessel->id,
        'status' => CrewAssignmentStatus::Draft,
        'source' => 'manual',
    ]);

    $active = makeActiveOnVesselAssignment($company, $employee, $rank, $activeVessel);
    $result = (new CrewAssignmentStatusResolver)->forEmployee($employee->fresh());

    expect($result['assignment_id'])->toBe($active->id)
        ->and($result['status'])->toBe('on_vessel');
});

test('forEmployees returns map keyed by employee id', function () {
    ['company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Multi Employee Vessel');

    $employeeA = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id, 'status' => 'active']);
    $employeeB = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id, 'status' => 'active']);

    makeActiveOnVesselAssignment($company, $employeeA, $rank, $vessel);

    $results = (new CrewAssignmentStatusResolver)->forEmployees([$employeeA, $employeeB], $company->id);

    expect($results[$employeeA->id]['status'])->toBe('on_vessel')
        ->and($results[$employeeB->id]['status'])->toBe('in_home');
});
