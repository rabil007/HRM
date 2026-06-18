<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\CrewPlanningAssignment;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeDeployment;
use App\Models\EmployeeSeaService;
use App\Models\Rank;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselManning;
use App\Models\VesselType;
use App\Support\CrewPlanning\CrewPlanningGanttQuery;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

function makeAssignmentFixtures(): array
{
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'CPA',
        'name' => 'Crew Assign Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CPA',
        'name' => 'Crew Assign Currency',
        'symbol' => 'A$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Crew Assign Co',
        'slug' => 'crew-assign-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $otherCompany = Company::query()->create([
        'name' => 'Other Assign Co',
        'slug' => 'other-assign-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $vesselType = VesselType::query()->create(['name' => 'AHTS-CPA', 'is_active' => true]);
    $vessel = Vessel::query()->create(['name' => 'Assign Vessel Beta', 'vessel_type_id' => $vesselType->id, 'is_active' => true]);
    $rank = Rank::query()->create(['name' => 'Engineer CPA', 'is_active' => true]);

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.planning.create',
        'crew_operations.planning.update',
        'crew_operations.planning.delete',
        'crew_operations.planning.confirm',
    ]);

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'required_count' => 1,
    ]);

    return compact('user', 'company', 'otherCompany', 'vessel', 'rank');
}

// ─── Access control ────────────────────────────────────────────────────────────

test('guests cannot create assignments', function () {
    $this->post(route('organization.crew-planning.assignments.store'))
        ->assertRedirect(route('login'));
});

test('users without create permission cannot create assignments', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeAssignmentFixtures();

    grantCompanyPermissions($user, $company, ['crew_operations.planning.view']);

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.store'), [
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'planned_join_date' => '2027-01-01',
            'planned_leave_date' => '2027-06-30',
        ])
        ->assertForbidden();
});

test('guests cannot update assignments', function () {
    $this->put(route('organization.crew-planning.assignments.update', 999))
        ->assertRedirect(route('login'));
});

test('guests cannot delete assignments', function () {
    $this->delete(route('organization.crew-planning.assignments.destroy', 999))
        ->assertRedirect(route('login'));
});

// ─── Store ─────────────────────────────────────────────────────────────────────

test('authorized user can create an assignment', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeAssignmentFixtures();

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.store'), [
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'planned_join_date' => '2027-02-01',
            'planned_leave_date' => '2027-08-31',
            'notes' => 'Standby pool',
        ])
        ->assertRedirect();

    $assignment = CrewPlanningAssignment::query()
        ->where('company_id', $company->id)
        ->where('vessel_id', $vessel->id)
        ->where('rank_id', $rank->id)
        ->first();

    expect($assignment)->not->toBeNull()
        ->and($assignment->status)->toBe('draft')
        ->and($assignment->notes)->toBe('Standby pool')
        ->and($assignment->planned_join_date->toDateString())->toBe('2027-02-01')
        ->and($assignment->planned_leave_date->toDateString())->toBe('2027-08-31');
});

test('store validates required fields', function () {
    ['user' => $user] = makeAssignmentFixtures();

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.store'), [])
        ->assertSessionHasErrors(['vessel_id', 'rank_id', 'planned_join_date', 'planned_leave_date']);
});

test('store validates leave date is after or equal to join date', function () {
    ['user' => $user, 'vessel' => $vessel, 'rank' => $rank] = makeAssignmentFixtures();

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.store'), [
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'planned_join_date' => '2027-06-01',
            'planned_leave_date' => '2027-01-01',
        ])
        ->assertSessionHasErrors(['planned_leave_date']);
});

test('store rejects employee whose profile rank does not match assignment rank', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'rank' => $chiefRank] = makeAssignmentFixtures();

    $masterRank = Rank::query()->create(['name' => 'Master CPA', 'is_active' => true]);

    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'rank_id' => $masterRank->id,
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.store'), [
            'vessel_id' => $vessel->id,
            'rank_id' => $chiefRank->id,
            'employee_id' => $employee->id,
            'planned_join_date' => '2027-02-01',
            'planned_leave_date' => '2027-08-31',
        ])
        ->assertSessionHasErrors(['employee_id']);
});

test('store rejects ranks that are not configured on the vessel in vessel manning', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeAssignmentFixtures();

    $portCaptain = Rank::query()->create(['name' => 'Port Captain CPA', 'is_active' => true]);

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.store'), [
            'vessel_id' => $vessel->id,
            'rank_id' => $portCaptain->id,
            'planned_join_date' => '2027-02-01',
            'planned_leave_date' => '2027-08-31',
        ])
        ->assertSessionHasErrors(['rank_id']);

    expect(CrewPlanningAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('update rejects changing to a rank not configured on the vessel', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeAssignmentFixtures();

    $portCaptain = Rank::query()->create(['name' => 'Port Captain Update CPA', 'is_active' => true]);

    $assignment = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'planned_join_date' => '2027-02-01',
        'planned_leave_date' => '2027-08-31',
        'status' => 'draft',
    ]);

    $this->actingAs($user)
        ->put(route('organization.crew-planning.assignments.update', $assignment), [
            'rank_id' => $portCaptain->id,
        ])
        ->assertSessionHasErrors(['rank_id']);

    $assignment->refresh();
    expect($assignment->rank_id)->toBe($rank->id);
});

// ─── Update ────────────────────────────────────────────────────────────────────

test('authorized user can update their own assignment', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeAssignmentFixtures();

    $assignment = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'planned_join_date' => '2027-02-01',
        'planned_leave_date' => '2027-08-31',
        'status' => 'draft',
    ]);

    $this->actingAs($user)
        ->put(route('organization.crew-planning.assignments.update', $assignment), [
            'planned_join_date' => '2027-03-01',
            'planned_leave_date' => '2027-09-30',
            'notes' => 'Updated notes',
        ])
        ->assertRedirect();

    $assignment->refresh();
    expect($assignment->planned_join_date->toDateString())->toBe('2027-03-01')
        ->and($assignment->planned_leave_date->toDateString())->toBe('2027-09-30')
        ->and($assignment->notes)->toBe('Updated notes');
});

test('update is scoped to current company', function () {
    ['user' => $user, 'otherCompany' => $otherCompany, 'vessel' => $vessel, 'rank' => $rank] = makeAssignmentFixtures();

    $foreignAssignment = CrewPlanningAssignment::query()->create([
        'company_id' => $otherCompany->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'planned_join_date' => '2027-01-01',
        'planned_leave_date' => '2027-06-30',
        'status' => 'draft',
    ]);

    $this->actingAs($user)
        ->put(route('organization.crew-planning.assignments.update', $foreignAssignment), [
            'planned_join_date' => '2027-04-01',
        ])
        ->assertNotFound();
});

// ─── Destroy ───────────────────────────────────────────────────────────────────

test('authorized user can delete an assignment', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeAssignmentFixtures();

    $assignment = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'planned_join_date' => '2027-02-01',
        'planned_leave_date' => '2027-08-31',
        'status' => 'draft',
    ]);

    $this->actingAs($user)
        ->delete(route('organization.crew-planning.assignments.destroy', $assignment))
        ->assertRedirect();

    expect(CrewPlanningAssignment::withTrashed()->find($assignment->id)?->deleted_at)->not->toBeNull();
});

test('destroy is scoped to current company', function () {
    ['user' => $user, 'otherCompany' => $otherCompany, 'vessel' => $vessel, 'rank' => $rank] = makeAssignmentFixtures();

    $foreignAssignment = CrewPlanningAssignment::query()->create([
        'company_id' => $otherCompany->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'planned_join_date' => '2027-01-01',
        'planned_leave_date' => '2027-06-30',
        'status' => 'draft',
    ]);

    $this->actingAs($user)
        ->delete(route('organization.crew-planning.assignments.destroy', $foreignAssignment))
        ->assertNotFound();

    expect(CrewPlanningAssignment::find($foreignAssignment->id))->not->toBeNull();
});

// ─── Gantt bars integration ────────────────────────────────────────────────────

test('assignment bars are included in the gantt bars response', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeAssignmentFixtures();

    $today = CarbonImmutable::today();
    $from = $today->startOfMonth()->toDateString();
    $to = $today->addMonths(2)->endOfMonth()->toDateString();

    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'planned_join_date' => $today->addDays(5)->toDateString(),
        'planned_leave_date' => $today->addDays(35)->toDateString(),
        'status' => 'draft',
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-planning.index', compact('from', 'to')))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('bars', 1)
            ->where('bars.0.source', 'assignment')
            ->where('bars.0.status', 'draft')
        );
});

test('bars method combines deployment and assignment bars', function () {
    ['company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeAssignmentFixtures();

    $employee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id]);
    $today = CarbonImmutable::today();
    $from = $today->startOfMonth()->toDateString();
    $to = $today->addMonths(2)->endOfMonth()->toDateString();

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'joined_date' => $today->subDays(5)->toDateString(),
        'disembarked_date' => null,
        'sort_order' => 0,
    ]);

    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'planned_join_date' => $today->addDays(10)->toDateString(),
        'planned_leave_date' => $today->addDays(40)->toDateString(),
        'status' => 'draft',
    ]);

    $bars = CrewPlanningGanttQuery::bars($company->id, $from, $to);

    $sources = collect($bars)->pluck('source')->unique()->sort()->values()->all();
    expect($sources)->toBe(['assignment', 'deployment'])
        ->and($bars)->toHaveCount(2);
});

// ─── Overlap / double-booking validation ──────────────────────────────────────

test('store allows overlapping rank dates for different employees', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeAssignmentFixtures();

    $firstEmployee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id]);
    $secondEmployee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id]);

    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $firstEmployee->id,
        'planned_join_date' => '2027-06-14',
        'planned_leave_date' => '2027-06-18',
        'status' => 'draft',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.store'), [
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'employee_id' => $secondEmployee->id,
            'planned_join_date' => '2027-06-15',
            'planned_leave_date' => '2027-06-18',
        ])
        ->assertRedirect();

    expect(CrewPlanningAssignment::query()->where('employee_id', $secondEmployee->id)->exists())->toBeTrue();
});

test('store ignores stale older deployments when checking for overlap', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeAssignmentFixtures();

    $employee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id]);

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'joined_date' => '2024-01-01',
        'disembarked_date' => null,
        'sort_order' => 0,
    ]);

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'joined_date' => '2027-01-01',
        'disembarked_date' => '2027-06-10',
        'sort_order' => 1,
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.store'), [
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'employee_id' => $employee->id,
            'planned_join_date' => '2027-06-15',
            'planned_leave_date' => '2027-06-18',
        ])
        ->assertRedirect();
});

test('store is rejected when employee already has an overlapping draft assignment', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeAssignmentFixtures();

    $employee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id]);

    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-03-01',
        'planned_leave_date' => '2027-08-31',
        'status' => 'draft',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.store'), [
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'employee_id' => $employee->id,
            'planned_join_date' => '2027-05-01',
            'planned_leave_date' => '2027-11-30',
        ])
        ->assertSessionHasErrors(['planned_join_date']);
});

test('store is rejected when employee already has an overlapping deployment', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeAssignmentFixtures();

    $employee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id]);
    $today = CarbonImmutable::today();

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'joined_date' => $today->subDays(10)->toDateString(),
        'disembarked_date' => null,
        'sort_order' => 0,
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.store'), [
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'employee_id' => $employee->id,
            'planned_join_date' => $today->addDays(5)->toDateString(),
            'planned_leave_date' => $today->addDays(60)->toDateString(),
        ])
        ->assertSessionHasErrors(['planned_join_date']);
});

test('store with no employee (vacant slot) skips overlap check', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeAssignmentFixtures();

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.store'), [
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'employee_id' => null,
            'planned_join_date' => '2027-03-01',
            'planned_leave_date' => '2027-08-31',
        ])
        ->assertRedirect();

    expect(CrewPlanningAssignment::query()->where('employee_id', null)->count())->toBe(1);
});

test('update (date shift from drag) is rejected when new dates cause overlap', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeAssignmentFixtures();

    $employee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id]);

    $existing = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-01-01',
        'planned_leave_date' => '2027-03-31',
        'status' => 'draft',
    ]);

    $conflict = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-06-01',
        'planned_leave_date' => '2027-09-30',
        'status' => 'draft',
    ]);

    $this->actingAs($user)
        ->put(route('organization.crew-planning.assignments.update', $existing), [
            'planned_join_date' => '2027-05-01',
            'planned_leave_date' => '2027-07-31',
        ])
        ->assertSessionHasErrors(['planned_join_date']);
});

test('update excludes self when checking for overlaps', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeAssignmentFixtures();

    $employee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id]);

    $assignment = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-02-01',
        'planned_leave_date' => '2027-08-31',
        'status' => 'draft',
    ]);

    // Shifting the same assignment's dates slightly — should not clash with itself
    $this->actingAs($user)
        ->put(route('organization.crew-planning.assignments.update', $assignment), [
            'planned_join_date' => '2027-03-01',
            'planned_leave_date' => '2027-09-30',
        ])
        ->assertRedirect();

    $assignment->refresh();
    expect($assignment->planned_join_date->toDateString())->toBe('2027-03-01');
});

// ─── Confirm draft → deployment ──────────────────────────────────────────────

test('guests cannot confirm assignments', function () {
    $this->post(route('organization.crew-planning.assignments.confirm', 999))
        ->assertRedirect(route('login'));
});

test('users without confirm permission cannot confirm assignments', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeAssignmentFixtures();

    grantCompanyPermissions($user, $company, ['crew_operations.planning.view']);

    $employee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id]);

    $assignment = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-02-01',
        'planned_leave_date' => '2027-08-31',
        'status' => 'draft',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.confirm', $assignment))
        ->assertForbidden();
});

test('confirm creates deployment and links assignment', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeAssignmentFixtures();

    $employee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id]);

    $assignment = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-02-01',
        'planned_leave_date' => '2027-08-31',
        'status' => 'draft',
        'notes' => 'Ready to deploy',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.confirm', $assignment))
        ->assertRedirect();

    $assignment->refresh();

    expect($assignment->status)->toBe('confirmed')
        ->and($assignment->employee_deployment_id)->not->toBeNull();

    $deployment = EmployeeDeployment::query()->findOrFail($assignment->employee_deployment_id);

    expect($deployment->company_id)->toBe($company->id)
        ->and($deployment->employee_id)->toBe($employee->id)
        ->and($deployment->vessel_id)->toBe($vessel->id)
        ->and($deployment->rank_id)->toBe($rank->id)
        ->and($deployment->joined_date->toDateString())->toBe('2027-02-01')
        ->and($deployment->disembarked_date->toDateString())->toBe('2027-08-31')
        ->and($deployment->remarks)->toBe('Ready to deploy');

    expect(EmployeeSeaService::query()->where('employee_deployment_id', $deployment->id)->exists())->toBeTrue();
});

test('confirm is rejected for vacant assignments', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeAssignmentFixtures();

    $assignment = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => null,
        'planned_join_date' => '2027-02-01',
        'planned_leave_date' => '2027-08-31',
        'status' => 'draft',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.confirm', $assignment))
        ->assertSessionHasErrors(['employee_id']);

    expect(EmployeeDeployment::query()->count())->toBe(0);
});

test('confirm is rejected when assignment is already confirmed', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeAssignmentFixtures();

    $employee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id]);

    $assignment = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-02-01',
        'planned_leave_date' => '2027-08-31',
        'status' => 'confirmed',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.confirm', $assignment))
        ->assertSessionHasErrors(['assignment']);
});

test('confirm is rejected when employee would double-book', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeAssignmentFixtures();

    $employee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id]);

    EmployeeDeployment::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'joined_date' => '2027-03-01',
        'disembarked_date' => '2027-09-30',
        'sort_order' => 0,
    ]);

    $assignment = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-05-01',
        'planned_leave_date' => '2027-07-31',
        'status' => 'draft',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.confirm', $assignment))
        ->assertSessionHasErrors(['planned_join_date']);

    $assignment->refresh();
    expect($assignment->status)->toBe('draft')
        ->and($assignment->employee_deployment_id)->toBeNull();
});

test('confirmed assignments no longer appear as draft bars in gantt data', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeAssignmentFixtures();

    $employee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id]);

    $assignment = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-02-01',
        'planned_leave_date' => '2027-08-31',
        'status' => 'draft',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.confirm', $assignment))
        ->assertRedirect();

    $bars = CrewPlanningGanttQuery::bars($company->id, '2027-01-01', '2027-12-31');

    expect(collect($bars)->where('source', 'assignment')->count())->toBe(0)
        ->and(collect($bars)->where('source', 'deployment')->count())->toBe(1);
});

test('confirmed assignments cannot be updated', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeAssignmentFixtures();

    $employee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id]);

    $assignment = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-02-01',
        'planned_leave_date' => '2027-08-31',
        'status' => 'confirmed',
    ]);

    $this->actingAs($user)
        ->put(route('organization.crew-planning.assignments.update', $assignment), [
            'planned_join_date' => '2027-03-01',
        ])
        ->assertSessionHasErrors(['assignment']);
});

// ─── index page includes employees prop ────────────────────────────────────────

test('planning index includes active employees list', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeAssignmentFixtures();

    Employee::factory()->count(3)->create([
        'company_id' => $company->id,
        'rank_id' => $rank->id,
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-planning.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('employees', 3)
            ->where('can.create', true)
            ->where('can.update', true)
            ->where('can.delete', true)
            ->where('can.confirm', true)
        );
});

test('assignments reject employees without a profile rank', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeAssignmentFixtures();

    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'rank_id' => null,
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.store'), [
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'employee_id' => $employee->id,
            'planned_join_date' => '2027-02-01',
            'planned_leave_date' => '2027-08-31',
        ])
        ->assertSessionHasErrors(['employee_id']);
});
