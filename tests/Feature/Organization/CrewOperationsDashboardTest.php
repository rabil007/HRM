<?php

use App\Enums\CrewAssignmentStatus;
use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\CrewOperationsSetting;
use App\Models\CrewPlanningAssignment;
use App\Models\Employee;
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

test('authorized users can view daily operations dashboard essentials', function () {
    ['user' => $user] = makeCrewOperationsFixtures();

    $this->actingAs($user)
        ->get(route('organization.crew-operations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-operations/index')
            ->has('daily_pulse')
            ->has('daily_pulse.onboard_now')
            ->has('daily_pulse.joins_next_7_days')
            ->has('daily_pulse.signoffs_next_7_days')
            ->has('daily_pulse.coverage_risks')
            ->has('action_required')
            ->has('next_seven_days', 7)
            ->has('manning_relief_risks')
            ->where('projected_manning', null)
            ->missing('deployment_trends')
            ->missing('pool_snapshot')
            ->missing('recent_activity')
            ->missing('deployment_summary')
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
            ->where('can.overview', true)
            ->where('projected_manning', null));
});

test('onboard now reflects active P4 operational truth', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCrewOperationsFixtures();

    makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $this->actingAs($user)
        ->get(route('organization.crew-operations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('daily_pulse.onboard_now', 1));
});

test('daily pulse counts next-7-day joins and sign-offs with overdue secondary', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCrewOperationsFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.overview.view',
        'crew_operations.planning.view',
    ]);

    $timezone = CompanyTimezone::forCompanyId((int) $company->id);
    $today = CarbonImmutable::now($timezone)->startOfDay();

    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => Employee::factory()->forCompany($company)->create([
            'rank_id' => $rank->id,
            'status' => 'active',
        ])->id,
        'planned_join_date' => $today->addDays(2)->toDateString(),
        'planned_leave_date' => $today->addDays(40)->toDateString(),
    ]);

    makeActiveOnVesselAssignment($company, $employee, $rank, $vessel, [
        'planned_signoff_at' => $today->addDays(3)->toDateTimeString(),
    ]);

    $overdueEmployee = Employee::factory()->forCompany($company)->create([
        'rank_id' => $rank->id,
        'status' => 'active',
    ]);
    makeActiveOnVesselAssignment($company, $overdueEmployee, $rank, $vessel, [
        'planned_signoff_at' => $today->subDays(2)->toDateTimeString(),
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-operations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('daily_pulse.joins_next_7_days', 1)
            ->where('daily_pulse.signoffs_next_7_days', 1)
            ->where('daily_pulse.signoffs_overdue', 1)
            ->where('next_seven_days.2.joins', 1)
            ->where('next_seven_days.3.signoffs', 1)
        );
});

test('actual current gap remains distinct from projected future gap', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCrewOperationsFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.overview.view',
        'crew_operations.vessel_manning.view',
    ]);

    $currentVessel = makeCrewMovementVessel('Actual Gap Vessel');
    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $currentVessel->id,
        'rank_id' => $rank->id,
        'required_count' => 1,
    ]);

    $futureRank = Rank::query()->create([
        'name' => 'Future Gap Rank '.uniqid(),
        'is_active' => true,
    ]);
    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $futureRank->id,
        'required_count' => 1,
    ]);

    $timezone = CompanyTimezone::forCompanyId((int) $company->id);
    $from = CarbonImmutable::now($timezone)->toDateString();
    $plannedSignoff = CarbonImmutable::parse($from, $timezone)->addDays(10)->startOfDay();

    makeActiveOnVesselAssignment($company, $employee, $futureRank, $vessel, [
        'planned_signoff_at' => $plannedSignoff->toDateTimeString(),
    ]);

    $expected = (new CrewProjectedManningQuery)->forCompany(
        (int) $company->id,
        $from,
        CarbonImmutable::parse($from, $timezone)->addDays(30)->toDateString(),
    );

    $this->actingAs($user)
        ->get(route('organization.crew-operations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('daily_pulse.coverage_risks.current', 1)
            ->where('daily_pulse.coverage_risks.upcoming', $expected['summary']['future_gap_positions'])
            ->where('projected_manning.future_gap_positions', $expected['summary']['future_gap_positions'])
            ->where('projected_manning.current_gap_positions', $expected['summary']['current_gap_positions'])
            ->where('action_required.0.type', 'current_manning_gap')
            ->where('manning_relief_risks.0.kind', 'actual')
            ->where('manning_relief_risks.0.risk', 'Gap now')
        );
});

test('projected future risk comes from CrewProjectedManningQuery', function () {
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
    $plannedSignoff = CarbonImmutable::parse($from, $timezone)->addDays(12)->startOfDay();

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
            ->where('projected_manning.from', $expected['from'])
            ->where('projected_manning.to', $expected['to'])
            ->where('projected_manning.future_gap_positions', 1)
            ->where('daily_pulse.coverage_risks.upcoming', 1)
            ->where('daily_pulse.coverage_risks.current', 0)
            ->has('projected_manning.critical_positions', 1)
            ->where('action_required.0.type', 'projected_future_gap')
            ->where('manning_relief_risks.0.kind', 'projected')
            ->where('manning_relief_risks.0.risk', 'Future gap')
        );
});

test('action required stays bounded and prefers current manning gaps first', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewOperationsFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.overview.view',
        'crew_operations.vessel_manning.view',
    ]);

    for ($i = 0; $i < 12; $i++) {
        $vessel = makeCrewMovementVessel("Gap Vessel {$i}");
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
            ->has('action_required', 10)
            ->where('action_required.0.type', 'current_manning_gap')
            ->where('daily_pulse.coverage_risks.current', 12)
        );
});

test('user without vessel manning permission does not receive projected data', function () {
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
            ->where('projected_manning', null)
            ->where('daily_pulse.coverage_risks.upcoming', 0)
            ->where('daily_pulse.coverage_risks.current', 0)
        );
});

test('company B projected manning cannot appear on company A dashboard', function () {
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
            ->where('daily_pulse.coverage_risks.current', 0)
            ->where('daily_pulse.coverage_risks.upcoming', 0)
            ->where('projected_manning.critical_positions', [])
            ->where('manning_relief_risks', [])
        );
});

test('crew operations overview counts needs update assignments in action required', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCrewOperationsFixtures();

    CrewAssignment::query()->create([
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
            ->has('action_required', 1)
            ->where('action_required.0.type', 'needs_update'));
});

test('crew operations overview counts overdue home using max home days setting', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCrewOperationsFixtures();

    CrewOperationsSetting::query()->create([
        'company_id' => $company->id,
        'pool_department_ids' => null,
        'max_home_days' => 3,
    ]);

    CrewAssignment::query()->create([
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
            ->has('action_required', 1)
            ->where('action_required.0.type', 'overdue_home'));
});

test('projected critical positions remain bounded on daily dashboard', function () {
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
            ->has('action_required', 7)
            ->where('action_required.0.type', 'current_manning_gap')
        );
});
