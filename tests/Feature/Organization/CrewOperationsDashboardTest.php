<?php

use App\Enums\CrewAssignmentStatus;
use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\CrewOperationsSetting;
use App\Models\CrewPlanningAssignment;
use App\Models\Rank;
use App\Models\User;
use App\Models\VesselManning;
use App\Support\CrewOperations\CrewProjectedManningQuery;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot access crew operations overview', function () {
    $this->get(route('organization.crew-operations.index'))
        ->assertRedirect(route('login'));
});

test('users without overview view permission cannot access crew operations overview', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('organization.crew-operations.index'))
        ->assertForbidden();
});

test('authorized users can view crew operations overview', function () {
    ['user' => $user] = makeCrewOperationsFixtures();

    $this->actingAs($user)
        ->get(route('organization.crew-operations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-operations/index')
            ->has('deployment_summary')
            ->has('alert_counts')
            ->has('alert_counts.signoff_within_14_days_no_relief')
            ->has('alert_counts.relief_not_ready')
            ->has('alert_counts.relief_ready_to_join')
            ->has('alert_counts.critical_relief_risk')
            ->has('attention_items')
            ->has('pool_snapshot')
            ->where('projected_manning', null)
            ->where('can.overview', true)
        );
});

test('users with only overview view permission can access crew operations overview', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeCrewOperationsFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.overview.view',
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-operations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.overview', true));
});

test('crew operations overview counts needs update assignments in alert counts', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCrewOperationsFixtures();

    $assignment = CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-'.now()->year.'-NEEDSUP',
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Active,
        'started_at' => now()->subDays(10),
        'source' => 'manual',
        'current_phase_id' => null,
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-operations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('alert_counts.needs_update', 1)
            ->where('deployment_summary.movement_update_required', 1)
            ->has('attention_items', 1)
            ->where('attention_items.0.type', 'needs_update'));
});

test('crew operations overview counts overdue home assignments using max home days setting', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCrewOperationsFixtures();

    CrewOperationsSetting::query()->create([
        'company_id' => $company->id,
        'pool_department_ids' => null,
        'max_home_days' => 3,
    ]);

    $assignment = CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-'.now()->year.'-OVERDUE',
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Completed,
        'started_at' => CarbonImmutable::today()->subDays(20),
        'closed_at' => CarbonImmutable::today()->subDays(10),
        'source' => 'manual',
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

test('crew operations overview lists upcoming planning when user can view planning', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCrewOperationsFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.overview.view',
        'crew_operations.planning.view',
    ]);

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

test('crew operations overview handles open-ended upcoming planning without leave date', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCrewOperationsFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.overview.view',
        'crew_operations.planning.view',
    ]);

    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => CarbonImmutable::today()->addDays(5)->toDateString(),
        'planned_leave_date' => null,
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-operations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('alert_counts.upcoming_planning', 1)
            ->has('upcoming_planning', 1)
            ->where('upcoming_planning.0.employee_name', $employee->name)
            ->where('upcoming_planning.0.planned_leave_date', null));
});

test('dashboard remains available when upcoming planning leave date is null', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCrewOperationsFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.overview.view',
        'crew_operations.planning.view',
        'crew_operations.deployments.view',
    ]);

    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => CarbonImmutable::today()->addDays(5)->toDateString(),
        'planned_leave_date' => null,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();
});

test('crew operations overview hides recent activity without audit permission', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCrewOperationsFixtures();

    makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $this->actingAs($user)
        ->get(route('organization.crew-operations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can_view_audit', false)
            ->where('recent_activity', []));
});

test('crew operations overview deployment summary matches expected keys', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCrewOperationsFixtures();

    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $this->actingAs($user)
        ->get(route('organization.crew-operations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('deployment_summary.on_vessel', 1)
            ->where('deployment_summary.total', 1)
            ->where('deployment_summary.movement_update_required', 0));
});

test('crew operations overview exposes manning gaps when user can view vessel manning', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCrewOperationsFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.overview.view',
        'crew_operations.vessel_manning.view',
    ]);

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'required_count' => 2,
    ]);

    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

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
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCrewOperationsFixtures();

    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);
    $assignment->currentPhase->update([
        'actual_start_at' => CarbonImmutable::now()->startOfMonth()->addDay(),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-operations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('deployment_trends', 6)
            ->where('deployment_trends.5.joins', 1));
});

test('crew operations overview hides manning gaps without vessel manning permission', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank, 'vessel' => $vessel] = makeCrewOperationsFixtures();

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
            ->where('manning_gaps.items', [])
            ->where('projected_manning', null));
});

test('crew operations overview exposes 30-day projected manning separate from actual gaps', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCrewOperationsFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.overview.view',
        'crew_operations.vessel_manning.view',
    ]);

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'required_count' => 1,
    ]);

    $timezone = CompanyTimezone::forCompanyId((int) $company->id);
    $from = CarbonImmutable::now($timezone)->toDateString();
    $to = CarbonImmutable::parse($from, $timezone)->addDays(30)->toDateString();
    $plannedSignoff = CarbonImmutable::parse($from, $timezone)->addDays(10)->startOfDay();

    makeActiveOnVesselAssignment($company, $employee, $rank, $vessel, [
        'planned_signoff_at' => $plannedSignoff->toDateTimeString(),
    ]);

    $expected = (new CrewProjectedManningQuery)->forCompany(
        (int) $company->id,
        $from,
        $to,
    );

    $this->actingAs($user)
        ->get(route('organization.crew-operations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.vessel_manning', true)
            ->where('manning_gaps.understaffed_positions', 0)
            ->where('manning_gaps.total_shortfall', 0)
            ->where('projected_manning.horizon_days', 30)
            ->where('projected_manning.from', $expected['from'])
            ->where('projected_manning.to', $expected['to'])
            ->where('projected_manning.current_gap_positions', $expected['summary']['current_gap_positions'])
            ->where('projected_manning.future_gap_positions', $expected['summary']['future_gap_positions'])
            ->where('projected_manning.covered_positions', $expected['summary']['covered_positions'])
            ->where('projected_manning.overlap_positions', $expected['summary']['overlap_positions'])
            ->where('projected_manning.projected_shortfall_days', $expected['summary']['total_projected_shortfall_days'])
            ->where('projected_manning.future_gap_positions', 1)
            ->where('projected_manning.next_gap_date', $expected['items'][0]['next_gap_date'])
            ->has('projected_manning.critical_positions', 1)
            ->where('projected_manning.critical_positions.0.vessel_id', $vessel->id)
            ->where('projected_manning.critical_positions.0.rank_id', $rank->id)
            ->where('projected_manning.critical_positions.0.status', 'future_gap')
            ->where('projected_manning.critical_positions.0.maximum_gap', $expected['items'][0]['maximum_gap'])
        );
});

test('crew operations overview represents current and future projected gaps', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCrewOperationsFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.overview.view',
        'crew_operations.vessel_manning.view',
    ]);

    $futureRank = Rank::query()->create([
        'name' => 'Future Gap Rank '.uniqid(),
        'is_active' => true,
    ]);
    $currentVessel = makeCrewMovementVessel('Current Gap Vessel');

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $currentVessel->id,
        'rank_id' => $rank->id,
        'required_count' => 1,
    ]);
    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $futureRank->id,
        'required_count' => 1,
    ]);

    $timezone = CompanyTimezone::forCompanyId((int) $company->id);
    $from = CarbonImmutable::now($timezone)->toDateString();
    $plannedSignoff = CarbonImmutable::parse($from, $timezone)->addDays(12)->startOfDay();

    makeActiveOnVesselAssignment(
        $company,
        $employee,
        $futureRank,
        $vessel,
        ['planned_signoff_at' => $plannedSignoff->toDateTimeString()],
    );

    $this->actingAs($user)
        ->get(route('organization.crew-operations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('projected_manning.current_gap_positions', 1)
            ->where('projected_manning.future_gap_positions', 1)
            ->has('projected_manning.critical_positions', 2)
            ->where('projected_manning.critical_positions.0.status', 'current_gap')
            ->where('projected_manning.critical_positions.1.status', 'future_gap')
            ->where('projected_manning.next_gap_date', $from)
        );
});

test('crew operations overview projected manning stays company scoped', function () {
    ['user' => $user, 'company' => $companyA, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCrewOperationsFixtures();

    grantCompanyPermissions($user, $companyA, [
        'crew_operations.overview.view',
        'crew_operations.vessel_manning.view',
    ]);

    VesselManning::query()->create([
        'company_id' => $companyA->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'required_count' => 1,
    ]);

    makeActiveOnVesselAssignment($companyA, $employee, $rank, $vessel, [
        'planned_signoff_at' => null,
    ]);

    $companyB = Company::query()->create([
        'name' => 'Dashboard Other Co',
        'slug' => 'dashboard-other-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $companyA->country_id,
        'currency_id' => $companyA->currency_id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $vesselB = makeCrewMovementVessel('Foreign Dashboard Vessel');
    $rankB = Rank::query()->create([
        'name' => 'Foreign Dashboard Rank '.uniqid(),
        'is_active' => true,
    ]);

    VesselManning::query()->create([
        'company_id' => $companyB->id,
        'vessel_id' => $vesselB->id,
        'rank_id' => $rankB->id,
        'required_count' => 4,
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-operations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('projected_manning.current_gap_positions', 0)
            ->where('projected_manning.future_gap_positions', 0)
            ->where('projected_manning.critical_positions', [])
            ->missing('projected_manning.critical_positions.0')
        );
});

test('crew operations overview bounds projected critical positions and picks nearest gap', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewOperationsFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.overview.view',
        'crew_operations.vessel_manning.view',
    ]);

    $timezone = CompanyTimezone::forCompanyId((int) $company->id);
    $from = CarbonImmutable::now($timezone)->toDateString();

    for ($i = 0; $i < 7; $i++) {
        $vessel = makeCrewMovementVessel("Critical Gap Vessel {$i}");
        VesselManning::query()->create([
            'company_id' => $company->id,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'required_count' => 1,
        ]);
    }

    $this->actingAs($user)
        ->get(route('organization.crew-operations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('projected_manning.current_gap_positions', 7)
            ->has('projected_manning.critical_positions', 5)
            ->where('projected_manning.next_gap_date', $from)
            ->where('projected_manning.critical_positions.0.next_gap_date', $from)
        );
});
