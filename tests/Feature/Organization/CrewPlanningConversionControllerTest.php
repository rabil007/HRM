<?php

use App\Enums\CrewAssignmentStatus;
use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\Employee;

test('authorized user converts an unlinked planning row to draft crew assignment', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Planning Vessel Alpha');
    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.assignments.create',
        'crew_operations.assignments.view',
    ]);

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

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->where('employee_id', $employee->id)->firstOrFail();

    $response->assertRedirect(route('organization.crew-assignments.show', $assignment));
    $response->assertSessionHas('success', 'Crew assignment created from planning.');

    expect($assignment->status)->toBe(CrewAssignmentStatus::Draft)
        ->and($assignment->source)->toBe('crew_planning')
        ->and($assignment->vessel_id)->toBe($vessel->id)
        ->and($assignment->rank_id)->toBe($rank->id)
        ->and($assignment->planned_join_at->toDateString())->toBe('2027-04-01')
        ->and($assignment->planned_signoff_at->toDateString())->toBe('2027-09-30')
        ->and($planning->fresh()->crew_assignment_id)->toBe($assignment->id);
});

test('conversion reuses original planning row without creating duplicate planning rows', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Planning Vessel Beta');
    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.assignments.create',
        'crew_operations.assignments.view',
    ]);
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
        ->and($planning->fresh()->crew_assignment_id)->not->toBeNull();
});

test('calling conversion again is idempotent and redirects to existing crew assignment', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Idempotent Vessel');
    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.assignments.create',
        'crew_operations.assignments.view',
    ]);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id, 'status' => 'active']);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-04-01',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.create-crew-assignment', $planning));

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->firstOrFail();

    $response2 = $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.create-crew-assignment', $planning->fresh()));

    $response2->assertRedirect(route('organization.crew-assignments.show', $assignment));
    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(1);
});

test('relieves_crew_assignment_id link is preserved on conversion', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Relief Vessel');
    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.assignments.create',
        'crew_operations.assignments.view',
    ]);
    $onboardEmployee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id, 'status' => 'active']);
    $onboardAssignment = makeActiveOnVesselAssignment($company, $onboardEmployee, $rank, $vessel);

    $reliefEmployee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id, 'status' => 'active']);
    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $reliefEmployee->id,
        'planned_join_date' => '2027-05-01',
        'relieves_crew_assignment_id' => $onboardAssignment->id,
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.create-crew-assignment', $planning));

    expect($planning->fresh()->relieves_crew_assignment_id)->toBe($onboardAssignment->id)
        ->and($planning->fresh()->crew_assignment_id)->not->toBeNull();
});

test('vacant planning row cannot be converted and throws validation error', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Vacant Vessel');
    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.assignments.create',
    ]);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => null,
        'planned_join_date' => '2027-06-01',
    ]);

    $response = $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.create-crew-assignment', $planning));

    $response->assertSessionHasErrors('error');
    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('planning row missing required vessel or rank is rejected using service validation rules', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Missing Master Vessel');
    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.assignments.create',
    ]);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id, 'status' => 'active']);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-04-01',
    ]);

    $planning->update(['vessel_id' => null]);

    $response = $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.create-crew-assignment', $planning));

    $response->assertSessionHasErrors('error');
});

test('user without crew_operations.assignments.create permission receives 403', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Forbidden Vessel');
    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.planning.create',
    ]);
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

test('cross company planning row cannot be converted and returns 404', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    ['company' => $otherCompany] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Cross Company Vessel');

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.assignments.create',
    ]);
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

test('linked planning bars remain controlled by crew assignments and reject update and delete', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Controlled Vessel');
    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.planning.update',
        'crew_operations.planning.delete',
        'crew_operations.assignments.create',
        'crew_operations.assignments.view',
    ]);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id, 'status' => 'active']);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-04-01',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.create-crew-assignment', $planning));

    $linked = $planning->fresh();

    $this->actingAs($user)
        ->put(route('organization.crew-planning.assignments.update', $linked), [
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'employee_id' => $employee->id,
            'planned_join_date' => '2027-05-01',
        ])
        ->assertSessionHasErrors('error');

    $this->actingAs($user)
        ->delete(route('organization.crew-planning.assignments.destroy', $linked))
        ->assertSessionHasErrors('error');
});

test('existing edit and delete behavior for unlinked planning rows still works', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Edit Delete Vessel');
    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.planning.update',
        'crew_operations.planning.delete',
    ]);
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
