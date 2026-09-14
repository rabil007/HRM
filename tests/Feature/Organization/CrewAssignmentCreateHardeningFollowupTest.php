<?php

use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Support\CrewMovements\ActiveOnVesselAssignmentFinder;
use Inertia\Testing\AssertableInertia as Assert;

test('create-only users do not receive restricted on-vessel transfer metadata', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, ['crew_operations.assignments.create']);
    $user->update(['current_company_id' => $company->id]);

    $vessel = makeCrewMovementVessel('Restricted P4 Vessel', $company);
    makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew/create')
            ->where('form_options.active_on_vessel_by_employee', [])
            ->where("form_options.employee_status_by_employee.{$employee->id}.status", 'on_vessel')
            ->where("form_options.employee_status_by_employee.{$employee->id}.has_active_assignment", true)
            ->where("form_options.employee_status_by_employee.{$employee->id}.assignment_id", null)
            ->where("form_options.employee_status_by_employee.{$employee->id}.assignment_no", null)
            ->where("form_options.employee_status_by_employee.{$employee->id}.vessel_name", null));
});

test('create-only users are redirected to dashboard after successful assignment creation', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, ['crew_operations.assignments.create']);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
        ])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('success', 'Crew assignment created successfully.');

    expect(CrewAssignment::query()
        ->where('company_id', $company->id)
        ->where('employee_id', $employee->id)
        ->exists())->toBeTrue();
});

test('users with assignment view permission still redirect to the new assignment', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create',
        'crew_operations.assignments.view',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $response = $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
        ]);

    $assignment = CrewAssignment::query()
        ->where('company_id', $company->id)
        ->where('employee_id', $employee->id)
        ->latest('id')
        ->firstOrFail();

    $response
        ->assertRedirect(route('organization.crew-assignments.show', $assignment))
        ->assertSessionHas('success', 'Crew assignment created successfully.');
});

test('on-vessel lookup can be limited to the selectable employee set', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $otherEmployee = Employee::factory()
        ->forCompany($company)
        ->create([
            'rank_id' => $rank->id,
            'status' => 'active',
        ]);

    $firstVessel = makeCrewMovementVessel('Scoped P4 Vessel A', $company);
    $secondVessel = makeCrewMovementVessel('Scoped P4 Vessel B', $company);
    makeActiveOnVesselAssignment($company, $employee, $rank, $firstVessel);
    makeActiveOnVesselAssignment($company, $otherEmployee, $rank, $secondVessel);

    $finder = app(ActiveOnVesselAssignmentFinder::class);
    $scoped = $finder->forCompany($company->id, [$employee->id]);

    expect($scoped)->toHaveKey($employee->id)
        ->and($scoped)->not->toHaveKey($otherEmployee->id)
        ->and($finder->forCompany($company->id, []))->toBe([]);
});
