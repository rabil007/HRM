<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Models\Rank;
use App\Models\User;
use App\Support\CrewMovements\CrewMovementService;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{user: User, company: Company, employee: Employee, rank: Rank}
 */
function makeCrewAssignmentOperationsFixtures(array $permissions = [
    'crew_operations.assignments.view',
    'crew_operations.assignments.create',
    'crew_operations.assignments.update',
    'crew_operations.movements.perform',
    'crew_operations.assignments.cancel',
]): array
{
    $fixtures = makeCrewAssignmentFixtures();

    grantCompanyPermissions($fixtures['user'], $fixtures['company'], $permissions);
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);

    return $fixtures;
}

test('guests cannot access current crew', function () {
    $this->get(route('organization.crew-assignments.index'))
        ->assertRedirect(route('login'));
});

test('users without view permission cannot access current crew', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentOperationsFixtures([]);

    grantCompanyPermissions($user, $company, []);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.index'))
        ->assertForbidden();
});

test('authorized users can view current crew index', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentOperationsFixtures();

    app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
    ], $user->id);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew/index')
            ->has('assignments')
            ->has('summary')
            ->has('filter_options')
            ->has('filters')
            ->has('can')
            ->where('summary.total', 1));
});

test('cross-company assignment show returns not found', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentOperationsFixtures();
    ['company' => $otherCompany, 'employee' => $otherEmployee, 'rank' => $otherRank] = makeCrewAssignmentFixtures();

    $foreign = app(CrewMovementService::class)->createDraft($otherCompany->id, $otherEmployee->id, [
        'rank_id' => $otherRank->id,
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.show', $foreign))
        ->assertNotFound();
});

test('authorized users can open create with global master data options', function () {
    ['user' => $user] = makeCrewAssignmentOperationsFixtures();

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew/create')
            ->has('form_options.employees')
            ->has('form_options.ranks')
            ->has('form_options.vessels')
            ->has('form_options.clients')
            ->has('form_options.visa_types'));
});

test('authorized users can create a draft assignment', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentOperationsFixtures();
    $vessel = makeCrewMovementVessel('Ops Create Vessel');

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-08-01',
            'remarks' => 'Created via UI',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->first();

    expect($assignment)->not->toBeNull()
        ->and($assignment->status)->toBe(CrewAssignmentStatus::Draft)
        ->and($assignment->assignment_no)->not->toBeEmpty()
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreMobilisation);
});

test('users without create permission cannot store assignments', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeCrewAssignmentOperationsFixtures([
        'crew_operations.assignments.view',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'employee_id' => $employee->id,
        ])
        ->assertForbidden();
});

test('search filters current crew assignments', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentOperationsFixtures();

    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
    ], $user->id);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.index', ['search' => $assignment->assignment_no]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('assignments', 1)
            ->where('assignments.0.assignment_no', $assignment->assignment_no));
});

test('phase filter works on current crew index', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentOperationsFixtures();
    $vessel = makeCrewMovementVessel('Phase Filter Vessel');

    app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
    ], $user->id);

    $otherEmployee = Employee::factory()->forCompany($company)->create([
        'rank_id' => $rank->id,
        'status' => 'active',
    ]);
    makeActiveOnVesselAssignment($company, $otherEmployee, $rank, $vessel, [
        'assignment_no' => 'CA-2026-PHASE1',
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.index', ['phase' => 'p0']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('assignments', 1)
            ->where('assignments.0.current_phase.code', 'p0'));
});
