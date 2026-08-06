<?php

use App\Models\CrewPlanningAssignment;
use App\Models\Employee;
use App\Models\User;
use App\Support\CrewPlanning\CreateCrewAssignmentFromPlanning;
use Illuminate\Support\Facades\DB;

test('guests cannot access crew assignments index for relief filters', function () {
    $this->get(route('organization.crew-assignments.index', [
        'relief_status' => 'no_relief',
    ]))->assertRedirect(route('login'));
});

test('users without assignment view permission cannot filter by relief', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeCrewAssignmentFixtures();

    grantCompanyPermissions($user, $company, []);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.index', [
            'relief_not_ready' => 1,
        ]))
        ->assertForbidden();
});

it('requires planning create permission to plan relief', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $viewer = User::factory()->create();
    DB::table('company_user')->insert([
        'company_id' => $fixtures['company']->id,
        'user_id' => $viewer->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $viewer->update(['current_company_id' => $fixtures['company']->id]);
    grantCompanyPermissions($viewer, $fixtures['company'], [
        'crew_operations.planning.view',
    ]);

    $source = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        makeCrewMovementVessel('Auth Viewer Vessel'),
    );
    $relief = Employee::factory()->forCompany($fixtures['company'])->create([
        'rank_id' => $fixtures['rank']->id,
        'status' => 'active',
    ]);

    $this->actingAs($viewer)->post(route('organization.crew-planning.assignments.store'), [
        'vessel_id' => $source->vessel_id,
        'rank_id' => $source->rank_id,
        'employee_id' => $relief->id,
        'planned_join_date' => now()->addDays(10)->toDateString(),
        'planned_leave_date' => now()->addDays(100)->toDateString(),
        'relieves_crew_assignment_id' => $source->id,
    ])->assertForbidden();
});

it('allows planning creator to plan relief and convert via support action', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $planner = $fixtures['user'];
    grantCompanyPermissions($planner, $fixtures['company'], [
        'crew_operations.planning.view',
        'crew_operations.planning.create',
        'crew_operations.planning.update',
        'crew_operations.assignments.create',
    ]);
    $planner->update(['current_company_id' => $fixtures['company']->id]);

    $source = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        makeCrewMovementVessel('Auth Planner Vessel'),
    );
    $relief = Employee::factory()->forCompany($fixtures['company'])->create([
        'rank_id' => $fixtures['rank']->id,
        'status' => 'active',
    ]);

    $this->actingAs($planner)->post(route('organization.crew-planning.assignments.store'), [
        'vessel_id' => $source->vessel_id,
        'rank_id' => $source->rank_id,
        'employee_id' => $relief->id,
        'planned_join_date' => now()->addDays(10)->toDateString(),
        'planned_leave_date' => now()->addDays(100)->toDateString(),
        'relieves_crew_assignment_id' => $source->id,
    ])->assertRedirect();

    $planning = CrewPlanningAssignment::query()
        ->where('relieves_crew_assignment_id', $source->id)
        ->firstOrFail();

    $assignment = app(CreateCrewAssignmentFromPlanning::class)->handle($planning, $planner->id);

    expect($planning->fresh()->crew_assignment_id)->toBe($assignment->id)
        ->and($planning->fresh()->relieves_crew_assignment_id)->toBe($source->id);
});

it('rejects cross-company relief source ids', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $other = makeCrewAssignmentFixtures();
    $user = $fixtures['user'];
    grantCompanyPermissions($user, $fixtures['company'], [
        'crew_operations.planning.create',
        'crew_operations.planning.update',
    ]);
    $user->update(['current_company_id' => $fixtures['company']->id]);

    $foreignSource = makeActiveOnVesselAssignment(
        $other['company'],
        $other['employee'],
        $other['rank'],
        makeCrewMovementVessel('Foreign Source Vessel'),
    );
    $relief = Employee::factory()->forCompany($fixtures['company'])->create([
        'rank_id' => $fixtures['rank']->id,
        'status' => 'active',
    ]);
    $localVessel = makeCrewMovementVessel('Local Auth Vessel');

    $this->actingAs($user)->post(route('organization.crew-planning.assignments.store'), [
        'vessel_id' => $localVessel->id,
        'rank_id' => $fixtures['rank']->id,
        'employee_id' => $relief->id,
        'planned_join_date' => now()->addDays(10)->toDateString(),
        'planned_leave_date' => now()->addDays(100)->toDateString(),
        'relieves_crew_assignment_id' => $foreignSource->id,
    ])->assertSessionHasErrors('relieves_crew_assignment_id');
});
