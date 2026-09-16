<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\Rank;
use App\Support\CrewPlanning\CreateCrewAssignmentFromPlanning;
use App\Support\CrewPlanning\StartCrewAssignmentFromPlanning;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

function planningStartPermissions(array $extra = []): array
{
    return array_values(array_unique(array_merge([
        'crew_operations.planning.view',
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.movements.perform',
    ], $extra)));
}

test('planning start entry requires movement permission even with planning view', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Permission Vessel');
    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.assignments.create',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'rank_id' => $rank->id,
        'status' => 'active',
    ]);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-04-01',
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create', ['planning_assignment_id' => $planning->id]))
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.start', $planning), [
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2027-04-01',
            'current_stage' => 'p1',
        ])
        ->assertForbidden();
});

test('planning start requires planning view permission', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Planning View Vessel');
    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.movements.perform',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'rank_id' => $rank->id,
        'status' => 'active',
    ]);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-04-01',
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create', ['planning_assignment_id' => $planning->id]))
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.start', $planning), [
            'current_stage' => 'p1',
        ])
        ->assertForbidden();
});

test('cross company planning id is rejected for start handoff', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    ['company' => $otherCompany, 'employee' => $otherEmployee] = makeCrewAssignmentFixtures();
    $otherVessel = makeCrewMovementVessel('Other Company Vessel', $otherCompany);

    grantCompanyPermissions($user, $company, planningStartPermissions());
    $user->update(['current_company_id' => $company->id]);

    $otherPlanning = CrewPlanningAssignment::query()->create([
        'company_id' => $otherCompany->id,
        'vessel_id' => $otherVessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $otherEmployee->id,
        'planned_join_date' => '2027-04-01',
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create', ['planning_assignment_id' => $otherPlanning->id]))
        ->assertNotFound();

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.start', $otherPlanning), [
            'employee_id' => $otherEmployee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $otherVessel->id,
            'planned_join_at' => '2027-04-01',
            'current_stage' => 'p1',
        ])
        ->assertNotFound();
});

test('opening start from planning does not create a crew assignment', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Preview Vessel');
    grantCompanyPermissions($user, $company, planningStartPermissions());
    $user->update(['current_company_id' => $company->id]);

    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'rank_id' => $rank->id,
        'status' => 'active',
    ]);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-04-01',
        'planned_leave_date' => '2027-09-30',
        'notes' => 'Planning notes',
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create', ['planning_assignment_id' => $planning->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew/create')
            ->has('planning_context')
            ->where('planning_context.planning_assignment_id', $planning->id)
            ->where('planning_context.employee_id', $employee->id)
            ->where('planning_context.rank_id', $rank->id)
            ->where('planning_context.vessel_id', $vessel->id)
            ->where('planning_context.planned_join_at', '2027-04-01')
            ->missing('planning_context.current_stage')
            ->where('planning_context.remarks', 'Planning notes')
        );

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('p1 start from planning uses trusted server time not planned join date', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Trusted Time Vessel');
    grantCompanyPermissions($user, $company, planningStartPermissions());
    $user->update(['current_company_id' => $company->id]);

    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'rank_id' => $rank->id,
        'status' => 'active',
    ]);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-09-20',
        'planned_leave_date' => '2027-12-31',
    ]);

    Carbon::setTestNow(Carbon::parse('2027-09-18 09:15:00', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.start', $planning), [
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2027-09-20',
            'stage_started_at' => '2027-09-20T08:00',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->firstOrFail();

    expect($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->source)->toBe('crew_planning')
        ->and($assignment->planned_join_at?->toDateString())->toBe('2027-09-20')
        ->and($assignment->planned_signoff_at?->toDateString())->toBe('2027-12-31')
        ->and($assignment->started_at?->timezone($company->timezone)->format('Y-m-d H:i'))->toBe('2027-09-18 09:15')
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreMobilisation)
        ->and($assignment->currentPhase?->actual_start_at?->timezone($company->timezone)->format('Y-m-d H:i'))->toBe('2027-09-18 09:15')
        ->and($planning->fresh()->crew_assignment_id)->toBe($assignment->id)
        ->and(EmployeeSeaService::query()->where('employee_id', $employee->id)->count())->toBe(0);
});

test('p0 start from planning creates only p0 phase', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('P0 Planning Vessel');
    grantCompanyPermissions($user, $company, planningStartPermissions());
    $user->update(['current_company_id' => $company->id]);

    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'rank_id' => $rank->id,
        'status' => 'active',
    ]);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-04-01',
    ]);

    Carbon::setTestNow(Carbon::parse('2027-04-01 08:00:00', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.start', $planning), [
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2027-04-01',
            'current_stage' => 'p0',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->firstOrFail();

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreMobilisation)
        ->and($assignment->phases)->toHaveCount(1)
        ->and($assignment->currentPhase?->status)->toBe(CrewPhaseStatus::Active);
});

test('planning start ignores browser current stage and starts in p0', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Direct P4 Vessel');
    grantCompanyPermissions($user, $company, planningStartPermissions());
    $user->update(['current_company_id' => $company->id]);

    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'rank_id' => $rank->id,
        'status' => 'active',
    ]);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-04-01',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.start', $planning), [
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2027-04-01',
            'current_stage' => 'p4',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->firstOrFail();
    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreMobilisation);
});

test('planning employee already p4 exposes active assignment conflict data without mutation', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $firstVessel = makeCrewMovementVessel('Vessel A');
    $secondVessel = makeCrewMovementVessel('Vessel B');
    grantCompanyPermissions($user, $company, planningStartPermissions());
    $user->update(['current_company_id' => $company->id]);

    $active = makeActiveOnVesselAssignment($company, $employee, $rank, $firstVessel);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $secondVessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-09-20',
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create', ['planning_assignment_id' => $planning->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew/create')
            ->has('planning_context')
            ->where('planning_context.vessel_name', $secondVessel->name)
            ->has("form_options.active_on_vessel_by_employee.{$employee->id}", fn (Assert $item) => $item
                ->where('assignment_id', $active->id)
                ->where('assignment_no', $active->assignment_no)
                ->where('vessel_id', $firstVessel->id)
                ->where('vessel_name', $firstVessel->name)
                ->etc()
            )
            ->where("form_options.employee_status_by_employee.{$employee->id}.has_active_assignment", true)
            ->where("form_options.employee_status_by_employee.{$employee->id}.status", 'on_vessel')
            ->where("form_options.employee_status_by_employee.{$employee->id}.current_phase", 'p4')
        );

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(1)
        ->and($planning->fresh()->crew_assignment_id)->toBeNull()
        ->and(CrewPlanningAssignment::query()->where('company_id', $company->id)->count())->toBe(1);
});

test('existing active assignment blocks planning start', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $firstVessel = makeCrewMovementVessel('Active Vessel');
    $secondVessel = makeCrewMovementVessel('Planned Vessel');
    grantCompanyPermissions($user, $company, planningStartPermissions());
    $user->update(['current_company_id' => $company->id]);

    $active = makeActiveOnVesselAssignment($company, $employee, $rank, $firstVessel);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $secondVessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-04-01',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.start', $planning), [
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $secondVessel->id,
            'planned_join_at' => '2027-04-01',
            'current_stage' => 'p1',
        ])
        ->assertSessionHasErrors('error');

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(1)
        ->and($planning->fresh()->crew_assignment_id)->toBeNull()
        ->and(CrewPlanningAssignment::query()->where('company_id', $company->id)->count())->toBe(1)
        ->and($active->fresh()->status)->toBe(CrewAssignmentStatus::Active)
        ->and($active->fresh()->vessel_id)->toBe($firstVessel->id);
});

test('already linked active assignment does not create duplicate on start', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Linked Active Vessel');
    grantCompanyPermissions($user, $company, planningStartPermissions());
    $user->update(['current_company_id' => $company->id]);

    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'rank_id' => $rank->id,
        'status' => 'active',
    ]);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-04-01',
    ]);

    $active = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);
    $planning->update(['crew_assignment_id' => $active->id]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create', ['planning_assignment_id' => $planning->id]))
        ->assertRedirect(route('organization.crew-assignments.show', $active));

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.start', $planning), [
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2027-04-01',
            'current_stage' => 'p1',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $active));

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(1);
});

test('existing linked draft redirects to assignment show for backward compatibility', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Linked Draft Vessel');
    grantCompanyPermissions($user, $company, planningStartPermissions());
    $user->update(['current_company_id' => $company->id]);

    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'rank_id' => $rank->id,
        'status' => 'active',
    ]);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-04-01',
    ]);

    $draft = app(CreateCrewAssignmentFromPlanning::class)->handle($planning, $user->id);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create', ['planning_assignment_id' => $planning->fresh()->id]))
        ->assertRedirect(route('organization.crew-assignments.show', $draft));

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.start', $planning->fresh()), [
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2027-04-01',
            'current_stage' => 'p1',
        ])
        ->assertSessionHasErrors('error');

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(1);
});

test('relieves crew assignment id remains preserved when starting from planning', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Relief Start Vessel');
    grantCompanyPermissions($user, $company, planningStartPermissions());
    $user->update(['current_company_id' => $company->id]);

    $onboardEmployee = Employee::factory()->create([
        'company_id' => $company->id,
        'rank_id' => $rank->id,
        'status' => 'active',
    ]);
    $onboardAssignment = makeActiveOnVesselAssignment($company, $onboardEmployee, $rank, $vessel);

    $reliefEmployee = Employee::factory()->create([
        'company_id' => $company->id,
        'rank_id' => $rank->id,
        'status' => 'active',
    ]);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $reliefEmployee->id,
        'planned_join_date' => '2027-05-01',
        'relieves_crew_assignment_id' => $onboardAssignment->id,
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.start', $planning), [
            'employee_id' => $reliefEmployee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2027-05-01',
            'current_stage' => 'p1',
        ])
        ->assertRedirect();

    expect($planning->fresh()->relieves_crew_assignment_id)->toBe($onboardAssignment->id);
});

test('legacy create crew assignment post redirects to unified start form', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Legacy Redirect Vessel');
    grantCompanyPermissions($user, $company, planningStartPermissions());
    $user->update(['current_company_id' => $company->id]);

    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'rank_id' => $rank->id,
        'status' => 'active',
    ]);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-04-01',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.create-crew-assignment', $planning))
        ->assertRedirect(route('organization.crew-assignments.create', [
            'planning_assignment_id' => $planning->id,
        ]));

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('start service creates exactly one assignment and links planning row', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Service Start Vessel');

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-04-01',
    ]);

    $result = app(StartCrewAssignmentFromPlanning::class)->handle($planning, [
        'current_stage' => 'p1',
    ]);

    expect($result['created_new'])->toBeTrue()
        ->and(CrewAssignment::query()->where('employee_id', $employee->id)->count())->toBe(1)
        ->and($planning->fresh()->crew_assignment_id)->toBe($result['assignment']->id)
        ->and(CrewPlanningAssignment::query()->where('company_id', $company->id)->count())->toBe(1);
});

test('crafted post cannot override authoritative planning master data', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank, 'employee' => $ahmed] = makeCrewAssignmentFixtures();
    $vesselA = makeCrewMovementVessel('Vessel A', $company);
    $vesselB = makeCrewMovementVessel('Vessel B', $company);
    $bosun = Rank::query()->create(['name' => 'Bosun '.uniqid(), 'is_active' => true]);
    $john = Employee::factory()->create([
        'company_id' => $company->id,
        'rank_id' => $bosun->id,
        'status' => 'active',
        'name' => 'John Smith',
    ]);

    grantCompanyPermissions($user, $company, planningStartPermissions());
    $user->update(['current_company_id' => $company->id]);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vesselA->id,
        'rank_id' => $rank->id,
        'employee_id' => $ahmed->id,
        'planned_join_date' => '2027-09-20',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.start', $planning), [
            'employee_id' => $john->id,
            'rank_id' => $bosun->id,
            'vessel_id' => $vesselB->id,
            'planned_join_at' => '2027-10-10',
            'current_stage' => 'p1',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->firstOrFail();

    expect($assignment->employee_id)->toBe($ahmed->id)
        ->and($assignment->rank_id)->toBe($rank->id)
        ->and($assignment->vessel_id)->toBe($vesselA->id)
        ->and($assignment->planned_join_at?->toDateString())->toBe('2027-09-20')
        ->and($planning->fresh()->employee_id)->toBe($ahmed->id)
        ->and($planning->fresh()->vessel_id)->toBe($vesselA->id)
        ->and($planning->fresh()->rank_id)->toBe($rank->id);
});

test('planning start rejects join date after planned sign off', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Invalid Date Vessel');
    grantCompanyPermissions($user, $company, planningStartPermissions());
    $user->update(['current_company_id' => $company->id]);

    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'rank_id' => $rank->id,
        'status' => 'active',
    ]);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2026-10-15',
        'planned_leave_date' => '2026-09-30',
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create', ['planning_assignment_id' => $planning->id]))
        ->assertRedirect(route('organization.crew-planning.index'))
        ->assertSessionHas('error');

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.start', $planning), [
            'current_stage' => 'p1',
        ])
        ->assertSessionHasErrors('error');

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('relief planning start ignores crafted vessel rank substitution', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vesselA = makeCrewMovementVessel('Relief Vessel A', $company);
    $vesselB = makeCrewMovementVessel('Relief Vessel B', $company);
    $bosun = Rank::query()->create(['name' => 'Relief Bosun '.uniqid(), 'is_active' => true]);

    grantCompanyPermissions($user, $company, planningStartPermissions());
    $user->update(['current_company_id' => $company->id]);

    $onboardEmployee = Employee::factory()->create([
        'company_id' => $company->id,
        'rank_id' => $rank->id,
        'status' => 'active',
    ]);
    $onboardAssignment = makeActiveOnVesselAssignment($company, $onboardEmployee, $rank, $vesselA);

    $reliefEmployee = Employee::factory()->create([
        'company_id' => $company->id,
        'rank_id' => $rank->id,
        'status' => 'active',
    ]);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vesselA->id,
        'rank_id' => $rank->id,
        'employee_id' => $reliefEmployee->id,
        'planned_join_date' => '2027-05-01',
        'relieves_crew_assignment_id' => $onboardAssignment->id,
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.start', $planning), [
            'employee_id' => $reliefEmployee->id,
            'rank_id' => $bosun->id,
            'vessel_id' => $vesselB->id,
            'planned_join_at' => '2027-06-01',
            'current_stage' => 'p1',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()
        ->where('company_id', $company->id)
        ->where('employee_id', $reliefEmployee->id)
        ->firstOrFail();

    expect($assignment->vessel_id)->toBe($vesselA->id)
        ->and($assignment->rank_id)->toBe($rank->id)
        ->and($assignment->planned_join_at?->toDateString())->toBe('2027-05-01')
        ->and($planning->fresh()->relieves_crew_assignment_id)->toBe($onboardAssignment->id)
        ->and($planning->fresh()->vessel_id)->toBe($vesselA->id)
        ->and($planning->fresh()->rank_id)->toBe($rank->id);
});
