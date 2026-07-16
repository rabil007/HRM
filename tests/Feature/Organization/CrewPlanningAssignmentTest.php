<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\CrewPlanningAssignment;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\Rank;
use App\Models\User;
use App\Models\Vessel;
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

test('store succeeds without vessel manning configured for the rank', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel] = makeAssignmentFixtures();

    $portCaptain = Rank::query()->create(['name' => 'Port Captain CPA', 'is_active' => true]);

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.store'), [
            'vessel_id' => $vessel->id,
            'rank_id' => $portCaptain->id,
            'planned_join_date' => '2027-02-01',
            'planned_leave_date' => '2027-08-31',
        ])
        ->assertRedirect();

    expect(CrewPlanningAssignment::query()->where('company_id', $company->id)->count())->toBe(1);
});

test('update succeeds when changing to a rank without vessel manning configured', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeAssignmentFixtures();

    $portCaptain = Rank::query()->create(['name' => 'Port Captain Update CPA', 'is_active' => true]);

    $assignment = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'planned_join_date' => '2027-02-01',
        'planned_leave_date' => '2027-08-31',
    ]);

    $this->actingAs($user)
        ->put(route('organization.crew-planning.assignments.update', $assignment), [
            'rank_id' => $portCaptain->id,
        ])
        ->assertRedirect();

    $assignment->refresh();
    expect($assignment->rank_id)->toBe($portCaptain->id);
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
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-planning.index', compact('from', 'to')))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('bars', 1)
            ->where('bars.0.planned_join_date', $today->addDays(5)->toDateString())
            ->where('bars.0.is_assigned', false)
        );
});

test('bars method marks manual relief assignments as not deployed', function () {
    ['company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeAssignmentFixtures();

    $employee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id]);
    $today = CarbonImmutable::today();
    $from = $today->startOfMonth()->toDateString();
    $to = $today->addMonths(2)->endOfMonth()->toDateString();

    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => $today->addDays(10)->toDateString(),
        'planned_leave_date' => $today->addDays(40)->toDateString(),
    ]);

    $bars = CrewPlanningGanttQuery::bars($company->id, $from, $to);

    expect($bars)->toHaveCount(1)
        ->and($bars[0]['employee_id'])->toBe($employee->id)
        ->and($bars[0]['is_assigned'])->toBeFalse();
});

test('bars method marks assignment-synced assignments as deployed', function () {
    ['company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeAssignmentFixtures();

    $employee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id]);
    $today = CarbonImmutable::today();
    $from = $today->startOfMonth()->toDateString();
    $to = $today->addMonths(2)->endOfMonth()->toDateString();

    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel, [
        'started_at' => $today->addDays(5),
    ]);

    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'crew_assignment_id' => $assignment->id,
        'planned_join_date' => $today->addDays(5)->toDateString(),
        'planned_leave_date' => $today->addDays(35)->toDateString(),
    ]);

    $bars = CrewPlanningGanttQuery::bars($company->id, $from, $to);

    expect($bars)->toHaveCount(1)
        ->and($bars[0]['is_assigned'])->toBeTrue();
});

test('tree method marks assignment-synced crew as deployed', function () {
    ['company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeAssignmentFixtures();

    $employee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id]);
    $today = CarbonImmutable::today();
    $from = $today->startOfMonth()->toDateString();
    $to = $today->addMonths(2)->endOfMonth()->toDateString();

    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel, [
        'started_at' => $today->addDays(5),
    ]);

    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'crew_assignment_id' => $assignment->id,
        'planned_join_date' => $today->addDays(5)->toDateString(),
        'planned_leave_date' => $today->addDays(35)->toDateString(),
    ]);

    $tree = CrewPlanningGanttQuery::tree($company->id, $from, $to);

    expect($tree)->toHaveCount(1)
        ->and($tree[0]['ranks'][0]['crew'][0]['is_assigned'])->toBeTrue();
});

test('tree method marks manual relief crew as not deployed', function () {
    ['company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeAssignmentFixtures();

    $employee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id]);
    $today = CarbonImmutable::today();
    $from = $today->startOfMonth()->toDateString();
    $to = $today->addMonths(2)->endOfMonth()->toDateString();

    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => $today->addDays(10)->toDateString(),
        'planned_leave_date' => $today->addDays(40)->toDateString(),
    ]);

    $tree = CrewPlanningGanttQuery::tree($company->id, $from, $to);

    expect($tree)->toHaveCount(1)
        ->and($tree[0]['ranks'][0]['crew'][0]['is_assigned'])->toBeFalse();
});

test('store links planned relief to the assignment being relieved', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeAssignmentFixtures();

    $deployedEmployee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id]);
    $reliefEmployee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id]);

    $assignment = makeActiveOnVesselAssignment($company, $deployedEmployee, $rank, $vessel);

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.store'), [
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'employee_id' => $reliefEmployee->id,
            'planned_join_date' => '2027-06-30',
            'planned_leave_date' => '2027-12-31',
            'relieves_crew_assignment_id' => $assignment->id,
        ])
        ->assertRedirect();

    $planningAssignment = CrewPlanningAssignment::query()->where('employee_id', $reliefEmployee->id)->first();

    expect($planningAssignment)->not->toBeNull()
        ->and($planningAssignment->relieves_crew_assignment_id)->toBe($assignment->id)
        ->and($planningAssignment->crew_assignment_id)->toBeNull();
});

test('store rejects relief linked to the same employee as the assignment', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeAssignmentFixtures();

    $employee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id]);

    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.store'), [
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'employee_id' => $employee->id,
            'planned_join_date' => '2027-06-30',
            'planned_leave_date' => '2027-12-31',
            'relieves_crew_assignment_id' => $assignment->id,
        ])
        ->assertSessionHasErrors('employee_id');
});

test('store rejects relief linked to an assignment on another vessel', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeAssignmentFixtures();

    $otherVessel = Vessel::query()->create([
        'name' => 'Other Relief Vessel',
        'vessel_type_id' => $vessel->vessel_type_id,
        'is_active' => true,
    ]);

    $deployedEmployee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id]);
    $reliefEmployee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id]);

    $assignment = makeActiveOnVesselAssignment($company, $deployedEmployee, $rank, $otherVessel);

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.store'), [
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'employee_id' => $reliefEmployee->id,
            'planned_join_date' => '2027-06-30',
            'planned_leave_date' => '2027-12-31',
            'relieves_crew_assignment_id' => $assignment->id,
        ])
        ->assertSessionHasErrors('relieves_crew_assignment_id');
});

test('bars include relief assignment linkage fields', function () {
    ['company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeAssignmentFixtures();

    $deployedEmployee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id, 'name' => 'Deployed Crew']);
    $reliefEmployee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id, 'name' => 'Relief Crew']);

    $assignment = makeActiveOnVesselAssignment($company, $deployedEmployee, $rank, $vessel);

    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $reliefEmployee->id,
        'relieves_crew_assignment_id' => $assignment->id,
        'planned_join_date' => '2027-06-30',
        'planned_leave_date' => '2027-12-31',
    ]);

    $bars = CrewPlanningGanttQuery::bars($company->id, '2027-01-01', '2027-12-31');

    expect($bars)->toHaveCount(1)
        ->and($bars[0]['relieves_crew_assignment_id'])->toBe($assignment->id)
        ->and($bars[0]['relieves_employee_name'])->toBe('Deployed Crew')
        ->and($bars[0]['is_assigned'])->toBeFalse();
});

test('store allows overlapping assignments for the same employee', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeAssignmentFixtures();

    $employee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id]);

    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-03-01',
        'planned_leave_date' => '2027-08-31',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.store'), [
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'employee_id' => $employee->id,
            'planned_join_date' => '2027-05-01',
            'planned_leave_date' => '2027-11-30',
        ])
        ->assertRedirect();

    expect(CrewPlanningAssignment::query()->where('employee_id', $employee->id)->count())->toBe(2);
});

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

test('store with no employee creates a vacant slot', function () {
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

test('update allows shifting assignment dates', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeAssignmentFixtures();

    $employee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id]);

    $assignment = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-02-01',
        'planned_leave_date' => '2027-08-31',
    ]);

    $this->actingAs($user)
        ->put(route('organization.crew-planning.assignments.update', $assignment), [
            'planned_join_date' => '2027-03-01',
            'planned_leave_date' => '2027-09-30',
        ])
        ->assertRedirect();

    $assignment->refresh();
    expect($assignment->planned_join_date->toDateString())->toBe('2027-03-01');
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
