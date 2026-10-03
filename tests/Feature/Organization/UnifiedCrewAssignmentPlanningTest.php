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
use App\Models\Position;
use App\Support\CrewMovements\CrewAssignmentConflictContext;
use App\Support\CrewMovements\CrewAssignmentConflictEvaluator;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\CrewMovements\CrewReliefReadinessResolver;
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
            'position_id' => $rank->id,
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

test('crafted submission_intent plan cannot create a crew assignment and planning reserves instead', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Planned Test Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.planning.create',
        'crew_operations.movements.perform',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'plan',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-10-10',
            'planned_signoff_at' => '2026-11-30',
        ])
        ->assertSessionHasErrors(['submission_intent']);

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2026-10-10',
        'planned_leave_date' => '2026-11-30',
    ]);

    expect($planning->crew_assignment_id)->toBeNull()
        ->and(EmployeeSeaService::query()->where('employee_id', $employee->id)->count())->toBe(0);

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
        ->and($result->allowedActions)->toContain('adjust_dates', 'cancel')
        ->and($result->allowedActions)->not->toContain('edit_existing_plan', 'cancel_existing_plan');
});

test('crew planning assignment appears in Planning Gantt query without duplicate records', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Gantt Test Vessel', $company);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2026-10-10',
        'planned_leave_date' => '2026-11-30',
    ]);

    $bars = CrewPlanningGanttQuery::bars($company->id, '2026-10-01', '2026-12-31');
    $bar = collect($bars)->firstWhere('id', $planning->id);

    expect($bar)->not->toBeNull()
        ->and($bar['employee_id'])->toBe($employee->id)
        ->and($bar['vessel_name'])->toBe($vessel->name)
        ->and($bar['planned_join_date'])->toBe('2026-10-10')
        ->and($bar['planned_leave_date'])->toBe('2026-11-30')
        ->and($bar['status'])->toBe('planned')
        ->and($bar['is_assigned'])->toBeFalse()
        ->and($bar['crew_assignment_id'])->toBeNull();
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
            'position_id' => $rank->id,
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

test('draft assignment can transition to active on the SAME CrewAssignment record', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Transition Vessel', $company);

    $service = app(CrewMovementService::class);
    $draft = $service->createDraft($company->id, $employee->id, [
        'position_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-10-10',
        'planned_signoff_at' => '2026-11-30',
    ], $user->id);

    $originalId = $draft->id;
    $originalNo = $draft->assignment_no;

    expect($draft->status)->toBe(CrewAssignmentStatus::Draft)
        ->and($draft->currentPhase?->status)->toBe(CrewPhaseStatus::Planned);

    $active = $service->perform($company->id, $draft->id, CrewMovementAction::ApproveMobilisation, [
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

test('draft to active transition reruns conflict check and blocks if circumstances changed', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel1 = makeCrewMovementVessel('Vessel 1', $company);
    $vessel2 = makeCrewMovementVessel('Vessel 2', $company);

    $service = app(CrewMovementService::class);
    $draft = $service->createDraft($company->id, $employee->id, [
        'position_id' => $rank->id,
        'vessel_id' => $vessel1->id,
        'planned_join_at' => '2026-10-10',
        'planned_signoff_at' => '2026-11-30',
    ], $user->id);

    // Concurrent Active that ends before the draft window — allowed at start time,
    // but still blocks Draft → Active because an Active assignment already exists.
    $service->startAssignment($company->id, $employee->id, [
        'position_id' => $rank->id,
        'vessel_id' => $vessel2->id,
        'planned_signoff_at' => '2026-09-30',
        'stage_started_at' => '2026-09-15 08:00:00',
    ], $user->id);

    expect(fn () => $service->perform($company->id, $draft->id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-09-20 10:00:00',
    ], $user->id))->toThrow(ValidationException::class);
});

test('planned vs active overlap is detected with actionable context', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Active Conflict Vessel', $company);

    $service = app(CrewMovementService::class);
    $active = $service->startAssignment($company->id, $employee->id, [
        'position_id' => $rank->id,
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
        'position_id' => $rank->id,
        'vessel_id' => $vessel1->id,
        'stage_started_at' => '2026-09-15 08:00:00',
    ], $user->id);

    expect(fn () => $service->startAssignment($company->id, $employee->id, [
        'position_id' => $rank->id,
        'vessel_id' => $vessel2->id,
        'stage_started_at' => '2026-09-15 09:00:00',
    ], $user->id))->toThrow(CrewMovementException::class, 'already has an active assignment');
});

test('deleting a crew planning reservation releases employee availability', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Cancel Plan Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.planning.create',
        'crew_operations.planning.delete',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2026-10-10',
        'planned_leave_date' => '2026-11-30',
    ]);

    $evaluator = new CrewAssignmentConflictEvaluator;
    $context = new CrewAssignmentConflictContext(
        companyId: $company->id,
        employeeId: $employee->id,
        action: 'plan',
        plannedJoinAt: CarbonImmutable::parse('2026-10-20'),
        plannedSignoffAt: CarbonImmutable::parse('2026-11-20'),
    );

    expect($evaluator->evaluate($context)->blocking)->toBeTrue();

    $this->actingAs($user)
        ->delete(route('organization.crew-planning.assignments.destroy', $planning))
        ->assertRedirect();

    expect(CrewPlanningAssignment::query()->whereKey($planning->id)->exists())->toBeFalse();
    expect($evaluator->evaluate($context)->blocking)->toBeFalse();
});

test('invalid date sequence is rejected on draft create', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Date Check Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'draft',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-11-30',
            'planned_signoff_at' => '2026-10-10',
        ])
        ->assertSessionHasErrors('planned_signoff_at');

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'draft',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_arrival_at' => '2026-10-15',
            'planned_join_at' => '2026-10-10',
            'planned_signoff_at' => '2026-11-30',
        ])
        ->assertSessionHasErrors('planned_arrival_at');
});

test('submission_intent plan is blocked even when planning create permission is granted', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Perm Test Vessel', $company);

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
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-10-10',
            'planned_signoff_at' => '2026-11-30',
        ])
        ->assertSessionHasErrors(['submission_intent']);

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('tenant isolation rejects cross-company employee and vessel on start creation', function () {
    ['user' => $user, 'company' => $company, 'employee' => $localEmployee] = makeCrewAssignmentFixtures();
    ['company' => $otherCompany, 'employee' => $foreignEmployee] = makeCrewAssignmentFixtures();
    $foreignVessel = makeCrewMovementVessel('Foreign Vessel', $otherCompany);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.movements.perform',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $foreignEmployee->id,
            'planned_join_at' => '2026-10-10',
            'planned_signoff_at' => '2026-11-30',
        ])
        ->assertSessionHasErrors('employee_id');

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $localEmployee->id,
            'vessel_id' => $foreignVessel->id,
            'planned_join_at' => '2026-10-10',
            'planned_signoff_at' => '2026-11-30',
        ])
        ->assertSessionHasErrors('vessel_id');
});

test('draft save creates no actual movement events or sea service', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('No Sea Service Vessel', $company);

    $service = app(CrewMovementService::class);
    $assignment = $service->createDraft($company->id, $employee->id, [
        'position_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-10-10',
        'planned_signoff_at' => '2026-11-30',
    ], $user->id);

    expect($assignment->status)->toBe(CrewAssignmentStatus::Draft)
        ->and($assignment->phases)->toHaveCount(1)
        ->and($assignment->phases->first()->status)->toBe(CrewPhaseStatus::Planned)
        ->and($assignment->phases->first()->actual_start_at)->toBeNull()
        ->and($assignment->phases->first()->actual_end_at)->toBeNull();

    expect(EmployeeSeaService::query()->where('employee_id', $employee->id)->count())->toBe(0);

    expect($assignment->planned_signoff_at?->toDateString())->toBe('2026-11-30')
        ->and($assignment->currentPhase?->actual_end_at)->toBeNull();
});

test('named planning start mobilisation keeps forecasts without planned_assignment activity', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Audit Test Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.assignments.create',
        'crew_operations.movements.perform',
        'crew_operations.assignments.view',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2026-10-10',
        'planned_leave_date' => '2026-11-30',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.start-mobilisation', $planning))
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->latest('id')->first();

    expect($assignment)->not->toBeNull()
        ->and($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->planned_join_at?->toDateString())->toBe('2026-10-10')
        ->and($assignment->planned_signoff_at?->toDateString())->toBe('2026-11-30')
        ->and(Activity::query()
            ->where('subject_type', CrewAssignment::class)
            ->where('subject_id', $assignment->id)
            ->where('description', 'like', 'planned_assignment_%')
            ->count())->toBe(0);
});

test('editing planning dates reruns conflict detection and blocks overlapping dates', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel1 = makeCrewMovementVessel('Edit Date Vessel 1', $company);
    $vessel2 = makeCrewMovementVessel('Edit Date Vessel 2', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.planning.create',
        'crew_operations.planning.update',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $planA = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel1->id,
        'position_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2026-10-10',
        'planned_leave_date' => '2026-10-31',
    ]);

    $planB = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel2->id,
        'position_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2026-12-01',
        'planned_leave_date' => '2026-12-31',
    ]);

    $this->actingAs($user)
        ->put(route('organization.crew-planning.assignments.update', $planB), [
            'vessel_id' => $vessel2->id,
            'position_id' => $rank->id,
            'employee_id' => $employee->id,
            'planned_join_date' => '2026-10-15',
            'planned_leave_date' => '2026-11-15',
        ])
        ->assertSessionHasErrors('employee_id');

    expect($planA->fresh()->planned_join_date?->toDateString())->toBe('2026-10-10')
        ->and($planB->fresh()->planned_join_date?->toDateString())->toBe('2026-12-01');
});

test('concurrent conflict evaluator locks and prevents conflicting reservations', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Concurrent Lock Vessel', $company);

    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2026-10-10',
        'planned_leave_date' => '2026-11-30',
    ]);

    $evaluator = new CrewAssignmentConflictEvaluator;
    $context = new CrewAssignmentConflictContext(
        companyId: $company->id,
        employeeId: $employee->id,
        action: 'plan',
        plannedJoinAt: CarbonImmutable::parse('2026-10-15'),
        plannedSignoffAt: CarbonImmutable::parse('2026-11-15'),
    );

    expect(fn () => DB::transaction(fn () => $evaluator->assertNoBlockingConflicts($context, withLock: true)))
        ->toThrow(ValidationException::class);
});

test('planning-only user cannot open assignment create or craft plan intent', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Plan Only Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.planning.create',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create', ['intent' => 'plan']))
        ->assertForbidden();

    // Invalid intent falls back to Draft for authorize(); planning-only still cannot create.
    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'plan',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-10-10',
            'planned_signoff_at' => '2026-11-30',
        ])
        ->assertForbidden();

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('create intent plan is ignored and does not create a planned assignment', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.planning.view',
        'crew_operations.planning.create',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create', ['intent' => 'plan']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew/create')
            ->where('intent', null)
        );
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
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-10-10',
            'planned_signoff_at' => '2026-11-30',
        ])
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-10-10',
            'planned_signoff_at' => '2026-11-30',
        ])
        ->assertForbidden();

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('draft to active rechecks full planned date range against overlapping planning reservation', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel1 = makeCrewMovementVessel('Range Vessel A', $company);
    $vessel2 = makeCrewMovementVessel('Range Vessel B', $company);

    $service = app(CrewMovementService::class);

    $draft = $service->createDraft($company->id, $employee->id, [
        'position_id' => $rank->id,
        'vessel_id' => $vessel1->id,
        'planned_join_at' => '2026-10-01',
        'planned_signoff_at' => '2026-11-30',
    ], $user->id);

    // Overlapping planning reservation that blocks Draft → Active on full range recheck.
    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel2->id,
        'position_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2026-11-15',
        'planned_leave_date' => '2026-12-31',
    ]);

    expect(fn () => $service->perform($company->id, $draft->id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-09-20 10:00:00',
    ], $user->id))->toThrow(ValidationException::class);

    expect($draft->fresh()->status)->toBe(CrewAssignmentStatus::Draft)
        ->and($draft->fresh()->id)->toBe($draft->id)
        ->and($draft->fresh()->assignment_no)->toBe($draft->assignment_no);
});

test('same draft assignment can start successfully without conflicting with itself', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Self Start Vessel', $company);

    $service = app(CrewMovementService::class);
    $draft = $service->createDraft($company->id, $employee->id, [
        'position_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-10-01',
        'planned_signoff_at' => '2026-11-30',
    ], $user->id);

    $active = $service->perform($company->id, $draft->id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-09-20 10:00:00',
    ], $user->id);

    expect($active->id)->toBe($draft->id)
        ->and($active->assignment_no)->toBe($draft->assignment_no)
        ->and($active->status)->toBe(CrewAssignmentStatus::Active);
});

test('vacant planning slot can be linked on start store and rejects already-linked or incompatible slots', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Slot Link Vessel', $company);
    $otherVessel = makeCrewMovementVessel('Other Slot Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.movements.perform',
        'crew_operations.planning.view',
        'crew_operations.planning.create',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $vacant = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => null,
        'planned_join_date' => '2026-10-10',
        'planned_leave_date' => '2026-11-30',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-10-10',
            'planned_signoff_at' => '2026-11-30',
            'planning_assignment_id' => $vacant->id,
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->latest('id')->first();
    expect($vacant->fresh()->crew_assignment_id)->toBe($assignment->id)
        ->and($assignment->status)->toBe(CrewAssignmentStatus::Active);

    $employee2 = Employee::factory()->create(['company_id' => $company->id, 'position_id' => $rank->id, 'status' => 'active']);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee2->id,
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2027-01-01',
            'planned_signoff_at' => '2027-02-01',
            'planning_assignment_id' => $vacant->id,
        ])
        ->assertSessionHasErrors('planning_assignment_id');

    $incompatible = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $otherVessel->id,
        'position_id' => $rank->id,
        'employee_id' => null,
        'planned_join_date' => '2027-03-01',
        'planned_leave_date' => '2027-04-01',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee2->id,
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2027-03-01',
            'planned_signoff_at' => '2027-04-01',
            'planning_assignment_id' => $incompatible->id,
        ])
        ->assertRedirect();

    $linked = CrewAssignment::query()->where('company_id', $company->id)->where('employee_id', $employee2->id)->latest('id')->first();
    expect($linked)->not->toBeNull()
        ->and($linked->vessel_id)->toBe($otherVessel->id)
        ->and($incompatible->fresh()->crew_assignment_id)->toBe($linked->id);
});

test('cross-company and unauthorized planning slot linkage is rejected', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    ['company' => $otherCompany] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Auth Slot Vessel', $company);
    $foreignVessel = makeCrewMovementVessel('Foreign Slot Vessel', $otherCompany);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.movements.perform',
        'crew_operations.planning.create',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $foreignSlot = CrewPlanningAssignment::query()->create([
        'company_id' => $otherCompany->id,
        'vessel_id' => $foreignVessel->id,
        'position_id' => $rank->id,
        'employee_id' => null,
        'planned_join_date' => '2026-10-10',
        'planned_leave_date' => '2026-11-30',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-10-10',
            'planned_signoff_at' => '2026-11-30',
            'planning_assignment_id' => $foreignSlot->id,
        ])
        ->assertSessionHasErrors('planning_assignment_id');

    $localSlot = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => null,
        'planned_join_date' => '2026-10-10',
        'planned_leave_date' => '2026-11-30',
    ]);

    // Missing planning.view → link authorization fails
    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-10-10',
            'planned_signoff_at' => '2026-11-30',
            'planning_assignment_id' => $localSlot->id,
        ])
        ->assertSessionHasErrors('planning_assignment_id');
});

test('conflict payload matches frontend schema for overlapping planning reservations', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Schema Vessel', $company);

    $existing = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2026-10-10',
        'planned_leave_date' => '2026-11-30',
    ]);

    $evaluator = new CrewAssignmentConflictEvaluator;
    $result = $evaluator->evaluate(new CrewAssignmentConflictContext(
        companyId: $company->id,
        employeeId: $employee->id,
        action: 'plan',
        plannedJoinAt: CarbonImmutable::parse('2026-10-20'),
        plannedSignoffAt: CarbonImmutable::parse('2026-12-15'),
        vesselId: $vessel->id,
        positionId: $rank->id,
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
            'position_id',
            'position_name',
            'planned_join_at',
            'planned_signoff_at',
            'current_phase_code',
            'current_phase_label',
        ])
        ->and($payload['new_assignment'])->toHaveKeys([
            'vessel_id',
            'vessel_name',
            'position_id',
            'position_name',
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
        ->and($payload['affected_dates']['overlap_end'])->not->toBeNull()
        ->and($payload['allowed_actions'])->toContain('adjust_dates', 'cancel')
        ->and($payload['allowed_actions'])->not->toContain('edit_existing_plan', 'cancel_existing_plan');
});

test('employee visibility scope hides inaccessible relief source without leaking details', function () {
    [
        'user' => $user,
        'company' => $company,
        'marineDept' => $marineDept,
        'officeEmployee' => $officeEmployee,
    ] = makeEmployeeVisibilityFixtures();

    $rank = Position::query()->create([
        'company_id' => $company->id,
        'title' => 'Relief Visibility Rank '.uniqid(),
        'status' => 'active', 'is_crew_position' => true,
    ]);
    $officeEmployee->update(['position_id' => $rank->id]);
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
        'position_id' => $rank->id,
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'draft',
            'employee_id' => $reliefEmployee->id,
            'position_id' => $rank->id,
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

test('planning handoff for a slot linked to a draft crew assignment redirects without throwing', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Draft Link Handoff Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.movements.perform',
        'crew_operations.planning.view',
        'crew_operations.planning.create',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $draft = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'position_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-10-10',
        'planned_signoff_at' => '2026-11-30',
    ], $user->id);

    $slot = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => null,
        'crew_assignment_id' => $draft->id,
        'planned_join_date' => '2026-10-10',
        'planned_leave_date' => '2026-11-30',
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create', [
            'planning_assignment_id' => $slot->id,
        ]))
        ->assertRedirect(route('organization.crew-assignments.show', $draft))
        ->assertSessionHas(
            'success',
            'This planning record is linked to a draft crew assignment. Continue mobilisation from Crew Assignments.',
        );
});

test('vacant planning slot date compatibility allows subsets and rejects out-of-window dates', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Date Boundary Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.movements.perform',
        'crew_operations.planning.view',
        'crew_operations.planning.create',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $baseSlot = fn (): CrewPlanningAssignment => CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => null,
        'planned_join_date' => '2026-10-10',
        'planned_leave_date' => '2026-11-30',
    ]);

    // Exact match — allow
    $exact = $baseSlot();
    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
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
        'position_id' => $rank->id,
        'status' => 'active',
    ]);
    $subset = $baseSlot();
    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $subsetEmployee->id,
            'position_id' => $rank->id,
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
        'position_id' => $rank->id,
        'status' => 'active',
    ]);
    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $overlongEmployee->id,
            'position_id' => $rank->id,
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
        'position_id' => $rank->id,
        'status' => 'active',
    ]);
    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $beforeEmployee->id,
            'position_id' => $rank->id,
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
        'position_id' => $rank->id,
        'status' => 'active',
    ]);
    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $afterEmployee->id,
            'position_id' => $rank->id,
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
        'position_id' => $rank->id,
        'status' => 'active',
    ]);
    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $subsetOverlongEmployee->id,
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-10-15',
            'planned_signoff_at' => '2026-12-31',
            'planning_assignment_id' => $subsetOverlong->id,
        ])
        ->assertSessionHasErrors('planning_assignment_id');
});

test('crew planning store supports both named and vacant plans with optional planned arrival date', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Named Planning Create Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.planning.create',
    ]);
    $user->update(['current_company_id' => $company->id]);

    // 1. Date ordering validation: arrival after join fails
    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.store'), [
            'vessel_id' => $vessel->id,
            'position_id' => $rank->id,
            'employee_id' => $employee->id,
            'planned_arrival_date' => '2026-10-15',
            'planned_join_date' => '2026-10-10',
            'planned_leave_date' => '2026-11-30',
        ])
        ->assertSessionHasErrors('planned_arrival_date');

    // 2. Date ordering validation: join after leave fails
    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.store'), [
            'vessel_id' => $vessel->id,
            'position_id' => $rank->id,
            'employee_id' => $employee->id,
            'planned_join_date' => '2026-12-01',
            'planned_leave_date' => '2026-11-30',
        ])
        ->assertSessionHasErrors('planned_leave_date');

    // 3. Named plan succeeds with valid arrival <= join <= leave
    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.store'), [
            'vessel_id' => $vessel->id,
            'position_id' => $rank->id,
            'employee_id' => $employee->id,
            'planned_arrival_date' => '2026-10-08',
            'planned_join_date' => '2026-10-10',
            'planned_leave_date' => '2026-11-30',
            'notes' => 'Named plan test',
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    $namedPlan = CrewPlanningAssignment::query()
        ->where('company_id', $company->id)
        ->where('vessel_id', $vessel->id)
        ->where('employee_id', $employee->id)
        ->first();

    expect($namedPlan)->not->toBeNull()
        ->and((int) $namedPlan->employee_id)->toBe($employee->id)
        ->and($namedPlan->planned_arrival_date?->format('Y-m-d'))->toBe('2026-10-08')
        ->and($namedPlan->planned_join_date->format('Y-m-d'))->toBe('2026-10-10')
        ->and($namedPlan->planned_leave_date?->format('Y-m-d'))->toBe('2026-11-30')
        ->and($namedPlan->notes)->toBe('Named plan test');

    // 4. Overlap conflict: same employee cannot have overlapping planning assignment
    $otherVessel = makeCrewMovementVessel('Other Conflict Vessel', $company);
    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.store'), [
            'vessel_id' => $otherVessel->id,
            'position_id' => $rank->id,
            'employee_id' => $employee->id,
            'planned_join_date' => '2026-10-20',
            'planned_leave_date' => '2026-11-15',
        ])
        ->assertSessionHasErrors('employee_id');

    // 5. Vacant plan succeeds without employee_id
    $this->actingAs($user)
        ->post(route('organization.crew-planning.assignments.store'), [
            'vessel_id' => $vessel->id,
            'position_id' => $rank->id,
            'planned_join_date' => '2026-12-01',
            'planned_leave_date' => '2027-01-15',
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    $vacantPlan = CrewPlanningAssignment::query()
        ->where('company_id', $company->id)
        ->where('vessel_id', $vessel->id)
        ->whereNull('employee_id')
        ->whereDate('planned_join_date', '2026-12-01')
        ->first();

    expect($vacantPlan)->not->toBeNull()
        ->and($vacantPlan->employee_id)->toBeNull();
});

test('crew planning update supports modifying named employee, dates, arrival date, and clearing to vacant', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Planning Update Vessel', $company);
    $secondEmployee = Employee::factory()
        ->forCompany($company)
        ->create([
            'position_id' => $rank->id,
            'status' => 'active',
        ]);

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.planning.create',
        'crew_operations.planning.update',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $plan = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_arrival_date' => '2026-10-01',
        'planned_join_date' => '2026-10-05',
        'planned_leave_date' => '2026-11-05',
    ]);

    // Updating self with adjusted dates does not cause false self-conflict
    $this->actingAs($user)
        ->put(route('organization.crew-planning.assignments.update', $plan), [
            'vessel_id' => $vessel->id,
            'position_id' => $rank->id,
            'employee_id' => $employee->id,
            'planned_arrival_date' => '2026-10-03',
            'planned_join_date' => '2026-10-06',
            'planned_leave_date' => '2026-11-10',
            'notes' => 'Updated self notes',
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    $plan->refresh();
    expect($plan->planned_arrival_date?->format('Y-m-d'))->toBe('2026-10-03')
        ->and($plan->planned_join_date->format('Y-m-d'))->toBe('2026-10-06')
        ->and($plan->notes)->toBe('Updated self notes');

    // Updating to clear employee_id (transition to vacant plan)
    $this->actingAs($user)
        ->put(route('organization.crew-planning.assignments.update', $plan), [
            'vessel_id' => $vessel->id,
            'position_id' => $rank->id,
            'employee_id' => null,
            'planned_join_date' => '2026-10-06',
            'planned_leave_date' => '2026-11-10',
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    $plan->refresh();
    expect($plan->employee_id)->toBeNull();

    // Updating to assign second employee
    $this->actingAs($user)
        ->put(route('organization.crew-planning.assignments.update', $plan), [
            'vessel_id' => $vessel->id,
            'position_id' => $rank->id,
            'employee_id' => $secondEmployee->id,
            'planned_join_date' => '2026-10-06',
            'planned_leave_date' => '2026-11-10',
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    $plan->refresh();
    expect((int) $plan->employee_id)->toBe($secondEmployee->id);
});

test('planning update cannot clear expected join or leave via blank submission', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Clear Join Vessel', $company);
    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.planning.create',
        'crew_operations.planning.update',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $plan = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2026-10-10',
        'planned_leave_date' => '2026-11-30',
    ]);

    $this->actingAs($user)
        ->put(route('organization.crew-planning.assignments.update', $plan), [
            'vessel_id' => $vessel->id,
            'position_id' => $rank->id,
            'employee_id' => $employee->id,
            'planned_join_date' => '',
            'planned_leave_date' => '2026-11-30',
        ])
        ->assertSessionHasErrors('planned_join_date');

    expect($plan->fresh()->planned_join_date?->toDateString())->toBe('2026-10-10');

    $this->actingAs($user)
        ->put(route('organization.crew-planning.assignments.update', $plan), [
            'vessel_id' => $vessel->id,
            'position_id' => $rank->id,
            'employee_id' => $employee->id,
            'planned_join_date' => '2026-10-10',
            'planned_leave_date' => '',
        ])
        ->assertSessionHasErrors('planned_leave_date');

    expect($plan->fresh()->planned_leave_date?->toDateString())->toBe('2026-11-30');
});

test('planning update conflict uses submitted effective dates not stale persisted values', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Effective Dates Vessel', $company);
    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.planning.create',
        'crew_operations.planning.update',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $existing = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2026-10-01',
        'planned_leave_date' => '2026-10-15',
    ]);

    $editable = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2026-11-01',
        'planned_leave_date' => '2026-11-30',
    ]);

    $this->actingAs($user)
        ->put(route('organization.crew-planning.assignments.update', $editable), [
            'vessel_id' => $vessel->id,
            'position_id' => $rank->id,
            'employee_id' => $employee->id,
            'planned_join_date' => '2026-10-05',
            'planned_leave_date' => '2026-10-20',
        ])
        ->assertSessionHasErrors(['employee_id']);

    expect($editable->fresh()->planned_join_date?->toDateString())->toBe('2026-11-01')
        ->and($existing->fresh()->planned_join_date?->toDateString())->toBe('2026-10-01');
});

test('submission_intent plan is blocked without creating an assignment', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.create',
        'crew_operations.assignments.create',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'plan',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'planned_join_at' => '2026-10-10',
            'planned_signoff_at' => '2026-11-30',
        ])
        ->assertSessionHasErrors(['submission_intent']);

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('planning-only user can manage planning records but cannot access crew assignments', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Planning Only Vessel', $company);
    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.planning.create',
        'crew_operations.planning.update',
        'crew_operations.planning.delete',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2026-10-10',
        'planned_leave_date' => '2026-11-30',
    ]);

    $this->actingAs($user)
        ->put(route('organization.crew-planning.assignments.update', $planning), [
            'vessel_id' => $vessel->id,
            'position_id' => $rank->id,
            'employee_id' => $employee->id,
            'planned_join_date' => '2026-10-12',
            'planned_leave_date' => '2026-11-30',
            'notes' => 'Planning edit',
        ])
        ->assertRedirect();

    expect($planning->fresh()->notes)->toBe('Planning edit')
        ->and($planning->fresh()->planned_join_date?->toDateString())->toBe('2026-10-12');

    $service = app(CrewMovementService::class);
    $draft = $service->createDraft($company->id, $employee->id, [
        'position_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2027-01-01',
        'planned_signoff_at' => '2027-02-01',
    ], $user->id);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.show', $draft))
        ->assertForbidden();

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.edit', $draft))
        ->assertForbidden();

    $activeEmployee = Employee::factory()->forCompany($company)->create([
        'position_id' => $rank->id,
        'status' => 'active',
    ]);
    $active = $service->startAssignment($company->id, $activeEmployee->id, [
        'position_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'stage_started_at' => '2026-09-15 08:00:00',
    ], $user->id);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.show', $active))
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $draft), [
            'action' => CrewMovementAction::ApproveMobilisation->value,
            'occurred_at' => '2026-10-01 09:00:00',
        ])
        ->assertForbidden();
});

test('draft relief does not block a subsequent planning relief', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Relief Source Vessel', $company);
    $service = app(CrewMovementService::class);

    $source = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel, [
        'planned_signoff_at' => '2026-12-01',
    ]);

    $reliefEmployee = Employee::factory()->forCompany($company)->create([
        'position_id' => $rank->id,
        'status' => 'active',
    ]);

    $draftRelief = $service->createDraft($company->id, $reliefEmployee->id, [
        'position_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-11-20',
        'planned_signoff_at' => '2027-02-28',
        'relieves_crew_assignment_id' => $source->id,
    ], $user->id);

    $resolver = new CrewReliefReadinessResolver;
    expect($resolver->hasActiveOperationalRelief($company->id, $source->id))->toBeFalse()
        ->and($resolver->forSourceAssignment($source)->status->value)->toBe('no_relief');

    $planningRelief = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => $reliefEmployee->id,
        'relieves_crew_assignment_id' => $source->id,
        'planned_join_date' => '2026-11-20',
        'planned_leave_date' => '2027-02-28',
    ]);

    expect($planningRelief->crew_assignment_id)->toBeNull()
        ->and($resolver->hasActiveOperationalRelief($company->id, $source->id))->toBeTrue()
        ->and($draftRelief->fresh()->status)->toBe(CrewAssignmentStatus::Draft);
});

test('crafted vacant slot handoff cannot clear vessel or rank', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Slot Handoff Vessel', $company);
    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.planning.create',
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.movements.perform',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $slot = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => null,
        'planned_join_date' => '2026-10-01',
        'planned_leave_date' => '2026-11-30',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => null,
            'vessel_id' => null,
            'planned_join_at' => '2026-10-10',
            'planned_signoff_at' => '2026-11-20',
            'planning_assignment_id' => $slot->id,
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->latest('id')->first();

    expect($assignment)->not->toBeNull()
        ->and($assignment->vessel_id)->toBe($vessel->id)
        ->and($assignment->position_id)->toBe($rank->id)
        ->and($slot->fresh()->crew_assignment_id)->toBe($assignment->id);
});

test('vacant planning handoff opens for create-only users without movements perform', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Create Only Handoff Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.assignments.create',
        'crew_operations.assignments.view',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $vacant = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => null,
        'planned_join_date' => '2026-10-10',
        'planned_leave_date' => '2026-11-30',
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create', [
            'planning_assignment_id' => $vacant->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew/create')
            ->where('planning_context.planning_assignment_id', $vacant->id)
            ->where('planning_context.employee_id', null)
            ->where('can.create', true)
            ->where('can.start', false)
        );
});

test('vacant planning create-only user can save draft and link the slot', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Draft Link Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.assignments.create',
        'crew_operations.assignments.view',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $vacant = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => null,
        'planned_join_date' => '2026-10-10',
        'planned_leave_date' => '2026-11-30',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'draft',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-10-10',
            'planned_signoff_at' => '2026-11-30',
            'planning_assignment_id' => $vacant->id,
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->latest('id')->first();

    expect($assignment)->not->toBeNull()
        ->and($assignment->status)->toBe(CrewAssignmentStatus::Draft)
        ->and($vacant->fresh()->crew_assignment_id)->toBe($assignment->id);
});

test('vacant planning start remains forbidden without movements perform', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Start Forbidden Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.assignments.create',
        'crew_operations.assignments.view',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $vacant = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => null,
        'planned_join_date' => '2026-10-10',
        'planned_leave_date' => '2026-11-30',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-10-10',
            'planned_signoff_at' => '2026-11-30',
            'planning_assignment_id' => $vacant->id,
        ])
        ->assertForbidden();

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0)
        ->and($vacant->fresh()->crew_assignment_id)->toBeNull();
});

test('named planning cannot open vacant-slot create handoff', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Named Handoff Block Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.assignments.create',
        'crew_operations.assignments.view',
        'crew_operations.movements.perform',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $named = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2026-10-10',
        'planned_leave_date' => '2026-11-30',
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create', [
            'planning_assignment_id' => $named->id,
        ]))
        ->assertRedirect(route('organization.crew-planning.index'))
        ->assertSessionHas('error', 'Named planning records must be started using Start Mobilisation.');
});
