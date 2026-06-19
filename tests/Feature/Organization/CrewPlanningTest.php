<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\CrewOperationsSetting;
use App\Models\CrewPlanningAssignment;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeDeployment;
use App\Models\Rank;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselManning;
use App\Models\VesselType;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

function makeCrewPlanningFixtures(): array
{
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'CPL',
        'name' => 'Crew Planning Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CPL',
        'name' => 'Crew Planning Currency',
        'symbol' => 'C$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Crew Planning Co',
        'slug' => 'crew-planning-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $otherCompany = Company::query()->create([
        'name' => 'Other Planning Co',
        'slug' => 'other-planning-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $vesselType = VesselType::query()->create(['name' => 'AHTS-CPL', 'is_active' => true]);

    $vessel = Vessel::query()->create([
        'name' => 'Planning Vessel Alpha',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $captain = Rank::query()->create(['name' => 'Captain CPL', 'is_active' => true]);
    $chiefOfficer = Rank::query()->create(['name' => 'Chief Officer CPL', 'is_active' => true]);

    grantCompanyPermissions($user, $company, ['crew_operations.planning.view']);

    return compact('user', 'company', 'otherCompany', 'vessel', 'captain', 'chiefOfficer');
}

test('guests cannot access crew planning', function () {
    $this->get(route('organization.crew-planning.index'))
        ->assertRedirect(route('login'));
});

test('users without view permission cannot access crew planning', function () {
    ['user' => $user, 'company' => $company] = makeCrewPlanningFixtures();

    grantCompanyPermissions($user, $company, []);

    $this->actingAs($user)
        ->get(route('organization.crew-planning.index'))
        ->assertForbidden();
});

test('authorized users can view the crew planning index', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'captain' => $captain] = makeCrewPlanningFixtures();

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'required_count' => 1,
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-planning.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-planning/index')
            ->has('rows')
            ->has('bars')
            ->has('tree')
            ->has('filters')
            ->where('can.view', true)
        );
});

test('planning crew list includes employees with active deployments', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'captain' => $captain] = makeCrewPlanningFixtures();

    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'rank_id' => $captain->id,
        'name' => 'Deployed Crew',
    ]);

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'joined_date' => CarbonImmutable::today()->subDays(5),
        'disembarked_date' => CarbonImmutable::today()->addMonths(2),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-planning.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 1)
            ->where('employees.0.id', $employee->id)
            ->where('employees.0.name', 'Deployed Crew')
        );
});

test('planning index employees list respects pool department settings', function () {
    ['user' => $user, 'company' => $company, 'captain' => $captain] = makeCrewPlanningFixtures();

    $crewDept = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Deck Crew',
        'code' => 'DECK',
        'status' => 'active',
    ]);

    $officeDept = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Office Staff',
        'code' => 'OFF',
        'status' => 'active',
    ]);

    $crewMember = Employee::factory()->create([
        'company_id' => $company->id,
        'department_id' => $crewDept->id,
        'rank_id' => $captain->id,
        'name' => 'Alpha Crew',
    ]);

    Employee::factory()->create([
        'company_id' => $company->id,
        'department_id' => $officeDept->id,
        'rank_id' => $captain->id,
        'name' => 'Beta Office',
    ]);

    CrewOperationsSetting::query()->create([
        'company_id' => $company->id,
        'pool_department_ids' => [$crewDept->id],
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-planning.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 1)
            ->where('employees.0.id', $crewMember->id)
            ->where('employees.0.rank_id', $captain->id)
            ->where('employees.0.rank_name', $captain->name)
        );
});

test('planning index only includes employees with a profile rank', function () {
    ['user' => $user, 'company' => $company, 'captain' => $captain, 'chiefOfficer' => $chiefOfficer] = makeCrewPlanningFixtures();

    $ranked = Employee::factory()->create([
        'company_id' => $company->id,
        'rank_id' => $captain->id,
        'name' => 'Ranked Crew',
    ]);

    Employee::factory()->create([
        'company_id' => $company->id,
        'rank_id' => null,
        'name' => 'Unranked Crew',
    ]);

    $anotherRanked = Employee::factory()->create([
        'company_id' => $company->id,
        'rank_id' => $chiefOfficer->id,
        'name' => 'Another Ranked Crew',
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-planning.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 2)
            ->where('employees', fn ($employees) => collect($employees)->pluck('id')->sort()->values()->all() === collect([$ranked->id, $anotherRanked->id])->sort()->values()->all())
        );
});

test('planning pool settings include employees from child departments when parent is selected', function () {
    ['user' => $user, 'company' => $company, 'captain' => $captain] = makeCrewPlanningFixtures();

    $parentDept = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Marine',
        'code' => 'MAR',
        'status' => 'active',
    ]);

    $childDept = Department::query()->create([
        'company_id' => $company->id,
        'parent_id' => $parentDept->id,
        'name' => 'Marine Officers',
        'code' => 'MAR-OFF',
        'status' => 'active',
    ]);

    $parentEmployee = Employee::factory()->create([
        'company_id' => $company->id,
        'department_id' => $parentDept->id,
        'rank_id' => $captain->id,
        'name' => 'Parent Crew',
    ]);

    $childEmployee = Employee::factory()->create([
        'company_id' => $company->id,
        'department_id' => $childDept->id,
        'rank_id' => $captain->id,
        'name' => 'Child Crew',
    ]);

    CrewOperationsSetting::query()->create([
        'company_id' => $company->id,
        'pool_department_ids' => [$parentDept->id],
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-planning.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 2)
            ->where('employees', fn ($employees) => collect($employees)->pluck('id')->sort()->values()->all() === collect([$parentEmployee->id, $childEmployee->id])->sort()->values()->all())
        );
});

test('rows are returned from vessel manning', function () {
    [
        'user' => $user,
        'company' => $company,
        'vessel' => $vessel,
        'captain' => $captain,
        'chiefOfficer' => $chiefOfficer,
    ] = makeCrewPlanningFixtures();

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'required_count' => 1,
    ]);

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $chiefOfficer->id,
        'required_count' => 2,
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-planning.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-planning/index')
            ->has('rows', 1)
            ->where('rows.0.vessel_name', 'Planning Vessel Alpha')
            ->has('rows.0.ranks', 2)
        );
});

test('bars are returned for assignments overlapping the date range', function () {
    [
        'user' => $user,
        'company' => $company,
        'vessel' => $vessel,
        'captain' => $captain,
    ] = makeCrewPlanningFixtures();

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'required_count' => 1,
    ]);

    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'rank_id' => $captain->id,
    ]);

    $today = CarbonImmutable::today();
    $from = $today->startOfMonth()->toDateString();
    $to = $today->addMonths(2)->endOfMonth()->toDateString();

    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'planned_join_date' => $today->subDays(10)->toDateString(),
        'planned_leave_date' => $today->addDays(30)->toDateString(),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-planning.index', compact('from', 'to')))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('bars', 1)
            ->where('bars.0.employee_id', $employee->id)
            ->where('bars.0.planned_join_date', $today->subDays(10)->toDateString())
        );
});

test('bars outside the date range are excluded', function () {
    [
        'user' => $user,
        'company' => $company,
        'vessel' => $vessel,
        'captain' => $captain,
    ] = makeCrewPlanningFixtures();

    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'rank_id' => $captain->id,
    ]);

    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'planned_join_date' => '2020-01-01',
        'planned_leave_date' => '2020-03-01',
    ]);

    $from = '2025-01-01';
    $to = '2025-03-31';

    $this->actingAs($user)
        ->get(route('organization.crew-planning.index', compact('from', 'to')))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('bars', 0)
        );
});

test('vessel filter narrows rows, bars, and tree', function () {
    [
        'user' => $user,
        'company' => $company,
        'vessel' => $vessel,
        'captain' => $captain,
    ] = makeCrewPlanningFixtures();

    $vesselType = VesselType::query()->create(['name' => 'Other VT', 'is_active' => true]);

    $otherVessel = Vessel::query()->create([
        'name' => 'Other Vessel',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'required_count' => 1,
    ]);

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $otherVessel->id,
        'rank_id' => $captain->id,
        'required_count' => 1,
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-planning.index', ['vessel_id' => $vessel->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.vessel_name', 'Planning Vessel Alpha')
            ->has('tree', 1)
            ->where('tree.0.vessel_name', 'Planning Vessel Alpha')
        );
});

test('rank filter narrows rows, bars, and tree', function () {
    [
        'user' => $user,
        'company' => $company,
        'vessel' => $vessel,
        'captain' => $captain,
        'chiefOfficer' => $chiefOfficer,
    ] = makeCrewPlanningFixtures();

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'required_count' => 1,
    ]);

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $chiefOfficer->id,
        'required_count' => 1,
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-planning.index', ['rank_id' => $captain->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.ranks.0.rank_name', 'Captain CPL')
            ->has('tree', 1)
            ->has('tree.0.ranks', 1)
            ->where('tree.0.ranks.0.rank_name', 'Captain CPL')
        );
});

test('planning data is scoped to current company', function () {
    [
        'user' => $user,
        'company' => $company,
        'otherCompany' => $otherCompany,
        'vessel' => $vessel,
        'captain' => $captain,
    ] = makeCrewPlanningFixtures();

    VesselManning::query()->create([
        'company_id' => $otherCompany->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'required_count' => 3,
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-planning.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 0)
            ->has('bars', 0)
        );
});
