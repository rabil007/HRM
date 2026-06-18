<?php

use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyVisaType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeDeployment;
use App\Models\EmployeeSeaService;
use App\Models\Rank;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselType;
use App\Support\CrewDeployments\DeploymentStatus;
use App\Support\Employees\SeaServiceDuration;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

function makeCrewDeploymentVessel(string $name): Vessel
{
    $vesselType = VesselType::query()->firstOrCreate(
        ['name' => 'Crew Deployment Test Type'],
        ['is_active' => true],
    );

    return Vessel::query()->firstOrCreate(
        ['name' => $name],
        ['vessel_type_id' => $vesselType->id, 'is_active' => true],
    );
}

function makeCrewDeploymentFixtures(): array
{
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'CRW',
        'name' => 'Crewland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CRW',
        'name' => 'Crew Currency',
        'symbol' => 'C$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Crew Co',
        'slug' => 'crew-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $rank = Rank::query()->create([
        'name' => 'SM',
        'is_active' => true,
    ]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => '2018',
            'name' => 'Boby Jahja',
            'rank_id' => $rank->id,
            'status' => 'active',
        ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'unlimited',
        'start_date' => '2026-01-01',
        'end_date' => null,
        'labor_contract_id' => null,
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, [
        'crew_operations.deployments.view',
        'crew_operations.deployments.create',
        'crew_operations.deployments.update',
        'crew_operations.deployments.delete',
        'crew_operations.deployments.export',
    ]);

    return compact('user', 'company', 'employee', 'rank');
}

test('guests cannot access crew deployments', function () {
    $this->get(route('organization.crew-deployments.index'))
        ->assertRedirect(route('login'));
});

test('authorized users can view crew deployment board', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    $client = Client::query()->create(['name' => 'JDL', 'is_active' => true]);
    $companyVisaType = CompanyVisaType::query()->create(['name' => 'EXPERTS', 'is_active' => true]);

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'client_id' => $client->id,
        'company_visa_type_id' => $companyVisaType->id,
        'vessel_id' => makeCrewDeploymentVessel('JDL')->id,
        'joined_date' => CarbonImmutable::today()->subDays(5),
        'disembarked_date' => CarbonImmutable::today()->addDays(25),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-deployments/index')
            ->has('deployments.data', 1)
            ->where('deployments.data.0.employee_no', '2018')
            ->where('deployments.data.0.status', DeploymentStatus::ON_VESSEL)
            ->where('summary.on_vessel', 1)
            ->where('summary.total', 1)
            ->has('status_rules')
            ->where('status_rules.in_home.title', 'In home'));
});

test('crew deployment board accepts view parameter', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeCrewDeploymentVessel('View Test Vessel')->id,
        'joined_date' => CarbonImmutable::today(),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.index', ['view' => 'board']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-deployments/index')
            ->where('filters.view', 'board')
            ->has('deployments.data', 1));

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.index', ['view' => 'table']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-deployments/index')
            ->where('filters.view', 'table')
            ->has('deployments.data', 1));
});

test('crew deployment board shows hire date from employee record', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    $employee->update(['hire_date' => '2024-03-15']);

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeCrewDeploymentVessel('Hire Date Vessel')->id,
        'joined_date' => CarbonImmutable::today()->subDay(),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('deployments.data.0.hire_date', '2024-03-15'));
});

test('authorized users can store update and destroy crew deployments', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    $client = Client::query()->create(['name' => 'Berltiz', 'is_active' => true]);
    $companyVisaType = CompanyVisaType::query()->create(['name' => 'High Land', 'is_active' => true]);

    $this->actingAs($user)
        ->post(route('organization.crew-deployments.store'), [
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'client_id' => $client->id,
            'company_visa_type_id' => $companyVisaType->id,
            'vessel_id' => makeCrewDeploymentVessel('Safeen OSV Pearl')->id,
            'joined_date' => '2024-11-26',
            'disembarked_date' => '2025-01-26',
            'remarks' => 'Test remark',
        ])
        ->assertRedirect();

    $deployment = EmployeeDeployment::query()->where('employee_id', $employee->id)->first();

    expect($deployment)->not->toBeNull()
        ->and($deployment->vessel?->name)->toBe('Safeen OSV Pearl');

    $this->actingAs($user)
        ->put(route('organization.crew-deployments.update', $deployment), [
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'client_id' => $client->id,
            'company_visa_type_id' => $companyVisaType->id,
            'vessel_id' => makeCrewDeploymentVessel('Cecilie K')->id,
            'joined_date' => '2024-11-26',
            'disembarked_date' => '2025-02-26',
        ])
        ->assertRedirect();

    $deployment->refresh();
    expect($deployment->vessel?->name)->toBe('Cecilie K');

    $this->actingAs($user)
        ->delete(route('organization.crew-deployments.destroy', $deployment))
        ->assertRedirect();

    expect(EmployeeDeployment::query()->find($deployment->id))->toBeNull();
});

test('store rejects employees from another company during validation', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewDeploymentFixtures();

    $otherCompany = Company::query()->create([
        'name' => 'Other Deployment Co',
        'slug' => 'other-deployment-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $company->country_id,
        'currency_id' => $company->currency_id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $otherEmployee = Employee::factory()
        ->forCompany($otherCompany)
        ->create(['status' => 'active']);

    $this->actingAs($user)
        ->post(route('organization.crew-deployments.store'), [
            'employee_id' => $otherEmployee->id,
            'rank_id' => $rank->id,
            'vessel_id' => makeCrewDeploymentVessel('Rejected Vessel')->id,
            'joined_date' => '2024-11-26',
        ])
        ->assertSessionHasErrors('employee_id');

    expect(EmployeeDeployment::query()->where('employee_id', $otherEmployee->id)->exists())->toBeFalse();
});

test('deployment board shows all assignment records per employee', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeCrewDeploymentVessel('Old Vessel')->id,
        'joined_date' => CarbonImmutable::today()->subMonths(6),
        'disembarked_date' => CarbonImmutable::today()->subMonths(4),
    ]);

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeCrewDeploymentVessel('Current Vessel')->id,
        'joined_date' => CarbonImmutable::today()->subDays(3),
        'disembarked_date' => CarbonImmutable::today()->addDays(30),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('deployments.data', 2)
            ->where('deployments.data.0.vessel_name', 'Current Vessel')
            ->where('deployments.data.1.vessel_name', 'Old Vessel'));
});

test('authorized users can download crew deployment export', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeCrewDeploymentVessel('Export Vessel')->id,
        'joined_date' => '2024-01-01',
        'disembarked_date' => '2024-03-01',
    ]);

    $response = $this->actingAs($user)
        ->get(route('organization.crew-deployments.export'));

    $response->assertOk();
    expect($response->headers->get('content-type'))
        ->toContain('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

test('crew deployment board can sort assignments by employee name', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewDeploymentFixtures();

    $employeeAlpha = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'CD100',
            'name' => 'Alpha Crew',
            'rank_id' => $rank->id,
            'status' => 'active',
        ]);

    $employeeZulu = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'CD200',
            'name' => 'Zulu Crew',
            'rank_id' => $rank->id,
            'status' => 'active',
        ]);

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employeeZulu->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeCrewDeploymentVessel('Zulu Vessel')->id,
        'joined_date' => CarbonImmutable::today()->subDay(),
    ]);

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employeeAlpha->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeCrewDeploymentVessel('Alpha Vessel')->id,
        'joined_date' => CarbonImmutable::today()->subDays(2),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.index', [
            'sort' => 'employee_name',
            'direction' => 'asc',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('deployments.data', 2)
            ->where('deployments.data.0.employee_name', 'Alpha Crew')
            ->where('deployments.data.1.employee_name', 'Zulu Crew')
            ->where('filters.sort', 'employee_name')
            ->where('filters.direction', 'asc'));
});

test('crew deployment board can sort assignments by vessel days', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeCrewDeploymentVessel('Short Tour')->id,
        'joined_date' => '2024-01-01',
        'disembarked_date' => '2024-01-10',
    ]);

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeCrewDeploymentVessel('Long Tour')->id,
        'joined_date' => '2024-02-01',
        'disembarked_date' => '2024-03-01',
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.index', [
            'sort' => 'vessel_days',
            'direction' => 'desc',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('deployments.data', 2)
            ->where('deployments.data.0.vessel_name', 'Long Tour')
            ->where('deployments.data.0.vessel_days', 30)
            ->where('deployments.data.1.vessel_name', 'Short Tour')
            ->where('deployments.data.1.vessel_days', 10));
});

test('crew deployment board marks overdue arrivals without join date as needs update', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeCrewDeploymentVessel('Vessel A')->id,
        'arrived_date' => CarbonImmutable::today()->subDay(),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('deployments.data.0.status', DeploymentStatus::UNKNOWN)
            ->where('deployments.data.0.status_label', 'Needs update')
            ->where('deployments.data.0.status_hint', 'Arrived 1d ago — add join date')
            ->where('deployments.data.0.overdue_date_fields', ['arrived_date'])
            ->where('deployments.data.0.due_soon_date_fields', [])
            ->where('summary.unknown', 1)
            ->where('summary.arrived', 0));
});

test('crew deployment board treats open ended join standby as join standby status', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeCrewDeploymentVessel('Vessel A')->id,
        'arrived_date' => CarbonImmutable::today()->subDays(10),
        'join_standby_from' => CarbonImmutable::today()->subDays(9),
        'join_standby_to' => null,
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('deployments.data.0.status', DeploymentStatus::JOIN_STANDBY)
            ->where('deployments.data.0.status_label', 'Join standby')
            ->where('summary.join_standby', 1)
            ->where('summary.unknown', 0));
});

test('crew deployment board marks past disembark without follow up dates as needs update', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeCrewDeploymentVessel('Vessel A')->id,
        'joined_date' => CarbonImmutable::today()->subDays(4),
        'disembarked_date' => CarbonImmutable::today()->subDays(3),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('deployments.data.0.status', DeploymentStatus::UNKNOWN)
            ->where('deployments.data.0.status_label', 'Needs update')
            ->where('deployments.data.0.status_hint', 'Disembarked 3d ago — add travel or standby')
            ->where('deployments.data.0.overdue_date_fields', ['disembarked_date'])
            ->where('summary.unknown', 1)
            ->where('summary.disembarked', 0));
});

test('crew deployment board marks overdue leave standby without travel as needs update', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeCrewDeploymentVessel('Vessel A')->id,
        'joined_date' => CarbonImmutable::today()->subDays(4),
        'disembarked_date' => CarbonImmutable::today()->subDays(3),
        'leave_standby_from' => CarbonImmutable::today()->subDays(2),
        'leave_standby_to' => CarbonImmutable::today()->subDay(),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('deployments.data.0.status', DeploymentStatus::UNKNOWN)
            ->where('deployments.data.0.status_label', 'Needs update')
            ->where('deployments.data.0.status_hint', 'Leave standby ended 1d ago — add travel date')
            ->where('deployments.data.0.overdue_date_fields', ['leave_standby_to'])
            ->where('deployments.data.0.due_soon_date_fields', [])
            ->where('summary.unknown', 1)
            ->where('summary.disembarked', 0));
});

test('guests cannot access crew deployment show page', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    $deployment = EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeCrewDeploymentVessel('Guest Vessel')->id,
    ]);

    $this->get(route('organization.crew-deployments.show', $deployment))
        ->assertRedirect(route('login'));
});

test('authorized users can view crew deployment show page', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    $deployment = EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeCrewDeploymentVessel('Detail Vessel')->id,
        'joined_date' => '2024-11-26',
        'disembarked_date' => '2025-01-26',
        'remarks' => 'Detail remark',
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.show', $deployment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-deployments/show')
            ->where('deployment.id', $deployment->id)
            ->where('deployment.vessel_name', 'Detail Vessel')
            ->where('deployment.employee_no', '2018')
            ->where('deployment.remarks', 'Detail remark')
            ->has('back_query'));
});

test('users cannot view crew deployment from another company', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    $otherCompany = Company::query()->create([
        'name' => 'Other Co',
        'slug' => 'other-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $company->country_id,
        'currency_id' => $company->currency_id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $otherEmployee = Employee::factory()
        ->forCompany($otherCompany)
        ->create([
            'employee_no' => '9999',
            'name' => 'Other Crew',
            'rank_id' => $rank->id,
            'status' => 'active',
        ]);

    $deployment = EmployeeDeployment::query()->create([
        'company_id' => $otherCompany->id,
        'employee_id' => $otherEmployee->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeCrewDeploymentVessel('Foreign Vessel')->id,
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.show', $deployment))
        ->assertNotFound();
});

test('deployment show page hides recent activity without audit permission', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    $deployment = EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeCrewDeploymentVessel('Audit Hidden Vessel')->id,
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.show', $deployment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can_view_audit', false)
            ->where('recent_activity', []));
});

test('deployment show page exposes recent activity with audit permission', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.deployments.view',
        'crew_operations.deployments.update',
        'audit.view',
    ]);

    $deployment = EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeCrewDeploymentVessel('Audit Visible Vessel')->id,
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.show', $deployment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can_view_audit', true)
            ->has('recent_activity', 1)
            ->where('recent_activity.0.event', 'created'));
});

test('updating a deployment records activity and can redirect to show page', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    $deployment = EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeCrewDeploymentVessel('Before Update')->id,
        'joined_date' => '2024-01-01',
    ]);

    $this->actingAs($user)
        ->put(route('organization.crew-deployments.update', $deployment), [
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'vessel_id' => makeCrewDeploymentVessel('After Update')->id,
            'joined_date' => '2024-02-01',
            'redirect_to' => 'show',
        ])
        ->assertRedirect(route('organization.crew-deployments.show', $deployment));

    expect($deployment->fresh()->load('vessel')->vessel?->name)->toBe('After Update');

    $activity = Activity::query()
        ->where('subject_type', EmployeeDeployment::class)
        ->where('subject_id', $deployment->id)
        ->where('event', 'updated')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->attribute_changes?->get('attributes'))->toMatchArray([
            'vessel_id' => makeCrewDeploymentVessel('After Update')->id,
        ])
        ->and($activity->attribute_changes?->get('attributes'))->toHaveKey('joined_date');
});

test('crew deployment board counts in home from latest travelled record per employee', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'sort_order' => 0,
        'vessel_id' => makeCrewDeploymentVessel('Old Vessel')->id,
        'joined_date' => CarbonImmutable::today()->subMonths(6),
        'disembarked_date' => CarbonImmutable::today()->subMonths(5),
        'travelled_date' => CarbonImmutable::today()->subMonths(4),
    ]);

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'sort_order' => 1,
        'vessel_id' => makeCrewDeploymentVessel('Return Pool')->id,
        'join_standby_from' => CarbonImmutable::today()->subDay(),
        'join_standby_to' => CarbonImmutable::today()->addDays(5),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.in_home', 0)
            ->where('summary.travel', 1));
});

test('crew deployment board in home filter shows latest travelled record with in home days', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'sort_order' => 0,
        'vessel_id' => makeCrewDeploymentVessel('At Home Vessel')->id,
        'joined_date' => CarbonImmutable::today()->subDays(20),
        'disembarked_date' => CarbonImmutable::today()->subDays(10),
        'travelled_date' => CarbonImmutable::today()->subDays(4),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.in_home', 1));

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.index', [
            'status' => DeploymentStatus::IN_HOME,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('deployments.data', 1)
            ->where('deployments.data.0.status', DeploymentStatus::TRAVEL)
            ->where('deployments.data.0.in_home_days', 5));
});

test('crew deployment board excludes needs update and future travel from in home', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeCrewDeploymentVessel('Needs Update')->id,
        'joined_date' => CarbonImmutable::today()->subDays(6),
        'disembarked_date' => CarbonImmutable::today()->subDays(3),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.in_home', 0));

    $employee->update(['employee_no' => '2019', 'name' => 'Future Travel']);

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeCrewDeploymentVessel('Future Travel Vessel')->id,
        'joined_date' => CarbonImmutable::today()->subDays(6),
        'disembarked_date' => CarbonImmutable::today()->subDays(2),
        'travelled_date' => CarbonImmutable::today()->addDay(),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.in_home', 0));

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.index', [
            'status' => DeploymentStatus::IN_HOME,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('deployments.data', 0));
});

test('crew deployment board in home summary respects rank filter on latest record', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    $otherRank = Rank::query()->create(['name' => 'AB', 'is_active' => true]);

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeCrewDeploymentVessel('At Home Vessel')->id,
        'joined_date' => CarbonImmutable::today()->subDays(20),
        'disembarked_date' => CarbonImmutable::today()->subDays(10),
        'travelled_date' => CarbonImmutable::today()->subDays(4),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.index', [
            'rank_id' => $otherRank->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.in_home', 0));
});

test('crew deployment board can filter by join standby status', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeCrewDeploymentVessel('Join Standby Pool')->id,
        'join_standby_from' => CarbonImmutable::today()->subDay(),
        'join_standby_to' => CarbonImmutable::today()->addDays(5),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-deployments.index', [
            'status' => DeploymentStatus::JOIN_STANDBY,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('deployments.data', 1)
            ->where('deployments.data.0.status', DeploymentStatus::JOIN_STANDBY));
});

test('updating a deployment with joined and disembarked dates creates linked sea service', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    $client = Client::query()->create(['name' => 'Berltiz', 'is_active' => true]);
    $vessel = makeCrewDeploymentVessel('Safeen OSV Pearl');
    $duration = SeaServiceDuration::fromDates('2024-11-26', '2025-01-26');

    $this->actingAs($user)
        ->post(route('organization.crew-deployments.store'), [
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'client_id' => $client->id,
            'vessel_id' => $vessel->id,
            'joined_date' => '2024-11-26',
            'disembarked_date' => '2025-01-26',
        ])
        ->assertRedirect();

    $deployment = EmployeeDeployment::query()->where('employee_id', $employee->id)->first();
    $seaService = EmployeeSeaService::query()->where('employee_deployment_id', $deployment->id)->first();

    expect($seaService)->not->toBeNull()
        ->and($seaService->employee_id)->toBe($employee->id)
        ->and($seaService->company_id)->toBe($company->id)
        ->and($seaService->vessel_id)->toBe($vessel->id)
        ->and($seaService->vessel_type_id)->toBe($vessel->vessel_type_id)
        ->and($seaService->rank_id)->toBe($rank->id)
        ->and($seaService->client_id)->toBe($client->id)
        ->and($seaService->start_date?->toDateString())->toBe('2024-11-26')
        ->and($seaService->end_date?->toDateString())->toBe('2025-01-26')
        ->and($seaService->total_months)->toBe($duration['months'])
        ->and($seaService->total_days)->toBe($duration['days']);
});

test('re-updating a deployment updates the same linked sea service record', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    $this->actingAs($user)
        ->post(route('organization.crew-deployments.store'), [
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'vessel_id' => makeCrewDeploymentVessel('First Vessel')->id,
            'joined_date' => '2024-11-26',
            'disembarked_date' => '2025-01-26',
        ])
        ->assertRedirect();

    $deployment = EmployeeDeployment::query()->where('employee_id', $employee->id)->first();
    $originalSeaServiceId = EmployeeSeaService::query()
        ->where('employee_deployment_id', $deployment->id)
        ->value('id');

    $replacementVessel = makeCrewDeploymentVessel('Cecilie K');

    $this->actingAs($user)
        ->put(route('organization.crew-deployments.update', $deployment), [
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $replacementVessel->id,
            'joined_date' => '2024-11-26',
            'disembarked_date' => '2025-02-26',
        ])
        ->assertRedirect();

    expect(EmployeeSeaService::query()->where('employee_deployment_id', $deployment->id)->count())->toBe(1);

    $seaService = EmployeeSeaService::query()->find($originalSeaServiceId);

    expect($seaService)->not->toBeNull()
        ->and($seaService->vessel_id)->toBe($replacementVessel->id)
        ->and($seaService->end_date?->toDateString())->toBe('2025-02-26');
});

test('clearing disembarked date removes linked sea service', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    $this->actingAs($user)
        ->post(route('organization.crew-deployments.store'), [
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'vessel_id' => makeCrewDeploymentVessel('Active Vessel')->id,
            'joined_date' => '2024-11-26',
            'disembarked_date' => '2025-01-26',
        ])
        ->assertRedirect();

    $deployment = EmployeeDeployment::query()->where('employee_id', $employee->id)->first();

    expect(EmployeeSeaService::query()->where('employee_deployment_id', $deployment->id)->exists())->toBeTrue();

    $this->actingAs($user)
        ->put(route('organization.crew-deployments.update', $deployment), [
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $deployment->vessel_id,
            'joined_date' => '2024-11-26',
            'disembarked_date' => null,
        ])
        ->assertRedirect();

    expect(EmployeeSeaService::query()->where('employee_deployment_id', $deployment->id)->exists())->toBeFalse();
});

test('deployment without vessel does not create linked sea service', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    $this->actingAs($user)
        ->post(route('organization.crew-deployments.store'), [
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'joined_date' => '2024-11-26',
            'disembarked_date' => '2025-01-26',
        ])
        ->assertRedirect();

    $deployment = EmployeeDeployment::query()->where('employee_id', $employee->id)->first();

    expect(EmployeeSeaService::query()->where('employee_deployment_id', $deployment->id)->exists())->toBeFalse();
});

test('deleting a deployment removes linked sea service', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    $this->actingAs($user)
        ->post(route('organization.crew-deployments.store'), [
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'vessel_id' => makeCrewDeploymentVessel('Delete Vessel')->id,
            'joined_date' => '2024-11-26',
            'disembarked_date' => '2025-01-26',
        ])
        ->assertRedirect();

    $deployment = EmployeeDeployment::query()->where('employee_id', $employee->id)->first();
    $seaServiceId = EmployeeSeaService::query()
        ->where('employee_deployment_id', $deployment->id)
        ->value('id');

    $this->actingAs($user)
        ->delete(route('organization.crew-deployments.destroy', $deployment))
        ->assertRedirect();

    expect(EmployeeSeaService::query()->find($seaServiceId))->toBeNull();
});
