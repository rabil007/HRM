<?php

use App\Models\CrewOperationsSetting;
use App\Models\CrewPlanningAssignment;
use App\Models\EmployeeDeployment;
use App\Models\User;
use App\Models\VesselManning;
use App\Support\CrewDeployments\DeploymentStatus;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot access crew operations overview', function () {
    $this->get(route('organization.crew-operations.index'))
        ->assertRedirect(route('login'));
});

test('users without deployments view permission cannot access crew operations overview', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('organization.crew-operations.index'))
        ->assertForbidden();
});

test('authorized users can view crew operations overview', function () {
    ['user' => $user, 'company' => $company] = makeCrewDeploymentFixtures();

    $this->actingAs($user)
        ->get(route('organization.crew-operations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-operations/index')
            ->has('deployment_summary')
            ->has('alert_counts')
            ->has('attention_items')
            ->has('pool_snapshot')
            ->where('can.deployments', true)
        );
});

test('crew operations overview counts needs update deployments in alert counts', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeCrewDeploymentVessel('Overview Needs Update')->id,
        'arrived_date' => CarbonImmutable::today()->subDay(),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-operations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('alert_counts.needs_update', 1)
            ->where('deployment_summary.unknown', 1)
            ->has('attention_items', 1)
            ->where('attention_items.0.type', 'needs_update'));
});

test('crew operations overview counts overdue home deployments using max home days setting', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    CrewOperationsSetting::query()->create([
        'company_id' => $company->id,
        'pool_department_ids' => null,
        'max_home_days' => 3,
    ]);

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeCrewDeploymentVessel('Overview Over Home')->id,
        'joined_date' => CarbonImmutable::today()->subDays(20),
        'disembarked_date' => CarbonImmutable::today()->subDays(10),
        'travelled_date' => CarbonImmutable::today()->subDays(5),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-operations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('max_home_days', 3)
            ->where('alert_counts.overdue_home', 1)
            ->has('attention_items', 1)
            ->where('attention_items.0.type', 'overdue_home'));
});

test('crew operations overview counts due soon deployments in alert counts', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeCrewDeploymentVessel('Overview Due Soon')->id,
        'joined_date' => CarbonImmutable::today()->subDays(10),
        'disembarked_date' => CarbonImmutable::today()->addDay(),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-operations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('alert_counts.due_soon', 1)
            ->has('attention_items', 1)
            ->where('attention_items.0.type', 'due_soon'));
});

test('crew operations overview lists upcoming planning when user can view planning', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.deployments.view',
        'crew_operations.deployments.create',
        'crew_operations.deployments.update',
        'crew_operations.deployments.delete',
        'crew_operations.deployments.export',
        'crew_operations.planning.view',
    ]);

    $vessel = makeCrewDeploymentVessel('Overview Planning Vessel');

    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => CarbonImmutable::today()->addDays(7)->toDateString(),
        'planned_leave_date' => CarbonImmutable::today()->addDays(37)->toDateString(),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-operations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.planning', true)
            ->where('alert_counts.upcoming_planning', 1)
            ->has('upcoming_planning', 1)
            ->where('upcoming_planning.0.employee_name', $employee->name));
});

test('crew operations overview hides recent activity without audit permission', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeCrewDeploymentVessel('Audit Hidden Overview')->id,
        'joined_date' => CarbonImmutable::today()->subDays(3),
        'disembarked_date' => CarbonImmutable::today()->subDay(),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-operations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can_view_audit', false)
            ->where('recent_activity', []));
});

test('crew operations overview deployment summary matches board summary keys', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeCrewDeploymentVessel('Overview On Vessel')->id,
        'joined_date' => CarbonImmutable::today()->subDays(2),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-operations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('deployment_summary.on_vessel', 1)
            ->where('deployment_summary.total', 1)
            ->where('deployment_summary.'.DeploymentStatus::UNKNOWN, 0));
});

test('crew operations overview exposes manning gaps when user can view vessel manning', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.deployments.view',
        'crew_operations.deployments.create',
        'crew_operations.deployments.update',
        'crew_operations.deployments.delete',
        'crew_operations.deployments.export',
        'crew_operations.vessel_manning.view',
    ]);

    $vessel = makeCrewDeploymentVessel('Overview Manning Gap');

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'required_count' => 2,
    ]);

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'joined_date' => CarbonImmutable::today()->subDays(2),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-operations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.vessel_manning', true)
            ->where('alert_counts.manning_gaps', 1)
            ->where('manning_gaps.understaffed_positions', 1)
            ->where('manning_gaps.total_shortfall', 1)
            ->has('manning_gaps.items', 1)
            ->where('manning_gaps.items.0.gap', 1));
});

test('crew operations overview includes deployment trends for the last six months', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => makeCrewDeploymentVessel('Trend Vessel')->id,
        'joined_date' => CarbonImmutable::now()->startOfMonth()->toDateString(),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-operations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('deployment_trends', 6)
            ->where('deployment_trends.5.joins', 1));
});

test('crew operations overview hides manning gaps without vessel manning permission', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewDeploymentFixtures();

    $vessel = makeCrewDeploymentVessel('Hidden Manning Gap');

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'required_count' => 3,
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-operations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.vessel_manning', false)
            ->where('alert_counts.manning_gaps', 0)
            ->where('manning_gaps.items', []));
});
