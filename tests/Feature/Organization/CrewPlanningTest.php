<?php

use App\Enums\CrewProjectedManningStatus;
use App\Models\Company;
use App\Models\Country;
use App\Models\CrewAssignment;
use App\Models\CrewOperationsSetting;
use App\Models\CrewPlanningAssignment;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\Rank;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselManning;
use App\Models\VesselType;
use App\Support\CrewOperations\CrewProjectedManningQuery;
use App\Support\CrewPlanning\CreateCrewAssignmentFromPlanning;
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
    ['user' => $user] = makeCrewPlanningFixtures();

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
            ->where('can.projection', false)
            ->where('projection', null)
            ->where('relief_prefill', null)
        );
});

test('crew planning index returns relief prefill from query params', function () {
    [
        'user' => $user,
        'company' => $company,
        'vessel' => $vessel,
        'captain' => $captain,
    ] = makeCrewPlanningFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.planning.create',
    ]);

    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'rank_id' => $captain->id,
        'name' => 'Onboard Source',
    ]);

    $source = makeActiveOnVesselAssignment($company, $employee, $captain, $vessel, [
        'planned_signoff_at' => '2026-09-15 00:00:00',
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-planning.index', [
            'vessel_id' => $vessel->id,
            'rank_id' => $captain->id,
            'relieves_crew_assignment_id' => $source->id,
            'planned_join_date' => '2026-09-15',
            'open_create' => 1,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-planning/index')
            ->where('relief_prefill.open_create', true)
            ->where('relief_prefill.vessel_id', $vessel->id)
            ->where('relief_prefill.rank_id', $captain->id)
            ->where('relief_prefill.relieves_crew_assignment_id', $source->id)
            ->where('relief_prefill.planned_join_date', '2026-09-15')
            ->where('relief_prefill.relieves_employee_name', 'Onboard Source')
        );
});

test('planning crew list includes employees with active assignments', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'captain' => $captain] = makeCrewPlanningFixtures();

    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'rank_id' => $captain->id,
        'name' => 'Deployed Crew',
    ]);

    makeActiveOnVesselAssignment($company, $employee, $captain, $vessel);

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

test('rows are returned from planned assignments in range', function () {
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

    $today = CarbonImmutable::today();
    $from = $today->startOfMonth()->toDateString();
    $to = $today->addMonths(2)->endOfMonth()->toDateString();

    $this->actingAs($user)
        ->get(route('organization.crew-planning.index', compact('from', 'to')))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-planning/index')
            ->has('rows', 0)
        );

    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'planned_join_date' => $today->subDays(5)->toDateString(),
        'planned_leave_date' => $today->addDays(20)->toDateString(),
    ]);

    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $chiefOfficer->id,
        'planned_join_date' => $today->subDays(3)->toDateString(),
        'planned_leave_date' => $today->addDays(25)->toDateString(),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-planning.index', compact('from', 'to')))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
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
            ->where('bars.0.total_days', 41)
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

    $today = CarbonImmutable::today();
    $from = $today->startOfMonth()->toDateString();
    $to = $today->addMonths(2)->endOfMonth()->toDateString();

    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'planned_join_date' => $today->subDays(5)->toDateString(),
        'planned_leave_date' => $today->addDays(20)->toDateString(),
    ]);

    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $otherVessel->id,
        'rank_id' => $captain->id,
        'planned_join_date' => $today->subDays(4)->toDateString(),
        'planned_leave_date' => $today->addDays(21)->toDateString(),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-planning.index', ['vessel_id' => $vessel->id, 'from' => $from, 'to' => $to]))
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

    $today = CarbonImmutable::today();
    $from = $today->startOfMonth()->toDateString();
    $to = $today->addMonths(2)->endOfMonth()->toDateString();

    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'planned_join_date' => $today->subDays(5)->toDateString(),
        'planned_leave_date' => $today->addDays(20)->toDateString(),
    ]);

    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $chiefOfficer->id,
        'planned_join_date' => $today->subDays(4)->toDateString(),
        'planned_leave_date' => $today->addDays(21)->toDateString(),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-planning.index', ['rank_id' => $captain->id, 'from' => $from, 'to' => $to]))
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

    $today = CarbonImmutable::today();
    $from = $today->startOfMonth()->toDateString();
    $to = $today->addMonths(2)->endOfMonth()->toDateString();

    CrewPlanningAssignment::query()->create([
        'company_id' => $otherCompany->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'planned_join_date' => $today->subDays(5)->toDateString(),
        'planned_leave_date' => $today->addDays(20)->toDateString(),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-planning.index', compact('from', 'to')))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 0)
            ->has('bars', 0)
        );
});

test('planning users without vessel manning permission receive no projection payload', function () {
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
        'required_count' => 2,
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-planning.index', [
            'from' => '2026-08-01',
            'to' => '2026-08-31',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.projection', false)
            ->where('projection', null)
            ->has('rows', 0)
            ->has('tree', 0)
        );
});

test('planning users with vessel manning permission receive projection matching CrewProjectedManningQuery', function () {
    [
        'user' => $user,
        'company' => $company,
        'vessel' => $vessel,
        'captain' => $captain,
    ] = makeCrewPlanningFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.vessel_manning.view',
    ]);

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'required_count' => 2,
    ]);

    $from = '2026-08-01';
    $to = '2026-08-31';
    $expected = (new CrewProjectedManningQuery)->forCompany(
        (int) $company->id,
        $from,
        $to,
        (int) $vessel->id,
        (int) $captain->id,
    );

    $response = $this->actingAs($user)
        ->get(route('organization.crew-planning.index', [
            'from' => $from,
            'to' => $to,
            'vessel_id' => $vessel->id,
            'rank_id' => $captain->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.projection', true)
            ->where('projection.from', $from)
            ->where('projection.to', $to)
            ->where('projection.summary.positions', $expected['summary']['positions'])
            ->where('projection.summary.current_gap_positions', $expected['summary']['current_gap_positions'])
            ->where('projection.summary.future_gap_positions', $expected['summary']['future_gap_positions'])
            ->where('projection.summary.overlap_positions', $expected['summary']['overlap_positions'])
            ->has('projection.rows', 1)
            ->where('projection.rows.0.required_count', 2)
            ->where('projection.rows.0.status', $expected['items'][0]['status'])
            ->where('projection.rows.0.maximum_gap', $expected['items'][0]['maximum_gap'])
            ->has('rows', 1)
            ->where('rows.0.ranks.0.required_count', 2)
        );

    $projectionRow = $response->inertiaProps('projection.rows.0');
    expect($projectionRow)->not->toHaveKey('events')
        ->and($projectionRow['periods'])->toBe($expected['items'][0]['periods']);
});

test('projection uses exact planning from and to range', function () {
    [
        'user' => $user,
        'company' => $company,
        'vessel' => $vessel,
        'captain' => $captain,
    ] = makeCrewPlanningFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.vessel_manning.view',
    ]);

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'required_count' => 1,
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-planning.index', [
            'from' => '2026-07-15',
            'to' => '2026-09-10',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.from', '2026-07-15')
            ->where('filters.to', '2026-09-10')
            ->where('projection.from', '2026-07-15')
            ->where('projection.to', '2026-09-10')
        );
});

test('projection vessel and rank filters apply on planning index', function () {
    [
        'user' => $user,
        'company' => $company,
        'vessel' => $vessel,
        'captain' => $captain,
        'chiefOfficer' => $chiefOfficer,
    ] = makeCrewPlanningFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.vessel_manning.view',
    ]);

    $otherVessel = Vessel::query()->create([
        'name' => 'Projection Filter Vessel',
        'vessel_type_id' => $vessel->vessel_type_id,
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
        'rank_id' => $chiefOfficer->id,
        'required_count' => 3,
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-planning.index', [
            'from' => '2026-08-01',
            'to' => '2026-08-31',
            'vessel_id' => $vessel->id,
            'rank_id' => $captain->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('projection.rows', 1)
            ->where('projection.rows.0.vessel_id', $vessel->id)
            ->where('projection.rows.0.rank_id', $captain->id)
            ->has('rows', 1)
            ->where('rows.0.vessel_id', $vessel->id)
            ->where('rows.0.ranks.0.rank_id', $captain->id)
            ->has('tree', 1)
            ->where('tree.0.vessel_id', $vessel->id)
            ->has('tree.0.ranks', 1)
            ->where('tree.0.ranks.0.rank_id', $captain->id)
        );
});

test('company B projection cannot appear on company A planning page', function () {
    [
        'user' => $user,
        'company' => $company,
        'otherCompany' => $otherCompany,
        'vessel' => $vessel,
        'captain' => $captain,
    ] = makeCrewPlanningFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.vessel_manning.view',
    ]);

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'required_count' => 1,
    ]);

    VesselManning::query()->create([
        'company_id' => $otherCompany->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'required_count' => 9,
    ]);

    $foreignEmployee = Employee::factory()->forCompany($otherCompany)->create([
        'rank_id' => $captain->id,
        'status' => 'active',
    ]);
    makeActiveOnVesselAssignment($otherCompany, $foreignEmployee, $captain, $vessel, [
        'planned_signoff_at' => '2026-08-20 00:00:00',
    ]);
    CrewPlanningAssignment::query()->create([
        'company_id' => $otherCompany->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'employee_id' => $foreignEmployee->id,
        'planned_join_date' => '2026-08-05',
        'planned_leave_date' => '2026-11-05',
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-planning.index', [
            'from' => '2026-08-01',
            'to' => '2026-08-31',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('projection.rows', 1)
            ->where('projection.rows.0.required_count', 1)
            ->where('projection.rows.0.minimum_projected_count', 0)
            ->where('projection.summary.positions', 1)
            ->has('bars', 0)
            ->has('tree', 1)
            ->where('tree.0.ranks.0.required_count', 1)
            ->where('tree.0.ranks.0.crew', [])
        );
});

test('configured vessel rank with projected gap and zero planning still appears as a gantt row', function () {
    [
        'user' => $user,
        'company' => $company,
        'vessel' => $vessel,
        'captain' => $captain,
    ] = makeCrewPlanningFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.vessel_manning.view',
    ]);

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'required_count' => 2,
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-planning.index', [
            'from' => '2026-08-01',
            'to' => '2026-08-31',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('bars', 0)
            ->has('rows', 1)
            ->where('rows.0.vessel_id', $vessel->id)
            ->where('rows.0.ranks.0.rank_id', $captain->id)
            ->where('rows.0.ranks.0.required_count', 2)
            ->where('rows.0.ranks.0.row_key', "vessel:{$vessel->id}|rank:{$captain->id}")
            ->has('tree', 1)
            ->where('tree.0.vessel_id', $vessel->id)
            ->has('tree.0.ranks', 1)
            ->where('tree.0.ranks.0.rank_id', $captain->id)
            ->where('tree.0.ranks.0.required_count', 2)
            ->where('tree.0.ranks.0.crew', [])
            ->where('projection.rows.0.status', CrewProjectedManningStatus::CurrentGap->value)
            ->where('projection.rows.0.maximum_gap', 2)
        );
});

test('vessel manning only positions appear in left tree with empty crew', function () {
    [
        'user' => $user,
        'company' => $company,
        'vessel' => $vessel,
        'captain' => $captain,
        'chiefOfficer' => $welder,
    ] = makeCrewPlanningFixtures();

    $welder->update(['name' => 'Welder CPL']);

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.vessel_manning.view',
    ]);

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'required_count' => 1,
    ]);
    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $welder->id,
        'required_count' => 2,
    ]);

    $response = $this->actingAs($user)
        ->get(route('organization.crew-planning.index', [
            'from' => '2026-08-01',
            'to' => '2026-08-31',
        ]))
        ->assertOk();

    $treeRanks = collect($response->inertiaProps('tree.0.ranks'));
    $rowRanks = collect($response->inertiaProps('rows.0.ranks'));

    expect($response->inertiaProps('tree'))->toHaveCount(1)
        ->and($response->inertiaProps('tree.0.vessel_id'))->toBe($vessel->id)
        ->and($treeRanks)->toHaveCount(2)
        ->and($treeRanks->pluck('rank_name')->all())->toBe(['Captain CPL', 'Welder CPL'])
        ->and($treeRanks->every(fn (array $rank): bool => $rank['crew'] === []))->toBeTrue()
        ->and($treeRanks->firstWhere('rank_id', $captain->id)['required_count'])->toBe(1)
        ->and($treeRanks->firstWhere('rank_id', $welder->id)['required_count'])->toBe(2)
        ->and($rowRanks)->toHaveCount(2)
        ->and($response->inertiaProps('bars'))->toHaveCount(0);
});

test('existing planning row and projection position do not duplicate vessel rank', function () {
    [
        'user' => $user,
        'company' => $company,
        'vessel' => $vessel,
        'captain' => $captain,
    ] = makeCrewPlanningFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.vessel_manning.view',
    ]);

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'required_count' => 3,
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'rank_id' => $captain->id,
        'status' => 'active',
    ]);

    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2026-08-10',
        'planned_leave_date' => '2026-11-10',
    ]);

    $response = $this->actingAs($user)
        ->get(route('organization.crew-planning.index', [
            'from' => '2026-08-01',
            'to' => '2026-08-31',
        ]))
        ->assertOk();

    $ranks = collect($response->inertiaProps('rows.0.ranks'));
    $treeRanks = collect($response->inertiaProps('tree.0.ranks'));
    $crew = $treeRanks->first()['crew'];

    expect($ranks)->toHaveCount(1)
        ->and($ranks->first()['required_count'])->toBe(3)
        ->and($response->inertiaProps('bars'))->toHaveCount(1)
        ->and($treeRanks)->toHaveCount(1)
        ->and($treeRanks->first()['required_count'])->toBe(3)
        ->and($crew)->toHaveCount(1)
        ->and($crew[0]['employee_id'])->toBe($employee->id)
        ->and($crew[0]['employee_name'])->toBe($employee->name);
});

test('planning projection returns current gap future gap and overlap periods', function () {
    [
        'user' => $user,
        'company' => $company,
        'vessel' => $vessel,
        'captain' => $captain,
    ] = makeCrewPlanningFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.vessel_manning.view',
    ]);

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'required_count' => 1,
    ]);

    $currentGap = $this->actingAs($user)
        ->get(route('organization.crew-planning.index', [
            'from' => '2026-08-01',
            'to' => '2026-08-31',
            'vessel_id' => $vessel->id,
            'rank_id' => $captain->id,
        ]))
        ->assertOk();

    $currentRow = $currentGap->inertiaProps('projection.rows.0');
    expect($currentRow['status'])->toBe(CrewProjectedManningStatus::CurrentGap->value)
        ->and(collect($currentRow['periods'])->contains(fn (array $period): bool => $period['gap'] > 0))->toBeTrue();

    $employee = Employee::factory()->forCompany($company)->create([
        'rank_id' => $captain->id,
        'status' => 'active',
    ]);
    makeActiveOnVesselAssignment($company, $employee, $captain, $vessel, [
        'planned_signoff_at' => '2026-08-20 00:00:00',
    ]);

    $futureGap = $this->actingAs($user)
        ->get(route('organization.crew-planning.index', [
            'from' => '2026-08-01',
            'to' => '2026-08-31',
            'vessel_id' => $vessel->id,
            'rank_id' => $captain->id,
        ]))
        ->assertOk();

    $futureRow = $futureGap->inertiaProps('projection.rows.0');
    expect($futureRow['status'])->toBe(CrewProjectedManningStatus::FutureGap->value)
        ->and($futureRow['next_gap_date'])->toBe('2026-08-20')
        ->and(collect($futureRow['periods'])->contains(fn (array $period): bool => $period['gap'] > 0))->toBeTrue();

    $early = Employee::factory()->forCompany($company)->create([
        'rank_id' => $captain->id,
        'status' => 'active',
    ]);
    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'employee_id' => $early->id,
        'planned_join_date' => '2026-08-18',
        'planned_leave_date' => '2026-11-18',
    ]);

    $overlap = $this->actingAs($user)
        ->get(route('organization.crew-planning.index', [
            'from' => '2026-08-01',
            'to' => '2026-08-31',
            'vessel_id' => $vessel->id,
            'rank_id' => $captain->id,
        ]))
        ->assertOk();

    $overlapRow = $overlap->inertiaProps('projection.rows.0');
    expect(collect($overlapRow['periods'])->contains(fn (array $period): bool => $period['excess'] > 0))->toBeTrue();
});

test('planning projection ignores vacant planning and counts linked assignment once', function () {
    [
        'user' => $user,
        'company' => $company,
        'vessel' => $vessel,
        'captain' => $captain,
    ] = makeCrewPlanningFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.vessel_manning.view',
        'crew_operations.planning.create',
    ]);

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'required_count' => 1,
    ]);

    $onboard = Employee::factory()->forCompany($company)->create([
        'rank_id' => $captain->id,
        'status' => 'active',
    ]);
    makeActiveOnVesselAssignment($company, $onboard, $captain, $vessel, [
        'planned_signoff_at' => '2026-09-01 00:00:00',
    ]);

    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'employee_id' => null,
        'planned_join_date' => '2026-08-15',
        'planned_leave_date' => '2026-11-15',
    ]);

    $vacantResponse = $this->actingAs($user)
        ->get(route('organization.crew-planning.index', [
            'from' => '2026-08-01',
            'to' => '2026-08-31',
            'vessel_id' => $vessel->id,
            'rank_id' => $captain->id,
        ]))
        ->assertOk();

    expect($vacantResponse->inertiaProps('projection.rows.0.minimum_projected_count'))->toBe(1);

    $planner = Employee::factory()->forCompany($company)->create([
        'rank_id' => $captain->id,
        'status' => 'active',
    ]);
    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'employee_id' => $planner->id,
        'planned_join_date' => '2026-08-25',
        'planned_leave_date' => '2026-11-25',
    ]);

    $beforeLink = $this->actingAs($user)
        ->get(route('organization.crew-planning.index', [
            'from' => '2026-08-01',
            'to' => '2026-08-31',
            'vessel_id' => $vessel->id,
            'rank_id' => $captain->id,
        ]))
        ->assertOk();

    $beforeMax = $beforeLink->inertiaProps('projection.rows.0.minimum_projected_count');

    app(CreateCrewAssignmentFromPlanning::class)->handle($planning, $user->id);

    $assignmentCountBefore = CrewAssignment::query()->where('company_id', $company->id)->count();
    $seaServiceCountBefore = EmployeeSeaService::query()->where('company_id', $company->id)->count();

    $afterLink = $this->actingAs($user)
        ->get(route('organization.crew-planning.index', [
            'from' => '2026-08-01',
            'to' => '2026-08-31',
            'vessel_id' => $vessel->id,
            'rank_id' => $captain->id,
        ]))
        ->assertOk();

    expect($afterLink->inertiaProps('projection.rows.0.minimum_projected_count'))->toBe($beforeMax)
        ->and(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe($assignmentCountBefore)
        ->and(EmployeeSeaService::query()->where('company_id', $company->id)->count())->toBe($seaServiceCountBefore);
});
