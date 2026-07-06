<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeDeployment;
use App\Models\Rank;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselType;
use App\Support\CrewDeployments\DeploymentStatus;
use App\Support\CrewDeployments\EmployeeCrewStatusPresenter;
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

test('employee crew status presenter returns available when no deployment exists', function () {
    $status = EmployeeCrewStatusPresenter::fromDeployment(null);

    expect($status)
        ->deployment_id->toBeNull()
        ->status->toBe('available')
        ->label->toBe('Available')
        ->hint->toBeNull();
});

test('employee crew status presenter returns on vessel with vessel name', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeEmployeeCrewStatusFixtures();

    $vessel = makeEmployeeCrewStatusVessel('MV Horizon');

    $deployment = EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'joined_date' => CarbonImmutable::today()->subDays(2),
    ]);

    $status = EmployeeCrewStatusPresenter::fromDeployment($deployment);

    expect($status)
        ->status->toBe(DeploymentStatus::ON_VESSEL)
        ->label->toBe('On vessel')
        ->current_vessel->toBe('MV Horizon')
        ->vessel_name->toBe('MV Horizon');
});

test('employee crew status presenter returns join standby during standby window', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeEmployeeCrewStatusFixtures();

    $deployment = EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeEmployeeCrewStatusVessel('Vessel A')->id,
        'arrived_date' => CarbonImmutable::today()->subDays(3),
        'join_standby_from' => CarbonImmutable::today()->subDay(),
        'join_standby_to' => CarbonImmutable::today()->addDay(),
    ]);

    $status = EmployeeCrewStatusPresenter::fromDeployment($deployment);

    expect($status)
        ->status->toBe(DeploymentStatus::JOIN_STANDBY)
        ->label->toBe('Join standby');
});

test('employee crew status presenter returns in home with day count', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeEmployeeCrewStatusFixtures();

    $deployment = EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeEmployeeCrewStatusVessel('Vessel A')->id,
        'joined_date' => CarbonImmutable::today()->subDays(40),
        'disembarked_date' => CarbonImmutable::today()->subDays(10),
        'travelled_date' => CarbonImmutable::today()->subDays(5),
    ]);

    $status = EmployeeCrewStatusPresenter::fromDeployment($deployment);

    expect($status)
        ->status->toBe(DeploymentStatus::IN_HOME)
        ->label->toBe('In home · 6d')
        ->in_home_days->toBe(6);
});

test('employee crew status presenter uses disembarked best guess when board would show needs update', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeEmployeeCrewStatusFixtures();

    $deployment = EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeEmployeeCrewStatusVessel('Vessel A')->id,
        'joined_date' => CarbonImmutable::today()->subDays(4),
        'disembarked_date' => CarbonImmutable::today()->subDays(3),
    ]);

    $boardStatus = DeploymentStatus::resolve($deployment);

    expect($boardStatus['status'])->toBe(DeploymentStatus::UNKNOWN)
        ->and($boardStatus['label'])->toBe('Needs update');

    $status = EmployeeCrewStatusPresenter::fromDeployment($deployment);

    expect($status)
        ->status->toBe(DeploymentStatus::DISEMBARKED)
        ->label->toBe('Disembarked')
        ->hint->toBeNull();
});

test('employee profile includes crew status available when employee has no deployments', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeEmployeeCrewStatusFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->actingAs($user)
        ->get(route('organization.employees.show', $employee))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('employee.crew_status.status', 'available')
            ->where('employee.crew_status.label', 'Available')
            ->where('employee.crew_status.deployment_id', null));
});

test('employee profile includes crew status from latest deployment', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeEmployeeCrewStatusFixtures();

    $vessel = makeEmployeeCrewStatusVessel('Current Vessel');

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'sort_order' => 1,
        'joined_date' => CarbonImmutable::today()->subDays(2),
    ]);

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeEmployeeCrewStatusVessel('Older Vessel')->id,
        'sort_order' => 0,
        'joined_date' => CarbonImmutable::today()->subDays(60),
        'disembarked_date' => CarbonImmutable::today()->subDays(50),
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->actingAs($user)
        ->get(route('organization.employees.show', $employee))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('employee.crew_status.status', DeploymentStatus::ON_VESSEL)
            ->where('employee.crew_status.label', 'On vessel'));
});

test('employee profile exposes deployments view permission in can payload', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeEmployeeCrewStatusFixtures();

    grantCompanyPermissions($user, $company, [
        'employees.view',
        'crew_operations.deployments.view',
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

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeEmployeeCrewStatusVessel('Vessel A')->id,
        'joined_date' => CarbonImmutable::today()->subDay(),
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $response = $this->actingAs($user)
        ->get(route('organization.employees.show', $employee));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('employee.crew_status.status', DeploymentStatus::ON_VESSEL));

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

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeEmployeeCrewStatusVessel('Vessel A')->id,
        'joined_date' => CarbonImmutable::today()->subDay(),
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $response = $this->actingAs($user)
        ->get(route('organization.employees.show', $employee));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('employee.crew_status.status', DeploymentStatus::ON_VESSEL));

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

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeEmployeeCrewStatusVessel('Vessel A')->id,
        'joined_date' => CarbonImmutable::today()->subDay(),
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->actingAs($user)
        ->get(route('organization.employees'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('employees', 1)
            ->where('employees.0.id', $employee->id)
            ->where('employees.0.crew_status.status', DeploymentStatus::ON_VESSEL)
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

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeEmployeeCrewStatusVessel('Vessel A')->id,
        'joined_date' => CarbonImmutable::today()->subDay(),
    ]);

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

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $onVesselEmployee->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeEmployeeCrewStatusVessel('Filter Vessel')->id,
        'joined_date' => CarbonImmutable::today()->subDay(),
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->actingAs($user)
        ->get(route('organization.employees', ['crew_status' => 'available']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('employees', 1)
            ->where('employees.0.id', $availableEmployee->id)
            ->where('filters.crew_status', 'available'));

    $this->actingAs($user)
        ->get(route('organization.employees', ['crew_status' => DeploymentStatus::ON_VESSEL]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('employees', 1)
            ->where('employees.0.id', $onVesselEmployee->id)
            ->where('filters.crew_status', DeploymentStatus::ON_VESSEL));
});
