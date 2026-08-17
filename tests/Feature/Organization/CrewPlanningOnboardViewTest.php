<?php

use App\Enums\CrewPhaseCode;
use App\Models\Employee;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Facades\Excel;

function makeCrewPlanningOnboardFixtures(array $permissions = [
    'crew_operations.planning.view',
    'crew_operations.assignments.view',
]): array
{
    $fixtures = makeCurrentCrewVesselViewFixtures($permissions);

    return $fixtures;
}

test('crew planning defaults to the planning gantt view', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCrewPlanningOnboardFixtures();
    makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $this->actingAs($user)
        ->get(route('organization.crew-planning.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-planning/index')
            ->where('view', 'planning')
            ->has('onboard_vessels', 0)
            ->has('rows')
            ->has('bars')
            ->has('tree'));
});

test('crew planning onboard vessels view lists actual active p4 crew', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCrewPlanningOnboardFixtures();
    makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $this->actingAs($user)
        ->get(route('organization.crew-planning.index', ['view' => 'onboard-vessels']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-planning/index')
            ->where('view', 'onboard-vessels')
            ->has('rows', 0)
            ->has('bars', 0)
            ->has('onboard_vessels', 1)
            ->where('onboard_vessels.0.id', $vessel->id)
            ->where('onboard_vessels.0.crew.0.employee.id', $employee->id));
});

test('crew planning onboard view excludes p3 crew even when vessel_id is set', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCrewPlanningOnboardFixtures();
    makeCurrentCrewPhaseAssignment(
        $company,
        $employee,
        $rank,
        $vessel,
        CrewPhaseCode::ReadyToJoin,
    );

    $this->actingAs($user)
        ->get(route('organization.crew-planning.index', ['view' => 'onboard-vessels']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('onboard_vessels', 0));
});

test('crew planning onboard view excludes p5 crew', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCrewPlanningOnboardFixtures();
    makeCurrentCrewPhaseAssignment(
        $company,
        $employee,
        $rank,
        $vessel,
        CrewPhaseCode::DemobStandby,
    );

    $this->actingAs($user)
        ->get(route('organization.crew-planning.index', ['view' => 'onboard-vessels']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('onboard_vessels', 0));
});

test('crew planning onboard view excludes inactive employees with leftover active p4', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank, 'vessel' => $vessel] = makeCrewPlanningOnboardFixtures();
    $inactive = Employee::factory()->forCompany($company)->inactive()->create([
        'rank_id' => $rank->id,
    ]);
    makeActiveOnVesselAssignment($company, $inactive, $rank, $vessel);

    $this->actingAs($user)
        ->get(route('organization.crew-planning.index', ['view' => 'onboard-vessels']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('onboard_vessels', 0));
});

test('crew planning onboard view does not include cross-company vessels or crew', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCrewPlanningOnboardFixtures();
    makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $foreign = makeCrewAssignmentFixtures();
    makeActiveOnVesselAssignment(
        $foreign['company'],
        $foreign['employee'],
        $foreign['rank'],
        makeCrewMovementVessel('Foreign Onboard Vessel', $foreign['company']),
    );

    $this->actingAs($user)
        ->get(route('organization.crew-planning.index', ['view' => 'onboard-vessels']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('onboard_vessels', 1)
            ->where('onboard_vessels.0.id', $vessel->id)
            ->where('onboard_vessels.0.crew.0.employee.id', $employee->id));
});

test('crew planning onboard view matches crew assignments vessel view for the same company and filters', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCrewPlanningOnboardFixtures();
    makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $assignments = $this->actingAs($user)
        ->get(route('organization.crew-assignments.index', [
            'view' => 'vessel',
            'search' => $employee->name,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
        ]))
        ->assertOk()
        ->inertiaProps('vessels');

    $planning = $this->actingAs($user)
        ->get(route('organization.crew-planning.index', [
            'view' => 'onboard-vessels',
            'search' => $employee->name,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
        ]))
        ->assertOk()
        ->inertiaProps('onboard_vessels');

    expect($planning)->toEqual($assignments);
});

test('crew planning gantt data is unchanged when the onboard view query is omitted', function () {
    ['user' => $user] = makeCrewPlanningOnboardFixtures(['crew_operations.planning.view']);

    $this->actingAs($user)
        ->get(route('organization.crew-planning.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('view', 'planning')
            ->has('rows')
            ->has('bars')
            ->has('tree')
            ->has('onboard_vessels', 0));
});

test('crew planning onboard view requires assignment view permission', function () {
    ['user' => $user] = makeCrewPlanningOnboardFixtures(['crew_operations.planning.view']);

    $this->actingAs($user)
        ->get(route('organization.crew-planning.index', ['view' => 'onboard-vessels']))
        ->assertForbidden();
});

test('selected onboard export with only invalid ids does not fall back to export all', function () {
    Excel::fake();

    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = makeCrewPlanningOnboardFixtures();
    makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $this->actingAs($user)
        ->from(route('organization.crew-planning.index', ['view' => 'onboard-vessels']))
        ->get(route('organization.crew-assignments.onboard-vessels.export', [
            'format' => 'xlsx',
            'scope' => 'selected',
            'assignment_ids' => [999999],
        ]))
        ->assertRedirect(route('organization.crew-planning.index', ['view' => 'onboard-vessels']))
        ->assertSessionHasErrors('assignment_ids');
});
