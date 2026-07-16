<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\Company;
use App\Models\Country;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\Rank;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselType;
use App\Support\CrewMovements\CrewAssignmentStatusResolver;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateFieldRegistry;
use Carbon\CarbonImmutable;

function makeEmployeeCrewStatusVessel(string $name): Vessel
{
    $vesselType = VesselType::query()->firstOrCreate(
        ['name' => 'Employee Crew Status Test Type'],
        ['is_active' => true],
    );

    return Vessel::query()->firstOrCreate(
        ['name' => $name],
        ['vessel_type_id' => $vesselType->id, 'is_active' => true],
    );
}

function makeEmployeeCrewStatusFixtures(): array
{
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'ECS',
        'name' => 'Crew Status Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'ECS',
        'name' => 'Crew Status Currency',
        'symbol' => 'E$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Crew Status Co',
        'slug' => 'crew-status-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $rank = Rank::query()->create([
        'name' => 'AB',
        'is_active' => true,
    ]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => '3001',
            'name' => 'Crew Status Seafarer',
            'rank_id' => $rank->id,
            'status' => 'active',
        ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'start_date' => '2026-01-01',
        'end_date' => null,
        'labor_contract_id' => null,
        'status' => 'active',
    ]);

    return compact('user', 'company', 'employee', 'rank');
}

test('employee crew status resolver returns available when no assignment exists', function () {
    ['employee' => $employee] = makeEmployeeCrewStatusFixtures();

    $resolver = new CrewAssignmentStatusResolver;
    $status = $resolver->forEmployee($employee);

    expect($status)
        ->assignment_id->toBeNull()
        ->status->toBe('in_home')
        ->label->toBe('Available');
});

test('employee crew status resolver returns on vessel with vessel name', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeEmployeeCrewStatusFixtures();

    $vessel = makeEmployeeCrewStatusVessel('MV Horizon');

    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $resolver = new CrewAssignmentStatusResolver;
    $status = $resolver->forEmployee($employee);

    expect($status)
        ->status->toBe('on_vessel')
        ->label->toBe('On vessel')
        ->current_vessel->toBe('MV Horizon')
        ->vessel_name->toBe('MV Horizon');
});

test('employee crew status resolver returns join standby during join standby phase', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeEmployeeCrewStatusFixtures();

    $vessel = makeEmployeeCrewStatusVessel('Vessel A');

    $assignment = CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-'.now()->year.'-STANDBY',
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Active,
        'started_at' => CarbonImmutable::today()->subDays(3),
        'source' => 'manual',
    ]);

    $phase = CrewAssignmentPhase::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::JoinStandby,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => CarbonImmutable::today()->subDay(),
    ]);

    $assignment->update(['current_phase_id' => $phase->id]);

    $resolver = new CrewAssignmentStatusResolver;
    $status = $resolver->forEmployee($employee);

    expect($status)
        ->status->toBe('join_standby')
        ->label->toBe('Join standby');
});

test('employee crew status resolver returns in home with day count', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeEmployeeCrewStatusFixtures();

    $vessel = makeEmployeeCrewStatusVessel('Vessel A');

    $assignment = CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-'.now()->year.'-HOME',
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Completed,
        'started_at' => CarbonImmutable::today()->subDays(40),
        'closed_at' => CarbonImmutable::today()->subDays(5),
        'source' => 'manual',
    ]);

    $resolver = new CrewAssignmentStatusResolver;
    $status = $resolver->forEmployee($employee);

    expect($status)
        ->status->toBe('in_home')
        ->label->toBe('In home · 5d')
        ->in_home_days->toBe(5);
});

test('employee crew status resolver returns needs update when active assignment has no current phase', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeEmployeeCrewStatusFixtures();

    $vessel = makeEmployeeCrewStatusVessel('Vessel A');

    $assignment = CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-'.now()->year.'-BROKEN',
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Active,
        'started_at' => CarbonImmutable::today()->subDays(4),
        'source' => 'manual',
        'current_phase_id' => null,
    ]);

    $resolver = new CrewAssignmentStatusResolver;
    $status = $resolver->forEmployee($employee);

    expect($status)
        ->status->toBe('movement_update_required')
        ->label->toBe('Needs update')
        ->warning->toBe('Active assignment has no current phase.');
});

test('employee profile includes crew status available when employee has no assignments', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeEmployeeCrewStatusFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->actingAs($user)
        ->get(route('organization.employees.show', $employee))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('employee.crew_status.status', 'in_home')
            ->where('employee.crew_status.label', 'Available')
            ->where('employee.crew_status.assignment_id', null));
});

test('employee profile includes crew status from latest assignment', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeEmployeeCrewStatusFixtures();

    $vessel = makeEmployeeCrewStatusVessel('Current Vessel');

    makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $olderVessel = makeEmployeeCrewStatusVessel('Older Vessel');
    CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-'.now()->year.'-OLD',
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $olderVessel->id,
        'status' => CrewAssignmentStatus::Completed,
        'started_at' => CarbonImmutable::today()->subDays(60),
        'closed_at' => CarbonImmutable::today()->subDays(50),
        'source' => 'manual',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->actingAs($user)
        ->get(route('organization.employees.show', $employee))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('employee.crew_status.status', 'on_vessel')
            ->where('employee.crew_status.label', 'On vessel'));
});

test('employee profile exposes assignments view permission in can payload', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeEmployeeCrewStatusFixtures();

    grantCompanyPermissions($user, $company, [
        'employees.view',
        'crew_operations.assignments.view',
    ]);

    $this->actingAs($user)
        ->get(route('organization.employees.show', $employee))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can.deployments_view', true));
});

test('employee profile hides crew status when disabled in profile template', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeEmployeeCrewStatusFixtures();

    $template = createEmployeeProfileTemplate(
        $company,
        'Office',
        employeeProfileTemplateWithVisibleEmployeeFields(['employee_no', 'name', 'work_email']),
    );

    $employee->update(['employee_profile_template_id' => $template->id]);

    $vessel = makeEmployeeCrewStatusVessel('Vessel A');
    makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $response = $this->actingAs($user)
        ->get(route('organization.employees.show', $employee));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('employee.crew_status.status', 'on_vessel'));

    $profileFields = $response->inertiaProps('employee_tabs.profile_fields');

    expect($profileFields)->toBeArray()
        ->and($profileFields)->not->toContain('crew_status');
});

test('employee profile includes crew status in profile fields when enabled in template', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeEmployeeCrewStatusFixtures();

    $configuration = EmployeeProfileTemplateFieldRegistry::defaultConfiguration();
    $configuration['fields']['employees']['crew_status']['visible'] = true;

    $template = createEmployeeProfileTemplate($company, 'Marine', $configuration);

    $employee->update(['employee_profile_template_id' => $template->id]);

    $vessel = makeEmployeeCrewStatusVessel('Vessel A');
    makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $response = $this->actingAs($user)
        ->get(route('organization.employees.show', $employee));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('employee.crew_status.status', 'on_vessel'));

    $profileFields = $response->inertiaProps('employee_tabs.profile_fields');

    expect($profileFields)->toBeArray()
        ->and($profileFields)->toContain('crew_status');
});

test('employee directory includes crew status on cards when enabled in profile template', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeEmployeeCrewStatusFixtures();

    $configuration = EmployeeProfileTemplateFieldRegistry::defaultConfiguration();
    $configuration['fields']['employees']['crew_status']['visible'] = true;

    $template = createEmployeeProfileTemplate($company, 'Marine', $configuration);

    $employee->update(['employee_profile_template_id' => $template->id]);

    $vessel = makeEmployeeCrewStatusVessel('Vessel A');
    makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->actingAs($user)
        ->get(route('organization.employees'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('employees', 1)
            ->where('employees.0.id', $employee->id)
            ->where('employees.0.crew_status.status', 'on_vessel')
            ->where('employees.0.crew_status.label', 'On vessel'));
});

test('employee directory omits crew status when disabled in profile template', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeEmployeeCrewStatusFixtures();

    $template = createEmployeeProfileTemplate(
        $company,
        'Office',
        employeeProfileTemplateWithVisibleEmployeeFields(['employee_no', 'name']),
    );

    $employee->update(['employee_profile_template_id' => $template->id]);

    $vessel = makeEmployeeCrewStatusVessel('Vessel A');
    makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->actingAs($user)
        ->get(route('organization.employees'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('employees', 1)
            ->where('employees.0.id', $employee->id)
            ->missing('employees.0.crew_status'));
});

test('employee directory can filter by crew status', function () {
    ['user' => $user, 'company' => $company, 'employee' => $onVesselEmployee, 'rank' => $rank] = makeEmployeeCrewStatusFixtures();

    $availableEmployee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => '3002',
            'name' => 'Available Seafarer',
            'rank_id' => $rank->id,
            'status' => 'active',
        ]);

    $vessel = makeEmployeeCrewStatusVessel('Filter Vessel');
    makeActiveOnVesselAssignment($company, $onVesselEmployee, $rank, $vessel);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->actingAs($user)
        ->get(route('organization.employees', ['crew_status' => 'in_home']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('employees', 1)
            ->where('employees.0.id', $availableEmployee->id)
            ->where('filters.crew_status', 'in_home'));

    $this->actingAs($user)
        ->get(route('organization.employees', ['crew_status' => 'on_vessel']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('employees', 1)
            ->where('employees.0.id', $onVesselEmployee->id)
            ->where('filters.crew_status', 'on_vessel'));
});
