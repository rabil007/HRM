<?php

use App\Enums\CrewAssignmentStatus;
use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\Employee;
use App\Models\VesselManning;
use App\Support\CrewMovements\CurrentCrewQuery;
use App\Support\CrewOperations\CrewAssignmentManningQuery;
use App\Support\CrewPlanning\CrewPlanningGanttQuery;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

test('current crew excludes inactive and terminated employees from operational lists', function () {
    ['user' => $user, 'company' => $company, 'employee' => $active, 'rank' => $rank] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, ['crew_operations.assignments.view']);
    $user->update(['current_company_id' => $company->id]);
    $vessel = makeCrewMovementVessel('Active Crew Vessel', $company);

    makeActiveOnVesselAssignment($company, $active, $rank, $vessel);

    $inactive = Employee::factory()->forCompany($company)->create([
        'rank_id' => $rank->id,
        'status' => 'inactive',
    ]);
    $terminated = Employee::factory()->forCompany($company)->terminated()->create([
        'rank_id' => $rank->id,
    ]);
    $foreignFixtures = makeCrewAssignmentFixtures();

    makeActiveOnVesselAssignment($company, $inactive, $rank, $vessel);
    makeActiveOnVesselAssignment($company, $terminated, $rank, $vessel);
    makeActiveOnVesselAssignment(
        $foreignFixtures['company'],
        $foreignFixtures['employee'],
        $foreignFixtures['rank'],
        makeCrewMovementVessel('Foreign Vessel', $foreignFixtures['company']),
    );

    expect(CurrentCrewQuery::paginate($company->id)->total())->toBe(1);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('assignments', 1)
            ->where('assignments.0.employee.id', $active->id)
            ->where('summary.total', 1));
});

test('current crew completed history still includes inactive employees', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, ['crew_operations.assignments.view']);
    $user->update(['current_company_id' => $company->id]);

    $assignment = CrewAssignment::factory()
        ->forEmployee($employee)
        ->completed()
        ->create(['rank_id' => $rank->id]);

    $employee->update(['status' => 'terminated']);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.index', [
            'status' => CrewAssignmentStatus::Completed->value,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('assignments', 1)
            ->where('assignments.0.id', $assignment->id));
});

test('crew operations dashboard onboard now excludes inactive employees', function () {
    ['user' => $user, 'company' => $company, 'employee' => $active, 'rank' => $rank, 'vessel' => $vessel] = makeCrewOperationsFixtures();

    makeActiveOnVesselAssignment($company, $active, $rank, $vessel);

    $inactive = Employee::factory()->forCompany($company)->inactive()->create([
        'rank_id' => $rank->id,
    ]);
    makeActiveOnVesselAssignment($company, $inactive, $rank, $vessel);

    $this->actingAs($user)
        ->get(route('organization.crew-operations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('daily_pulse.onboard_now', 1));
});

test('vessel manning actual onboard count excludes inactive employees', function () {
    ['company' => $company, 'employee' => $active, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Manning Vessel', $company);

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'required_count' => 2,
    ]);

    makeActiveOnVesselAssignment($company, $active, $rank, $vessel);

    $inactive = Employee::factory()->forCompany($company)->inactive()->create([
        'rank_id' => $rank->id,
    ]);
    makeActiveOnVesselAssignment($company, $inactive, $rank, $vessel);

    $onboard = CrewAssignmentManningQuery::onboardCountsByVesselRank($company->id);
    $key = $vessel->id.'|'.$rank->id;

    expect($onboard[$key] ?? 0)->toBe(1);

    $result = CrewAssignmentManningQuery::forCompany($company->id);

    expect($result['items'][0]['actual_count'])->toBe(1)
        ->and($result['items'][0]['gap'])->toBe(1);
});

test('crew planning gantt keeps past bars for inactive employees and excludes current future bars', function () {
    CarbonImmutable::setTestNow('2026-08-14 12:00:00');

    ['company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Gantt Vessel', $company);
    $inactive = Employee::factory()->forCompany($company)->inactive()->create([
        'rank_id' => $rank->id,
    ]);

    $past = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $inactive->id,
        'planned_join_date' => '2026-01-01',
        'planned_leave_date' => '2026-06-30',
    ]);

    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $inactive->id,
        'planned_join_date' => '2026-09-01',
        'planned_leave_date' => '2026-12-31',
    ]);

    $vacant = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => null,
        'planned_join_date' => '2026-09-01',
        'planned_leave_date' => '2026-12-31',
    ]);

    $bars = CrewPlanningGanttQuery::bars($company->id, '2026-01-01', '2026-12-31');
    $ids = collect($bars)->pluck('id')->all();

    expect($ids)->toContain($past->id)
        ->and($ids)->toContain($vacant->id)
        ->and($ids)->not->toContain(
            CrewPlanningAssignment::query()
                ->where('employee_id', $inactive->id)
                ->whereDate('planned_join_date', '2026-09-01')
                ->value('id')
        );

    CarbonImmutable::setTestNow();
});

test('crew assignment and planning mutations reject inactive employee ids', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $user->update(['current_company_id' => $company->id]);
    $vessel = makeCrewMovementVessel('Reject Vessel', $company);
    $inactive = Employee::factory()->forCompany($company)->inactive()->create([
        'rank_id' => $rank->id,
    ]);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.planning.view',
        'crew_operations.planning.create',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'employee_id' => $inactive->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
        ])
        ->assertSessionHasErrors('employee_id');

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.store'), [
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'employee_id' => $inactive->id,
            'planned_join_date' => '2027-02-01',
            'planned_leave_date' => '2027-08-31',
        ])
        ->assertSessionHasErrors('employee_id');
});

test('crew movement history retains completed assignments after termination', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $user->update(['current_company_id' => $company->id]);
    grantCompanyPermissions($user, $company, [
        'reports.crew_movement_history.view',
        'employees.update',
    ]);

    $assignment = CrewAssignment::factory()
        ->forEmployee($employee)
        ->completed()
        ->create(['rank_id' => $rank->id]);

    $this->actingAs($user)
        ->from(route('organization.employees.show', $employee))
        ->put(route('organization.employees.status', $employee), ['status' => 'terminated'])
        ->assertRedirect();

    $this->actingAs($user)
        ->get(route('organization.reports.crew-movement-history.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('assignments', 1)
            ->where('assignments.0.id', $assignment->id)
            ->where('assignments.0.employee.id', $employee->id));
});

test('employee status change is rejected while an active crew assignment exists', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $user->update(['current_company_id' => $company->id]);
    $vessel = makeCrewMovementVessel('Blocker Vessel', $company);
    makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    grantCompanyPermissions($user, $company, ['employees.view', 'employees.update']);

    $this->actingAs($user)
        ->from(route('organization.employees.show', $employee))
        ->put(route('organization.employees.status', $employee), ['status' => 'inactive'])
        ->assertSessionHasErrors('status');

    expect($employee->fresh()->status)->toBe('active');
});
