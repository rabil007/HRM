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
use App\Support\CrewMovements\CrewAssignmentConflictContext;
use App\Support\CrewMovements\CrewAssignmentConflictEvaluator;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\CrewPlanning\CrewPlanningGanttQuery;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
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

    // Simulate an active assignment being created concurrently
    $service->startAssignment($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel2->id,
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
