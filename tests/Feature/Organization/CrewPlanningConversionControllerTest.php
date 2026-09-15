<?php

use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\Employee;

test('authorized user is redirected to unified start form from planning', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Planning Vessel Alpha');
    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.assignments.create',
        'crew_operations.movements.perform',
        'crew_operations.assignments.view',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $employee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id, 'status' => 'active']);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-04-01',
        'planned_leave_date' => '2027-09-30',
        'notes' => 'Convert test notes',
    ]);

    $response = $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.create-crew-assignment', $planning));

    $response->assertRedirect(route('organization.crew-assignments.create', [
        'planning_assignment_id' => $planning->id,
    ]));

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('legacy conversion redirect does not create duplicate planning rows', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Planning Vessel Beta');
    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.assignments.create',
        'crew_operations.movements.perform',
    ]);
    $user->update(['current_company_id' => $company->id]);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id, 'status' => 'active']);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-04-01',
    ]);

    $initialCount = CrewPlanningAssignment::query()->where('company_id', $company->id)->count();

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.create-crew-assignment', $planning));

    expect(CrewPlanningAssignment::query()->where('company_id', $company->id)->count())->toBe($initialCount)
        ->and($planning->fresh()->crew_assignment_id)->toBeNull();
});

test('vacant planning row cannot open start handoff and throws validation error on direct create', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Vacant Vessel');
    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.assignments.create',
        'crew_operations.movements.perform',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => null,
        'planned_join_date' => '2027-06-01',
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create', ['planning_assignment_id' => $planning->id]))
        ->assertRedirect(route('organization.crew-planning.index'))
        ->assertSessionHas('error');

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('user without movement permission receives 403 on legacy conversion redirect route', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Forbidden Vessel');
    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.assignments.create',
    ]);
    $user->update(['current_company_id' => $company->id]);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id, 'status' => 'active']);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-04-01',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.create-crew-assignment', $planning))
        ->assertForbidden();
});

test('cross company planning row cannot open start handoff and returns 404', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    ['company' => $otherCompany] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Cross Company Vessel');

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.assignments.create',
        'crew_operations.movements.perform',
    ]);
    $user->update(['current_company_id' => $company->id]);
    $otherEmployee = Employee::factory()->create(['company_id' => $otherCompany->id, 'rank_id' => $rank->id, 'status' => 'active']);

    $otherPlanning = CrewPlanningAssignment::query()->create([
        'company_id' => $otherCompany->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $otherEmployee->id,
        'planned_join_date' => '2027-04-01',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.create-crew-assignment', $otherPlanning))
        ->assertNotFound();
});

test('existing edit and delete behavior for unlinked planning rows still works', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Edit Delete Vessel');
    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.planning.update',
        'crew_operations.planning.delete',
    ]);
    $user->update(['current_company_id' => $company->id]);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id, 'status' => 'active']);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-04-01',
    ]);

    $this->actingAs($user)
        ->put(route('organization.crew-planning.assignments.update', $planning), [
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'employee_id' => $employee->id,
            'planned_join_date' => '2027-05-01',
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Assignment updated.');

    expect($planning->fresh()->planned_join_date->toDateString())->toBe('2027-05-01');

    $this->actingAs($user)
        ->delete(route('organization.crew-planning.assignments.destroy', $planning))
        ->assertRedirect()
        ->assertSessionHas('success', 'Assignment removed.');

    expect(CrewPlanningAssignment::query()->whereKey($planning->id)->exists())->toBeFalse();
});
