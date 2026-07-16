<?php

use App\Enums\CrewAssignmentStatus;
use App\Models\CrewAssignment;
use App\Models\CrewOperationsSetting;
use App\Models\CrewPlanningAssignment;
use App\Models\User;
use App\Models\VesselManning;
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
            ->has('attention_items')
            ->has('pool_snapshot')
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
            ->where('manning_gaps.items', []));
});
