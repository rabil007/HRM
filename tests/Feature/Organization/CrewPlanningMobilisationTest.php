<?php

namespace Tests\Feature\Organization;

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Support\CrewPlanning\StartPlanningMobilisation;

test('named planning record starts mobilisation to active CrewAssignment with P0 Pre-Mobilisation', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Mobilisation Vessel Alpha', $company);
    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.assignments.create',
        'crew_operations.movements.perform',
        'crew_operations.assignments.view',
    ]);
    $user->update(['current_company_id' => $company->id]);
    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'position_id' => $rank->id,
        'status' => 'active',
    ]);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_arrival_date' => '2027-03-25',
        'planned_join_date' => '2027-04-01',
        'planned_leave_date' => '2027-09-30',
        'notes' => 'Mobilisation handoff notes',
    ]);

    $response = $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.start-mobilisation', $planning));

    $planning->refresh();
    expect($planning->crew_assignment_id)->not->toBeNull();

    $assignment = CrewAssignment::query()->find($planning->crew_assignment_id);
    expect($assignment)->not->toBeNull()
        ->and($assignment->company_id)->toBe($company->id)
        ->and($assignment->employee_id)->toBe($employee->id)
        ->and($assignment->vessel_id)->toBe($vessel->id)
        ->and($assignment->position_id)->toBe($rank->id)
        ->and($assignment->client_id)->toBe($vessel->client_id)
        ->and($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->source)->toBe('crew_planning')
        ->and($assignment->remarks)->toBe('Mobilisation handoff notes')
        ->and($assignment->started_at)->not->toBeNull()
        ->and($assignment->planned_arrival_at?->format('Y-m-d'))->toBe('2027-03-25')
        ->and($assignment->planned_join_at?->format('Y-m-d'))->toBe('2027-04-01')
        ->and($assignment->planned_signoff_at?->format('Y-m-d'))->toBe('2027-09-30');

    expect($assignment->currentPhase)->not->toBeNull()
        ->and($assignment->currentPhase->phase_code)->toBe(CrewPhaseCode::PreMobilisation)
        ->and($assignment->currentPhase->status)->toBe(CrewPhaseStatus::Active)
        ->and($assignment->currentPhase->actual_start_at?->toIso8601String())->toBe($assignment->started_at->toIso8601String());

    $response->assertRedirect(route('organization.crew-assignments.show', $assignment))
        ->assertSessionHas('success', 'Mobilisation started successfully.');
});

test('no sea service or actual vessel join is created on mobilisation start', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Vessel Sea Service Check', $company);
    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.assignments.create',
        'crew_operations.movements.perform',
        'crew_operations.assignments.view',
    ]);
    $user->update(['current_company_id' => $company->id]);
    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'position_id' => $rank->id,
        'status' => 'active',
    ]);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-04-01',
        'planned_leave_date' => '2027-09-30',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.start-mobilisation', $planning));

    expect(EmployeeSeaService::query()->where('employee_id', $employee->id)->count())->toBe(0);

    $assignment = CrewAssignment::query()->where('employee_id', $employee->id)->first();
    expect($assignment->phases()->where('phase_code', CrewPhaseCode::OnVessel)->count())->toBe(0);
});

test('vacant planning record cannot start mobilisation', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Vacant Mobilisation Vessel', $company);
    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.assignments.create',
        'crew_operations.movements.perform',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => null,
        'planned_join_date' => '2027-04-01',
        'planned_leave_date' => '2027-09-30',
    ]);

    $response = $this->actingAs($user)
        ->from(route('organization.crew-planning.index'))
        ->post(route('organization.crew-planning.assignments.start-mobilisation', $planning));

    $response->assertRedirect(route('organization.crew-planning.index'))
        ->assertSessionHas('error', 'Cannot start mobilisation for a vacant planning slot. Assign an employee first.');

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('already linked planning record returns existing assignment without duplicating', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Linked Mobilisation Vessel', $company);
    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.assignments.create',
        'crew_operations.movements.perform',
        'crew_operations.assignments.view',
    ]);
    $user->update(['current_company_id' => $company->id]);
    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'position_id' => $rank->id,
        'status' => 'active',
    ]);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-04-01',
        'planned_leave_date' => '2027-09-30',
    ]);

    // First start
    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.start-mobilisation', $planning));

    $assignmentId = $planning->fresh()->crew_assignment_id;
    expect($assignmentId)->not->toBeNull();
    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(1);

    // Second start (double-click/replay)
    $response = $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.start-mobilisation', $planning));

    $response->assertRedirect(route('organization.crew-assignments.show', $assignmentId));
    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(1);
});

test('double or concurrent start is safe and idempotent', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Idempotent Vessel', $company);
    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.assignments.create',
        'crew_operations.movements.perform',
        'crew_operations.assignments.view',
    ]);
    $user->update(['current_company_id' => $company->id]);
    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'position_id' => $rank->id,
        'status' => 'active',
    ]);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-04-01',
        'planned_leave_date' => '2027-09-30',
    ]);

    $service = app(StartPlanningMobilisation::class);

    $assignment1 = $service->handle($company->id, $planning, $user);
    $assignment2 = $service->handle($company->id, $planning, $user);

    expect($assignment1->id)->toBe($assignment2->id)
        ->and(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(1);
});

test('inactive employee cannot start mobilisation', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Inactive Employee Vessel', $company);
    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.assignments.create',
        'crew_operations.movements.perform',
    ]);
    $user->update(['current_company_id' => $company->id]);
    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'position_id' => $rank->id,
        'status' => 'inactive',
    ]);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-04-01',
        'planned_leave_date' => '2027-09-30',
    ]);

    $response = $this->actingAs($user)
        ->from(route('organization.crew-planning.index'))
        ->post(route('organization.crew-planning.assignments.start-mobilisation', $planning));

    $response->assertRedirect(route('organization.crew-planning.index'))
        ->assertSessionHas('error', 'Only active employees can receive a crew assignment.');

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('cross-company employee is blocked from mobilisation', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    ['company' => $otherCompany] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Cross Company Vessel', $company);
    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.assignments.create',
        'crew_operations.movements.perform',
    ]);
    $user->update(['current_company_id' => $company->id]);
    $foreignEmployee = Employee::factory()->create([
        'company_id' => $otherCompany->id,
        'status' => 'active',
    ]);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => $foreignEmployee->id,
        'planned_join_date' => '2027-04-01',
        'planned_leave_date' => '2027-09-30',
    ]);

    $response = $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.start-mobilisation', $planning));

    $response->assertNotFound();

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('hidden employee by visibility scope cannot be accessed', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Visibility Vessel', $company);
    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.assignments.create',
        'crew_operations.movements.perform',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $deptA = Department::query()->create(['company_id' => $company->id, 'name' => 'Dept A', 'status' => 'active']);
    $deptB = Department::query()->create(['company_id' => $company->id, 'name' => 'Dept B', 'status' => 'active']);

    restrictTestRoleEmployeeVisibility($user, $company, [$deptA->id]);

    // Target employee is in Department B (hidden)
    $hiddenEmployee = Employee::factory()->create([
        'company_id' => $company->id,
        'department_id' => $deptB->id,
        'position_id' => $rank->id,
        'status' => 'active',
    ]);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => $hiddenEmployee->id,
        'planned_join_date' => '2027-04-01',
        'planned_leave_date' => '2027-09-30',
    ]);

    $response = $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.start-mobilisation', $planning));

    $response->assertNotFound();
});

test('conflict with existing active assignment is blocked', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel1 = makeCrewMovementVessel('Active Vessel 1', $company);
    $vessel2 = makeCrewMovementVessel('Active Vessel 2', $company);
    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.assignments.create',
        'crew_operations.movements.perform',
    ]);
    $user->update(['current_company_id' => $company->id]);
    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'position_id' => $rank->id,
        'status' => 'active',
    ]);

    // Employee already has an active assignment
    makeActiveOnVesselAssignment($company, $employee, $rank, $vessel1);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel2->id,
        'position_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-04-01',
        'planned_leave_date' => '2027-09-30',
    ]);

    $response = $this->actingAs($user)
        ->from(route('organization.crew-planning.index'))
        ->post(route('organization.crew-planning.assignments.start-mobilisation', $planning));

    $response->assertRedirect(route('organization.crew-planning.index'))
        ->assertSessionHas('error');

    // Only the existing active assignment remains
    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(1);
});

test('conflict with another overlapping plan is blocked', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel1 = makeCrewMovementVessel('Plan Vessel 1', $company);
    $vessel2 = makeCrewMovementVessel('Plan Vessel 2', $company);
    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.assignments.create',
        'crew_operations.movements.perform',
    ]);
    $user->update(['current_company_id' => $company->id]);
    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'position_id' => $rank->id,
        'status' => 'active',
    ]);

    // Other unlinked plan for the same employee
    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel1->id,
        'position_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-04-10',
        'planned_leave_date' => '2027-08-30',
    ]);

    // Target plan overlapping with the other plan
    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel2->id,
        'position_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-04-01',
        'planned_leave_date' => '2027-09-30',
    ]);

    $response = $this->actingAs($user)
        ->from(route('organization.crew-planning.index'))
        ->post(route('organization.crew-planning.assignments.start-mobilisation', $planning));

    $response->assertRedirect(route('organization.crew-planning.index'))
        ->assertSessionHas('error');

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('source planning row does not conflict with itself', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Self Conflict Vessel', $company);
    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.assignments.create',
        'crew_operations.movements.perform',
        'crew_operations.assignments.view',
    ]);
    $user->update(['current_company_id' => $company->id]);
    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'position_id' => $rank->id,
        'status' => 'active',
    ]);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-04-01',
        'planned_leave_date' => '2027-09-30',
    ]);

    $response = $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.start-mobilisation', $planning));

    $response->assertRedirect();
    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(1);
});

test('permissions are strictly enforced on start-mobilisation endpoint', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Perms Vessel', $company);
    $user->update(['current_company_id' => $company->id]);
    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'position_id' => $rank->id,
        'status' => 'active',
    ]);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-04-01',
        'planned_leave_date' => '2027-09-30',
    ]);

    // 1. No permissions -> 403
    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.start-mobilisation', $planning))
        ->assertForbidden();

    // 2. Only planning.view -> 403
    grantCompanyPermissions($user, $company, ['crew_operations.planning.view']);
    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.start-mobilisation', $planning))
        ->assertForbidden();

    // 3. planning.view + assignments.create (missing movements.perform) -> 403
    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.assignments.create',
    ]);
    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.start-mobilisation', $planning))
        ->assertForbidden();

    // 4. All three -> succeeds
    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.assignments.create',
        'crew_operations.movements.perform',
        'crew_operations.assignments.view',
    ]);
    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.start-mobilisation', $planning))
        ->assertRedirect();
});

test('relieved assignment is validated and copied to created CrewAssignment', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Relief Mobilisation Vessel', $company);
    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.assignments.create',
        'crew_operations.movements.perform',
        'crew_operations.assignments.view',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $onboardEmployee = Employee::factory()->create([
        'company_id' => $company->id,
        'position_id' => $rank->id,
        'status' => 'active',
    ]);
    $onboardAssignment = makeActiveOnVesselAssignment($company, $onboardEmployee, $rank, $vessel);

    $reliefEmployee = Employee::factory()->create([
        'company_id' => $company->id,
        'position_id' => $rank->id,
        'status' => 'active',
    ]);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => $reliefEmployee->id,
        'relieves_crew_assignment_id' => $onboardAssignment->id,
        'planned_join_date' => '2027-04-01',
        'planned_leave_date' => '2027-09-30',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.start-mobilisation', $planning));

    $created = CrewAssignment::query()->where('employee_id', $reliefEmployee->id)->first();
    expect($created)->not->toBeNull()
        ->and($created->relieves_crew_assignment_id)->toBe($onboardAssignment->id);
});
