<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Exceptions\CrewMovementException;
use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\Rank;
use App\Support\CrewMovements\CrewAssignmentConflictContext;
use App\Support\CrewMovements\CrewAssignmentConflictEvaluator;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\CrewPlanning\CrewPlanningGanttQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

test('assignment can be saved as draft and does not reserve employee', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Draft Test Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'draft',
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-10-10',
            'planned_signoff_at' => '2026-11-20',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->latest('id')->first();

    expect($assignment)->not->toBeNull()
        ->and($assignment->status)->toBe(CrewAssignmentStatus::Draft)
        ->and($assignment->planned_join_at?->toDateString())->toBe('2026-10-10')
        ->and($assignment->planned_signoff_at?->toDateString())->toBe('2026-11-20')
        ->and(CrewPlanningAssignment::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(0);

    // Another assignment can plan or start during same dates without being blocked by draft
    $evaluator = new CrewAssignmentConflictEvaluator;
    $context = new CrewAssignmentConflictContext(
        companyId: $company->id,
        employeeId: $employee->id,
        action: 'plan',
        plannedJoinAt: CarbonImmutable::parse('2026-10-15'),
        plannedSignoffAt: CarbonImmutable::parse('2026-11-15'),
    );

    $result = $evaluator->evaluate($context);
    expect($result->blocking)->toBeFalse();
});

test('assignment can be saved as planned and reserves employee', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Planned Test Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.planning.create',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'plan',
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-10-10',
            'planned_signoff_at' => '2026-11-30',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->latest('id')->first();

    expect($assignment)->not->toBeNull()
        ->and($assignment->status)->toBe(CrewAssignmentStatus::Planned)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreMobilisation)
        ->and($assignment->currentPhase?->status)->toBe(CrewPhaseStatus::Planned)
        ->and(EmployeeSeaService::query()->where('employee_id', $employee->id)->count())->toBe(0)
        ->and(CrewPlanningAssignment::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(0);

    // Now conflict evaluator detects the planned reservation
    $evaluator = new CrewAssignmentConflictEvaluator;
    $conflictContext = new CrewAssignmentConflictContext(
        companyId: $company->id,
        employeeId: $employee->id,
        action: 'plan',
        plannedJoinAt: CarbonImmutable::parse('2026-10-20'),
        plannedSignoffAt: CarbonImmutable::parse('2026-12-15'),
    );

    $result = $evaluator->evaluate($conflictContext);
    expect($result->blocking)->toBeTrue()
        ->and($result->code)->toBe('planned_planned_overlap')
        ->and($result->allowedActions)->toContain('adjust_dates', 'edit_existing_plan', 'cancel_existing_plan', 'cancel');
});

test('planned assignment appears in Planning Gantt query without duplicate records', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Gantt Test Vessel', $company);

    $service = app(CrewMovementService::class);
    $assignment = $service->createPlanned($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-10-10',
        'planned_signoff_at' => '2026-11-30',
    ], $user->id);

    $bars = CrewPlanningGanttQuery::bars($company->id, '2026-10-01', '2026-12-31');
    $bar = collect($bars)->firstWhere('crew_assignment_id', $assignment->id);

    expect($bar)->not->toBeNull()
        ->and($bar['employee_id'])->toBe($employee->id)
        ->and($bar['vessel_name'])->toBe($vessel->name)
        ->and($bar['planned_join_date'])->toBe('2026-10-10')
        ->and($bar['planned_leave_date'])->toBe('2026-11-30')
        ->and($bar['status'])->toBe(CrewAssignmentStatus::Planned->value)
        ->and($bar['is_assigned'])->toBeFalse()
        ->and(CrewPlanningAssignment::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(0);
});

test('assignment can start directly without planning first', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Direct Start Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.movements.perform',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-09-20',
            'planned_signoff_at' => '2026-11-20',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->latest('id')->first();

    expect($assignment)->not->toBeNull()
        ->and($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreMobilisation)
        ->and($assignment->currentPhase?->status)->toBe(CrewPhaseStatus::Active)
        ->and($assignment->currentPhase?->actual_start_at)->not->toBeNull()
        ->and(CrewPlanningAssignment::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(0);
});

test('planned assignment can transition to active on the SAME CrewAssignment record', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Transition Vessel', $company);

    $service = app(CrewMovementService::class);
    $planned = $service->createPlanned($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-10-10',
        'planned_signoff_at' => '2026-11-30',
    ], $user->id);

    $originalId = $planned->id;
    $originalNo = $planned->assignment_no;

    expect($planned->status)->toBe(CrewAssignmentStatus::Planned);

    // Mobilisation approved (start assignment)
    $active = $service->perform($company->id, $planned->id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-09-20 10:00:00',
    ], $user->id);

    expect($active->id)->toBe($originalId)
        ->and($active->assignment_no)->toBe($originalNo)
        ->and($active->status)->toBe(CrewAssignmentStatus::Active)
        ->and($active->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreMobilisation)
        ->and($active->currentPhase?->status)->toBe(CrewPhaseStatus::Active)
        ->and($active->started_at)->not->toBeNull()
        ->and(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(1);
});

test('planned to active transition reruns conflict check and blocks if circumstances changed', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel1 = makeCrewMovementVessel('Vessel 1', $company);
    $vessel2 = makeCrewMovementVessel('Vessel 2', $company);

    $service = app(CrewMovementService::class);
    $planned = $service->createPlanned($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel1->id,
        'planned_join_at' => '2026-10-10',
        'planned_signoff_at' => '2026-11-30',
    ], $user->id);

    // Concurrent Active that ends before the Planned window — allowed at start time,
    // but still blocks Planned → Active because an Active assignment already exists.
    $service->startAssignment($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel2->id,
        'planned_signoff_at' => '2026-09-30',
        'stage_started_at' => '2026-09-15 08:00:00',
    ], $user->id);

    // Attempting to activate the planned assignment must be blocked
    expect(fn () => $service->perform($company->id, $planned->id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-09-20 10:00:00',
    ], $user->id))->toThrow(ValidationException::class);
});

test('planned vs active overlap is detected with actionable context', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Active Conflict Vessel', $company);

    $service = app(CrewMovementService::class);
    $active = $service->startAssignment($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-09-15',
        'planned_signoff_at' => '2026-10-31',
        'stage_started_at' => '2026-09-15 08:00:00',
    ], $user->id);

    // Try planning an overlapping future assignment
    $evaluator = new CrewAssignmentConflictEvaluator;
    $context = new CrewAssignmentConflictContext(
        companyId: $company->id,
        employeeId: $employee->id,
        action: 'plan',
        plannedJoinAt: CarbonImmutable::parse('2026-10-15'),
        plannedSignoffAt: CarbonImmutable::parse('2026-11-30'),
    );

    $result = $evaluator->evaluate($context);

    expect($result->blocking)->toBeTrue()
        ->and($result->code)->toBe('active_planned_overlap')
        ->and($result->allowedActions)->toContain('reschedule', 'view_current_assignment', 'cancel');
});

test('incompatible active vs active start is hard blocked', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel1 = makeCrewMovementVessel('Vessel A', $company);
    $vessel2 = makeCrewMovementVessel('Vessel B', $company);

    $service = app(CrewMovementService::class);
    $service->startAssignment($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel1->id,
        'stage_started_at' => '2026-09-15 08:00:00',
    ], $user->id);

    expect(fn () => $service->startAssignment($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel2->id,
        'stage_started_at' => '2026-09-15 09:00:00',
    ], $user->id))->toThrow(CrewMovementException::class, 'already has an active assignment');
});

test('cancelling a planned assignment releases employee reservation', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Cancel Plan Vessel', $company);

    $service = app(CrewMovementService::class);
    $planned = $service->createPlanned($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-10-10',
        'planned_signoff_at' => '2026-11-30',
    ], $user->id);

    $evaluator = new CrewAssignmentConflictEvaluator;
    $context = new CrewAssignmentConflictContext(
        companyId: $company->id,
        employeeId: $employee->id,
        action: 'plan',
        plannedJoinAt: CarbonImmutable::parse('2026-10-20'),
        plannedSignoffAt: CarbonImmutable::parse('2026-11-20'),
    );

    // Initially blocked
    expect($evaluator->evaluate($context)->blocking)->toBeTrue();

    // Cancel the planned assignment
    $service->perform($company->id, $planned->id, CrewMovementAction::CancelAssignment, [
        'reason' => 'Operator cancelled test plan',
    ], $user->id);

    expect($planned->fresh()->status)->toBe(CrewAssignmentStatus::Cancelled);

    // Now employee is free for that date range
    expect($evaluator->evaluate($context)->blocking)->toBeFalse();
});

test('invalid date sequence is rejected on planning', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Date Check Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.planning.create',
    ]);
    $user->update(['current_company_id' => $company->id]);

    // Sign-off before join
    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'plan',
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-11-30',
            'planned_signoff_at' => '2026-10-10',
        ])
        ->assertSessionHasErrors('planned_signoff_at');

    // Arrival after join
    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'plan',
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_arrival_at' => '2026-10-15',
            'planned_join_at' => '2026-10-10',
            'planned_signoff_at' => '2026-11-30',
        ])
        ->assertSessionHasErrors('planned_arrival_at');
});

test('save as planned requires planning create permission and denies when missing', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Perm Test Vessel', $company);

    // Has assignment create but lacks planning create
    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'plan',
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-10-10',
            'planned_signoff_at' => '2026-11-30',
        ])
        ->assertForbidden();

    // Now grant planning.create
    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.planning.create',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'plan',
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-10-10',
            'planned_signoff_at' => '2026-11-30',
        ])
        ->assertRedirect();
});

test('tenant isolation rejects cross-company employee and vessel on plan creation', function () {
    ['user' => $user, 'company' => $company, 'employee' => $localEmployee] = makeCrewAssignmentFixtures();
    ['company' => $otherCompany, 'employee' => $foreignEmployee] = makeCrewAssignmentFixtures();
    $foreignVessel = makeCrewMovementVessel('Foreign Vessel', $otherCompany);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.planning.create',
    ]);
    $user->update(['current_company_id' => $company->id]);

    // Foreign employee
    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'plan',
            'employee_id' => $foreignEmployee->id,
            'planned_join_at' => '2026-10-10',
            'planned_signoff_at' => '2026-11-30',
        ])
        ->assertSessionHasErrors('employee_id');

    // Foreign vessel
    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'plan',
            'employee_id' => $localEmployee->id,
            'vessel_id' => $foreignVessel->id,
            'planned_join_at' => '2026-10-10',
            'planned_signoff_at' => '2026-11-30',
        ])
        ->assertSessionHasErrors('vessel_id');
});

test('planned save creates no actual movement events or sea service', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('No Sea Service Vessel', $company);

    $service = app(CrewMovementService::class);
    $assignment = $service->createPlanned($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-10-10',
        'planned_signoff_at' => '2026-11-30',
    ], $user->id);

    // Phases count is 1, but status is Planned, actuals are null
    expect($assignment->phases)->toHaveCount(1)
        ->and($assignment->phases->first()->status)->toBe(CrewPhaseStatus::Planned)
        ->and($assignment->phases->first()->actual_start_at)->toBeNull()
        ->and($assignment->phases->first()->actual_end_at)->toBeNull();

    // Zero sea service
    expect(EmployeeSeaService::query()->where('employee_id', $employee->id)->count())->toBe(0);

    // Planned signoff remains purely planned
    expect($assignment->planned_signoff_at?->toDateString())->toBe('2026-11-30')
        ->and($assignment->currentPhase?->actual_end_at)->toBeNull();
});

test('audit logs meaningful activity for planned lifecycle transitions', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Audit Test Vessel', $company);

    $service = app(CrewMovementService::class);
    $assignment = $service->createPlanned($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-10-10',
        'planned_signoff_at' => '2026-11-30',
    ], $user->id);

    $logged = Activity::query()
        ->where('subject_type', CrewAssignment::class)
        ->where('subject_id', $assignment->id)
        ->where('description', 'planned_assignment_confirmed')
        ->first();

    expect($logged)->not->toBeNull()
        ->and($logged->causer_id)->toBe($user->id);
});

test('editing planned dates reruns conflict detection and blocks overlapping dates', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel1 = makeCrewMovementVessel('Edit Date Vessel 1', $company);
    $vessel2 = makeCrewMovementVessel('Edit Date Vessel 2', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.assignments.update',
        'crew_operations.planning.create',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $service = app(CrewMovementService::class);

    // Plan A: Oct 10 - Oct 31
    $planA = $service->createPlanned($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel1->id,
        'planned_join_at' => '2026-10-10',
        'planned_signoff_at' => '2026-10-31',
    ], $user->id);

    // Plan B: Dec 01 - Dec 31 (non-overlapping)
    $planB = $service->createPlanned($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel2->id,
        'planned_join_at' => '2026-12-01',
        'planned_signoff_at' => '2026-12-31',
    ], $user->id);

    // Now edit Plan B dates so they overlap Plan A (Oct 15 - Nov 15)
    $this->actingAs($user)
        ->put(route('organization.crew-assignments.update', $planB->id), [
            'planned_join_at' => '2026-10-15',
            'planned_signoff_at' => '2026-11-15',
        ])
        ->assertSessionHasErrors('employee_id');
});

test('concurrent conflict evaluator locks and prevents conflicting reservations', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Concurrent Lock Vessel', $company);

    $service = app(CrewMovementService::class);
    $service->createPlanned($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-10-10',
        'planned_signoff_at' => '2026-11-30',
    ], $user->id);

    $evaluator = new CrewAssignmentConflictEvaluator;
    $context = new CrewAssignmentConflictContext(
        companyId: $company->id,
        employeeId: $employee->id,
        action: 'plan',
        plannedJoinAt: CarbonImmutable::parse('2026-10-15'),
        plannedSignoffAt: CarbonImmutable::parse('2026-11-15'),
    );

    // Evaluator withLock: true detects conflicting reservation under transaction
    expect(fn () => DB::transaction(fn () => $evaluator->assertNoBlockingConflicts($context, withLock: true)))
        ->toThrow(ValidationException::class);
});

test('planning-only user can open plan form and save as planned without assignments.create', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Plan Only Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.planning.create',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create', ['intent' => 'plan']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew/create')
            ->where('intent', 'plan')
            ->where('can.plan', true)
            ->where('can.create', false)
            ->where('can.start', false)
        );

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'plan',
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-10-10',
            'planned_signoff_at' => '2026-11-30',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->latest('id')->first();

    expect($assignment)->not->toBeNull()
        ->and($assignment->status)->toBe(CrewAssignmentStatus::Planned)
        ->and(CrewPlanningAssignment::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(0);
});

test('planning-only user cannot draft or start and crafted start intent is forbidden', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Plan Escalation Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.planning.create',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'draft',
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-10-10',
            'planned_signoff_at' => '2026-11-30',
        ])
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-10-10',
            'planned_signoff_at' => '2026-11-30',
        ])
        ->assertForbidden();

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('planned to active rechecks full planned date range against overlapping planned assignment', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel1 = makeCrewMovementVessel('Range Vessel A', $company);
    $vessel2 = makeCrewMovementVessel('Range Vessel B', $company);

    $service = app(CrewMovementService::class);

    $planA = $service->createPlanned($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel1->id,
        'planned_join_at' => '2026-10-01',
        'planned_signoff_at' => '2026-11-30',
    ], $user->id);

    // Create a non-overlapping plan, then force an overlapping reservation that would
    // only be caught when Plan A activates (full planned range recheck).
    $planB = $service->createPlanned($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel2->id,
        'planned_join_at' => '2026-12-15',
        'planned_signoff_at' => '2026-12-31',
    ], $user->id);

    $planB->forceFill([
        'planned_join_at' => '2026-11-15',
        'planned_signoff_at' => '2026-12-31',
    ])->save();

    expect(fn () => $service->perform($company->id, $planA->id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-09-20 10:00:00',
    ], $user->id))->toThrow(ValidationException::class);

    expect($planA->fresh()->status)->toBe(CrewAssignmentStatus::Planned)
        ->and($planA->fresh()->id)->toBe($planA->id)
        ->and($planA->fresh()->assignment_no)->toBe($planA->assignment_no);
});

test('same planned assignment can start successfully without conflicting with itself', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Self Start Vessel', $company);

    $service = app(CrewMovementService::class);
    $planned = $service->createPlanned($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-10-01',
        'planned_signoff_at' => '2026-11-30',
    ], $user->id);

    $active = $service->perform($company->id, $planned->id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-09-20 10:00:00',
    ], $user->id);

    expect($active->id)->toBe($planned->id)
        ->and($active->assignment_no)->toBe($planned->assignment_no)
        ->and($active->status)->toBe(CrewAssignmentStatus::Active);
});

test('vacant planning slot can be linked on plan store and rejects already-linked or incompatible slots', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Slot Link Vessel', $company);
    $otherVessel = makeCrewMovementVessel('Other Slot Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.planning.view',
        'crew_operations.planning.create',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $vacant = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => null,
        'planned_join_date' => '2026-10-10',
        'planned_leave_date' => '2026-11-30',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'plan',
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-10-10',
            'planned_signoff_at' => '2026-11-30',
            'planning_assignment_id' => $vacant->id,
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->latest('id')->first();
    expect($vacant->fresh()->crew_assignment_id)->toBe($assignment->id);

    $employee2 = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id, 'status' => 'active']);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'plan',
            'employee_id' => $employee2->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2027-01-01',
            'planned_signoff_at' => '2027-02-01',
            'planning_assignment_id' => $vacant->id,
        ])
        ->assertSessionHasErrors('planning_assignment_id');

    $incompatible = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $otherVessel->id,
        'rank_id' => $rank->id,
        'employee_id' => null,
        'planned_join_date' => '2027-03-01',
        'planned_leave_date' => '2027-04-01',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'plan',
            'employee_id' => $employee2->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2027-03-01',
            'planned_signoff_at' => '2027-04-01',
            'planning_assignment_id' => $incompatible->id,
        ])
        ->assertSessionHasErrors('planning_assignment_id');
});

test('cross-company and unauthorized planning slot linkage is rejected', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    ['company' => $otherCompany] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Auth Slot Vessel', $company);
    $foreignVessel = makeCrewMovementVessel('Foreign Slot Vessel', $otherCompany);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.planning.create',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $foreignSlot = CrewPlanningAssignment::query()->create([
        'company_id' => $otherCompany->id,
        'vessel_id' => $foreignVessel->id,
        'rank_id' => $rank->id,
        'employee_id' => null,
        'planned_join_date' => '2026-10-10',
        'planned_leave_date' => '2026-11-30',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'plan',
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-10-10',
            'planned_signoff_at' => '2026-11-30',
            'planning_assignment_id' => $foreignSlot->id,
        ])
        ->assertSessionHasErrors('planning_assignment_id');

    $localSlot = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => null,
        'planned_join_date' => '2026-10-10',
        'planned_leave_date' => '2026-11-30',
    ]);

    // Missing planning.view → link authorization fails
    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'plan',
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-10-10',
            'planned_signoff_at' => '2026-11-30',
            'planning_assignment_id' => $localSlot->id,
        ])
        ->assertSessionHasErrors('planning_assignment_id');
});

test('conflict payload matches frontend schema for overlapping planned assignments', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Schema Vessel', $company);

    $service = app(CrewMovementService::class);
    $existing = $service->createPlanned($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-10-10',
        'planned_signoff_at' => '2026-11-30',
    ], $user->id);

    $evaluator = new CrewAssignmentConflictEvaluator;
    $result = $evaluator->evaluate(new CrewAssignmentConflictContext(
        companyId: $company->id,
        employeeId: $employee->id,
        action: 'plan',
        plannedJoinAt: CarbonImmutable::parse('2026-10-20'),
        plannedSignoffAt: CarbonImmutable::parse('2026-12-15'),
        vesselId: $vessel->id,
        rankId: $rank->id,
    ));

    $payload = $result->toArray();

    expect($result->blocking)->toBeTrue()
        ->and($payload)->toHaveKeys([
            'has_conflict',
            'severity',
            'code',
            'message',
            'existing_assignment',
            'new_assignment',
            'affected_dates',
            'allowed_actions',
            'blocking',
        ])
        ->and($payload['existing_assignment'])->toHaveKeys([
            'id',
            'assignment_no',
            'status',
            'status_label',
            'vessel_id',
            'vessel_name',
            'rank_id',
            'rank_name',
            'planned_join_at',
            'planned_signoff_at',
            'current_phase_code',
            'current_phase_label',
        ])
        ->and($payload['new_assignment'])->toHaveKeys([
            'vessel_id',
            'vessel_name',
            'rank_id',
            'rank_name',
            'planned_join_at',
            'planned_signoff_at',
        ])
        ->and($payload['new_assignment'])->not->toHaveKey('start_date')
        ->and($payload['new_assignment'])->not->toHaveKey('end_date')
        ->and($payload['existing_assignment']['id'])->toBe($existing->id)
        ->and($payload['existing_assignment']['planned_join_at'])->toBe('2026-10-10')
        ->and($payload['existing_assignment']['planned_signoff_at'])->toBe('2026-11-30')
        ->and($payload['new_assignment']['planned_join_at'])->toBe('2026-10-20')
        ->and($payload['new_assignment']['planned_signoff_at'])->toBe('2026-12-15')
        ->and($payload['affected_dates']['overlap_start'])->not->toBeNull()
        ->and($payload['affected_dates']['overlap_end'])->not->toBeNull();
});

test('employee visibility scope hides inaccessible relief source without leaking details', function () {
    [
        'user' => $user,
        'company' => $company,
        'marineDept' => $marineDept,
        'officeEmployee' => $officeEmployee,
    ] = makeEmployeeVisibilityFixtures();

    $rank = Rank::query()->create([
        'name' => 'Relief Visibility Rank '.uniqid(),
        'is_active' => true,
    ]);
    $officeEmployee->update(['rank_id' => $rank->id]);
    $vessel = makeCrewMovementVessel('Relief Visibility Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.planning.create',
        'crew_operations.movements.perform',
    ]);
    restrictTestRoleEmployeeVisibility($user, $company, [$marineDept->id]);
    $user->update(['current_company_id' => $company->id]);

    $service = app(CrewMovementService::class);
    $hiddenActive = makeActiveOnVesselAssignment($company, $officeEmployee, $rank, $vessel);

    $reliefEmployee = Employee::factory()->create([
        'company_id' => $company->id,
        'department_id' => $marineDept->id,
        'rank_id' => $rank->id,
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'plan',
            'employee_id' => $reliefEmployee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-10-10',
            'planned_signoff_at' => '2026-11-30',
            'relieves_crew_assignment_id' => $hiddenActive->id,
        ])
        ->assertSessionHasErrors('employee_id');

    $sessionErrors = session('errors');
    $message = (string) ($sessionErrors?->first('employee_id') ?? '');

    expect($message)->toContain('could not be found')
        ->and($message)->not->toContain($officeEmployee->name)
        ->and($message)->not->toContain($hiddenActive->assignment_no);
});

test('planning handoff for a slot linked to a planned crew assignment redirects without throwing', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Planned Link Handoff Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.planning.view',
        'crew_operations.planning.create',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $planned = app(CrewMovementService::class)->createPlanned($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-10-10',
        'planned_signoff_at' => '2026-11-30',
    ], $user->id);

    $slot = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => null,
        'crew_assignment_id' => $planned->id,
        'planned_join_date' => '2026-10-10',
        'planned_leave_date' => '2026-11-30',
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create', [
            'planning_assignment_id' => $slot->id,
            'intent' => 'plan',
        ]))
        ->assertRedirect(route('organization.crew-assignments.show', $planned))
        ->assertSessionHas(
            'success',
            'This planning record is already linked to a planned crew assignment.',
        );
});

test('vacant planning slot date compatibility allows subsets and rejects out-of-window dates', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Date Boundary Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.planning.view',
        'crew_operations.planning.create',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $baseSlot = fn (): CrewPlanningAssignment => CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => null,
        'planned_join_date' => '2026-10-10',
        'planned_leave_date' => '2026-11-30',
    ]);

    // Exact match — allow
    $exact = $baseSlot();
    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'plan',
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-10-10',
            'planned_signoff_at' => '2026-11-30',
            'planning_assignment_id' => $exact->id,
        ])
        ->assertRedirect();
    expect($exact->fresh()->crew_assignment_id)->not->toBeNull();

    // Subset within slot — allow
    $subsetEmployee = Employee::factory()->create([
        'company_id' => $company->id,
        'rank_id' => $rank->id,
        'status' => 'active',
    ]);
    $subset = $baseSlot();
    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'plan',
            'employee_id' => $subsetEmployee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-10-15',
            'planned_signoff_at' => '2026-11-25',
            'planning_assignment_id' => $subset->id,
        ])
        ->assertRedirect();
    expect($subset->fresh()->crew_assignment_id)->not->toBeNull();

    // Equal join + overlong sign-off — reject
    $overlong = $baseSlot();
    $overlongEmployee = Employee::factory()->create([
        'company_id' => $company->id,
        'rank_id' => $rank->id,
        'status' => 'active',
    ]);
    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'plan',
            'employee_id' => $overlongEmployee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-10-10',
            'planned_signoff_at' => '2026-12-31',
            'planning_assignment_id' => $overlong->id,
        ])
        ->assertSessionHasErrors('planning_assignment_id');
    expect($overlong->fresh()->crew_assignment_id)->toBeNull();

    // Join before slot start — reject
    $before = $baseSlot();
    $beforeEmployee = Employee::factory()->create([
        'company_id' => $company->id,
        'rank_id' => $rank->id,
        'status' => 'active',
    ]);
    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'plan',
            'employee_id' => $beforeEmployee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-10-09',
            'planned_signoff_at' => '2026-11-25',
            'planning_assignment_id' => $before->id,
        ])
        ->assertSessionHasErrors('planning_assignment_id');

    // Entirely after slot end — reject
    $after = $baseSlot();
    $afterEmployee = Employee::factory()->create([
        'company_id' => $company->id,
        'rank_id' => $rank->id,
        'status' => 'active',
    ]);
    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'plan',
            'employee_id' => $afterEmployee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-12-01',
            'planned_signoff_at' => '2026-12-15',
            'planning_assignment_id' => $after->id,
        ])
        ->assertSessionHasErrors('planning_assignment_id');

    // Subset join with overlong sign-off — reject
    $subsetOverlong = $baseSlot();
    $subsetOverlongEmployee = Employee::factory()->create([
        'company_id' => $company->id,
        'rank_id' => $rank->id,
        'status' => 'active',
    ]);
    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'plan',
            'employee_id' => $subsetOverlongEmployee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-10-15',
            'planned_signoff_at' => '2026-12-31',
            'planning_assignment_id' => $subsetOverlong->id,
        ])
        ->assertSessionHasErrors('planning_assignment_id');
});

test('vacant planning create rejects employee_id and succeeds without it', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Vacant Planning Create Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.planning.create',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $beforeCount = CrewPlanningAssignment::query()->where('company_id', $company->id)->count();

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.store'), [
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'employee_id' => $employee->id,
            'planned_join_date' => '2026-10-10',
            'planned_leave_date' => '2026-11-30',
        ])
        ->assertSessionHasErrors('employee_id');

    $sessionErrors = session('errors');
    $message = (string) ($sessionErrors?->first('employee_id') ?? '');

    expect($message)->toContain('Crew Assignment → Save as Planned')
        ->and(CrewPlanningAssignment::query()->where('company_id', $company->id)->count())->toBe($beforeCount)
        ->and(CrewPlanningAssignment::query()->where('company_id', $company->id)->whereNotNull('employee_id')->count())->toBe(0);

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.store'), [
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'planned_join_date' => '2026-10-10',
            'planned_leave_date' => '2026-11-30',
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    $created = CrewPlanningAssignment::query()
        ->where('company_id', $company->id)
        ->where('vessel_id', $vessel->id)
        ->whereDate('planned_join_date', '2026-10-10')
        ->latest('id')
        ->first();

    expect($created)->not->toBeNull()
        ->and($created->employee_id)->toBeNull();
});
