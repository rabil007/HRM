<?php

use App\Enums\CrewAccommodationStatus;
use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewAssignmentSubmissionIntent;
use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewTimesheetPayCategory;
use App\Exceptions\CrewMovementException;
use App\Models\CrewAccommodationStay;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewPlanningAssignment;
use App\Models\EmployeeSeaService;
use App\Models\Hotel;
use App\Models\RoomType;
use App\Support\CrewMovements\CrewArrivalResolver;
use App\Support\CrewMovements\CrewAssignmentPresenter;
use App\Support\CrewMovements\CrewAssignmentStatusResolver;
use App\Support\CrewMovements\CrewDateProvenance;
use App\Support\CrewMovements\CrewMovementAvailableActions;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\Payroll\CrewTimeline\CrewPhasePayCategoryResolver;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

function startAssignmentPermissions(array $extra = []): array
{
    return array_values(array_unique(array_merge([
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.movements.perform',
    ], $extra)));
}

function actingCrewStarter(array $permissions = []): array
{
    $fixtures = makeCrewAssignmentFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], startAssignmentPermissions($permissions));
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);

    return $fixtures;
}

test('start assignment requires create permission', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.movements.perform',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'stage_started_at' => '2026-09-15T10:30',
        ])
        ->assertForbidden();
});

test('start assignment also requires movement permission', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'current_stage' => 'p0',
            'stage_started_at' => '2026-09-15T10:30',
        ])
        ->assertForbidden();
});

test('create-only user can still save as draft', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, ['crew_operations.assignments.create']);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'draft',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
        ])
        ->assertRedirect(route('dashboard'));

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->first();

    expect($assignment)->not->toBeNull()
        ->and($assignment->status)->toBe(CrewAssignmentStatus::Draft)
        ->and($assignment->started_at)->toBeNull()
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreArrival)
        ->and($assignment->currentPhase?->status)->toBe(CrewPhaseStatus::Planned)
        ->and($assignment->currentPhase?->actual_start_at)->toBeNull();
});

test('omitted current stage starts active travel in with matching timestamps', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();
    $vessel = makeCrewMovementVessel('Start P1 Vessel', $company);
    Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-09-20',
            'remarks' => 'Started from ops desk',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->first();

    expect($assignment)->not->toBeNull()
        ->and($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->source)->toBe('manual')
        ->and($assignment->planned_join_at?->toDateString())->toBe('2026-09-20')
        ->and($assignment->planned_signoff_at)->toBeNull()
        ->and($assignment->planned_travel_at)->toBeNull()
        ->and($assignment->tour_of_duty_days)->toBeNull()
        ->and($assignment->started_at?->timezone($company->timezone)->format('Y-m-d H:i'))->toBe('2026-09-15 12:00')
        ->and($assignment->phases)->toHaveCount(1)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreArrival)
        ->and($assignment->currentPhase?->status)->toBe(CrewPhaseStatus::Active)
        ->and($assignment->currentPhase?->sequence)->toBe(1)
        ->and($assignment->currentPhase?->actual_start_at?->equalTo($assignment->started_at))->toBeTrue()
        ->and($assignment->currentPhase?->actual_end_at)->toBeNull()
        ->and(EmployeeSeaService::query()->where('employee_id', $employee->id)->count())->toBe(0)
        ->and(CrewPlanningAssignment::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(0);
});

test('explicit pre-mobilisation start assignment creates active p0', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();
    Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'current_stage' => 'p0',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->first();

    expect($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreArrival)
        ->and($assignment->currentPhase?->status)->toBe(CrewPhaseStatus::Active)
        ->and($assignment->started_at?->timezone($company->timezone)->format('Y-m-d H:i'))->toBe('2026-09-15 12:00');
});

test('explicit start without stage_started_at uses identical company-local timestamps and defaults to p0', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();
    Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'current_stage' => 'p1',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->first();

    expect($assignment)->not->toBeNull()
        ->and($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->started_at?->timezone($company->timezone)->format('Y-m-d H:i'))->toBe('2026-09-15 12:00')
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreArrival)
        ->and($assignment->currentPhase?->actual_start_at?->equalTo($assignment->started_at))->toBeTrue();
});

test('save as draft does not require stage started at and stays planned p0', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'draft',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'planned_join_at' => '2026-09-20',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->first();

    expect($assignment->status)->toBe(CrewAssignmentStatus::Draft)
        ->and($assignment->started_at)->toBeNull()
        ->and($assignment->currentPhase?->status)->toBe(CrewPhaseStatus::Planned)
        ->and($assignment->currentPhase?->actual_start_at)->toBeNull();
});

test('omitted assignment start date uses company-local now', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = actingCrewStarter();
    expect($company->timezone)->toBe('Asia/Dubai');
    Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', 'Asia/Dubai'));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->first();

    expect($assignment->started_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-09-15 08:00:00')
        ->and($assignment->currentPhase?->actual_start_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-09-15 08:00:00')
        ->and($assignment->currentPhase?->actual_start_at?->equalTo($assignment->started_at))->toBeTrue()
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreArrival);
});

test('crafted stage started at cannot backdate a normal web start assignment', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = actingCrewStarter();
    expect($company->timezone)->toBe('Asia/Dubai');
    Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', 'Asia/Dubai'));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'stage_started_at' => '2026-09-14T10:30',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->first();

    expect($assignment->started_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-09-15 08:00:00')
        ->and($assignment->currentPhase?->actual_start_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-09-15 08:00:00')
        ->and($assignment->currentPhase?->actual_start_at?->equalTo($assignment->started_at))->toBeTrue();
});

test('crafted future stage started at cannot affect a normal web start assignment', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = actingCrewStarter();
    Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'current_stage' => 'p0',
            'stage_started_at' => '2026-09-16T08:00',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->first();

    expect($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreArrival)
        ->and($assignment->started_at?->timezone($company->timezone)->format('Y-m-d H:i'))->toBe('2026-09-15 12:00')
        ->and($assignment->currentPhase?->actual_start_at?->equalTo($assignment->started_at))->toBeTrue();
});

test('service start assignment rejects legacy travel in direct start', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();
    Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', $company->timezone));

    expect(fn () => app(CrewMovementService::class)->startAssignment($company->id, $employee->id, [
        'current_stage' => 'p1',
        'stage_started_at' => '2026-09-14 10:30:00',
    ], $user->id))->toThrow(CrewMovementException::class, 'Assignments cannot start directly in this stage.');
});

test('service rejects date-only stage started at', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();

    expect(fn () => app(CrewMovementService::class)->startAssignment($company->id, $employee->id, [
        'stage_started_at' => '2026-09-15',
    ], $user->id))->toThrow(CrewMovementException::class, 'Assignment start date and time must include a time.');
});

test('service rejects future stage started at', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();
    Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', $company->timezone));

    expect(fn () => app(CrewMovementService::class)->startAssignment($company->id, $employee->id, [
        'stage_started_at' => '2026-09-16 08:00:00',
    ], $user->id))->toThrow(CrewMovementException::class, 'Assignment start date and time cannot be in the future.');
});

test('service rejects travel in as a direct start stage', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();

    expect(fn () => app(CrewMovementService::class)->startAssignment($company->id, $employee->id, [
        'current_stage' => 'p1',
        'stage_started_at' => '2026-09-15 08:30:00',
    ], $user->id))->toThrow(
        CrewMovementException::class,
        'Assignments cannot start directly in this stage.',
    );
});

test('direct start stages create only the selected phase', function (string $stage, CrewPhaseCode $expected) {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();
    $assignment = app(CrewMovementService::class)->startAssignment($company->id, $employee->id, [
        'current_stage' => $stage,
        'stage_started_at' => '2026-09-15 08:30:00',
    ], $user->id);

    expect($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->phases)->toHaveCount(1)
        ->and($assignment->currentPhase?->phase_code)->toBe($expected)
        ->and($assignment->currentPhase?->status)->toBe(CrewPhaseStatus::Active)
        ->and($assignment->currentPhase?->sequence)->toBe(1)
        ->and($assignment->phases->pluck('phase_code')->map->value->all())->toBe([$stage]);
})->with([
    'p0' => ['p0', CrewPhaseCode::PreArrival],
]);

test('browser supplied current stage is ignored by store endpoint and defaults to p0', function (string $stage) {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();
    $vessel = makeCrewMovementVessel('Start Test Vessel', $company);

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.create'))
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'current_stage' => $stage,
            'stage_started_at' => '2026-09-15T08:30',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->latest('id')->first();
    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreArrival);
})->with(['p1a', 'p3', 'p1b', 'p2', 'p3', 'p4']);

test('service rejects payable join-standby stages as a direct start', function (string $stage) {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();

    expect(fn () => app(CrewMovementService::class)->startAssignment($company->id, $employee->id, [
        'current_stage' => $stage,
        'stage_started_at' => '2026-09-15 08:30:00',
    ], $user->id))->toThrow(
        CrewMovementException::class,
        'Assignments cannot start directly in this stage.',
    );
})->with(['p1a', 'p1b', 'p2', 'p3', 'p4']);

test('service already arrived shortcut starts at p1a with actual arrival', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $assignment = app(CrewMovementService::class)->startAssignment($company->id, $employee->id, [
        'stage_started_at' => '2026-09-15 08:30:00',
        'position_id' => $rank->id,
        'already_arrived' => true,
        'actual_arrival_at' => '2026-09-15',
        'accommodation_status' => 'no_accommodation',
    ], $user->id);

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby);
});

test('manual quick create preserves planned sign-off and ignores travel input', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();
    $vessel = makeCrewMovementVessel('Ignore Planning Dates', $company);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-09-20',
            'planned_signoff_at' => '2026-11-01',
            'planned_travel_at' => '2026-11-05',
            'stage_started_at' => '2026-09-15T10:30',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->first();

    expect($assignment->planned_join_at?->toDateString())->toBe('2026-09-20')
        ->and($assignment->planned_signoff_at?->toDateString())->toBe('2026-11-01')
        ->and($assignment->planned_travel_at)->toBeNull()
        ->and(CrewPlanningAssignment::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(0);
});

test('active p0 counts as an active assignment and blocks another start', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = actingCrewStarter();
    $service = app(CrewMovementService::class);

    $first = $service->startAssignment($company->id, $employee->id, [
        'current_stage' => 'p0',
        'stage_started_at' => '2026-09-15 08:00:00',
    ], $user->id);

    $status = (new CrewAssignmentStatusResolver)->forEmployee($employee->fresh());

    expect($status['has_active_assignment'])->toBeTrue()
        ->and($status['status'])->toBe('pre_mobilisation');

    expect(fn () => $service->startAssignment($company->id, $employee->id, [
        'stage_started_at' => '2026-09-15 09:00:00',
    ], $user->id))->toThrow(CrewMovementException::class, 'Employee already has an active assignment');

    expect($first->fresh()->status)->toBe(CrewAssignmentStatus::Active);
});

test('draft p0 remains has_active_assignment false', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();
    app(CrewMovementService::class)->createDraft($company->id, $employee->id, [], $user->id);

    $status = (new CrewAssignmentStatusResolver)->forEmployee($employee->fresh());

    expect($status['has_active_assignment'])->toBeFalse()
        ->and($status['status'])->toBe('pre_mobilisation');
});

test('cross-company employee and vessel are rejected on start', function () {
    ['user' => $user, 'company' => $company, 'employee' => $localEmployee] = actingCrewStarter();
    ['employee' => $otherEmployee, 'company' => $otherCompany] = makeCrewAssignmentFixtures();
    $foreignVessel = makeCrewMovementVessel('Foreign Vessel', $otherCompany);

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.create'))
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $otherEmployee->id,
            'stage_started_at' => '2026-09-15T10:30',
        ])
        ->assertRedirect(route('organization.crew-assignments.create'))
        ->assertSessionHasErrors('employee_id');

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.create'))
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $localEmployee->id,
            'vessel_id' => $foreignVessel->id,
            'stage_started_at' => '2026-09-15T10:30',
        ])
        ->assertRedirect(route('organization.crew-assignments.create'))
        ->assertSessionHasErrors('vessel_id');
});

test('active p0 rejects crafted approve mobilisation and creates no p1', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();
    $service = app(CrewMovementService::class);

    $assignment = $service->startAssignment($company->id, $employee->id, [
        'stage_started_at' => '2026-09-15 08:00:00',
    ], $user->id);

    expect(fn () => $service->perform($company->id, $assignment->id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-09-15 14:15:00',
    ], $user->id))->toThrow(
        CrewMovementException::class,
        'Start Assignment can only be performed on a draft assignment in planned pre-mobilisation.',
    );

    $assignment->refresh()->load('phases');
    expect($assignment->phases)->toHaveCount(1)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreArrival)
        ->and(CrewAssignmentPhase::query()->where('crew_assignment_id', $assignment->id)->where('phase_code', CrewPhaseCode::JoinStandby)->count())->toBe(0);
});

test('draft p0 start assignment activates existing p0 without creating p1', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();
    $service = app(CrewMovementService::class);

    $assignment = $service->createDraft($company->id, $employee->id, [], $user->id);
    $p0Id = $assignment->current_phase_id;

    $assignment = $service->perform($company->id, $assignment->id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-09-15 11:00:00',
    ], $user->id);

    $p0 = CrewAssignmentPhase::query()->find($p0Id);

    expect($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->started_at?->timezone($company->timezone)->format('Y-m-d H:i'))->toBe('2026-09-15 11:00')
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreArrival)
        ->and($assignment->currentPhase?->status)->toBe(CrewPhaseStatus::Active)
        ->and($assignment->phases)->toHaveCount(1)
        ->and($p0?->status)->toBe(CrewPhaseStatus::Active)
        ->and($p0?->actual_start_at?->timezone($company->timezone)->format('Y-m-d H:i'))->toBe('2026-09-15 11:00');
});

test('active p0 exposes record arrival as the available mobilisation action', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, startAssignmentPermissions());
    $user->update(['current_company_id' => $company->id]);

    $assignment = app(CrewMovementService::class)->startAssignment($company->id, $employee->id, [
        'position_id' => $rank->id,
        'stage_started_at' => '2026-09-15 08:00:00',
    ], $user->id)->load(['currentPhase', 'employee', 'company']);

    expect(CrewMovementAvailableActions::for($assignment))->toBe([
        CrewMovementAction::RecordArrival->value,
        CrewMovementAction::CancelAssignment->value,
    ])
        ->and(CrewMovementAction::ApproveMobilisation->label())->toBe('Start Assignment');

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.show', $assignment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('assignment.available_actions.0', CrewMovementAction::RecordArrival->value)
            ->where('assignment.recommended_action.action', CrewMovementAction::RecordArrival->value)
            ->where('assignment.recommended_action.label', 'Record Arrival')
            ->where('can.start', true));
});

test('start assignment page permissions distinguish start from draft', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, ['crew_operations.assignments.create']);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew/create')
            ->where('can.create', true)
            ->where('can.start', false)
            ->where('can.perform_movement', false));
});

test('existing linked planning is preserved when an already-linked assignment later moves', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Linked Planning Vessel', $company);
    $service = app(CrewMovementService::class);

    $assignment = $service->createDraft($company->id, $employee->id, [
        'position_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-09-20',
        'planned_signoff_at' => '2026-12-01',
    ], $user->id);

    syncPlanningFromAssignment($assignment);

    $planning = CrewPlanningAssignment::query()->where('crew_assignment_id', $assignment->id)->first();
    expect($planning)->not->toBeNull();

    $assignment = $service->perform($company->id, $assignment->id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-09-15 10:00:00',
    ], $user->id);

    expect(CrewPlanningAssignment::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(1)
        ->and(CrewPlanningAssignment::query()->where('crew_assignment_id', $assignment->id)->first()?->id)->toBe($planning->id);
});

test('list presenter keeps planned join values for expected vessel join display', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();

    $assignment = app(CrewMovementService::class)->startAssignment($company->id, $employee->id, [
        'position_id' => $rank->id,
        'planned_join_at' => '2026-09-20',
        'current_stage' => 'p0',
        'stage_started_at' => '2026-09-15 08:00:00',
    ], $user->id)->load(['employee', 'position', 'vessel', 'client', 'currentPhase', 'company']);

    $item = CrewAssignmentPresenter::listItem($assignment);

    expect($item['planned_join_at'])->toBe('2026-09-20')
        ->and($item['available_actions'])->toContain(CrewMovementAction::RecordArrival->value)
        ->and($item['current_phase']['code'])->toBe('p0')
        ->and($item['current_phase']['status'])->toBe('active');
});

test('direct start with future arrival and no expected join succeeds without inventing planned_join_at', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();
    Carbon::setTestNow(Carbon::parse('2026-09-25 12:00:00', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'planned_arrival_at' => '2026-09-26',
            'planned_join_at' => '',
            'planned_signoff_at' => null,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->first();

    expect($assignment)->not->toBeNull()
        ->and($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->started_at)->not->toBeNull()
        ->and($assignment->planned_arrival_at?->toDateString())->toBe('2026-09-26')
        ->and($assignment->planned_join_at)->toBeNull()
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreArrival)
        ->and($assignment->currentPhase?->status)->toBe(CrewPhaseStatus::Active)
        ->and($assignment->currentPhase?->actual_start_at)->not->toBeNull();
});

test('direct start with no arrival and no expected join keeps planned_join_at null', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();
    Carbon::setTestNow(Carbon::parse('2026-09-25 12:00:00', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->first();

    expect($assignment)->not->toBeNull()
        ->and($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->planned_join_at)->toBeNull()
        ->and($assignment->planned_arrival_at)->toBeNull();
});

test('direct start with valid arrival before expected join persists both forecasts', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();
    Carbon::setTestNow(Carbon::parse('2026-09-25 12:00:00', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'planned_arrival_at' => '2026-09-26',
            'planned_join_at' => '2026-09-27',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->first();

    expect($assignment)->not->toBeNull()
        ->and($assignment->planned_arrival_at?->toDateString())->toBe('2026-09-26')
        ->and($assignment->planned_join_at?->toDateString())->toBe('2026-09-27');
});

test('direct start with arrival after expected join is blocked', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();
    Carbon::setTestNow(Carbon::parse('2026-09-25 12:00:00', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'planned_arrival_at' => '2026-09-28',
            'planned_join_at' => '2026-09-27',
        ])
        ->assertSessionHasErrors(['planned_arrival_at']);

    expect(session('errors')->first('planned_arrival_at'))
        ->toBe('Arrival Date cannot be after Expected Vessel Join.')
        ->and(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('crafted submission_intent plan is blocked on create store', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter([
        'crew_operations.planning.create',
    ]);
    Carbon::setTestNow(Carbon::parse('2026-09-25 12:00:00', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'plan',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'planned_arrival_at' => '2026-09-26',
            'planned_join_at' => '',
            'planned_signoff_at' => '2026-11-30',
        ])
        ->assertSessionHasErrors(['submission_intent']);

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0)
        ->and(CrewAssignmentSubmissionIntent::tryFrom('plan'))->toBeNull();
});

test('draft to active keeps expected join and signoff forecasts on the same assignment', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();
    $vessel = makeCrewMovementVessel('Draft Start Vessel', $company);
    $service = app(CrewMovementService::class);

    $draft = $service->createDraft($company->id, $employee->id, [
        'position_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-10-10',
        'planned_signoff_at' => '2026-11-30',
    ], $user->id);

    Carbon::setTestNow(Carbon::parse('2026-10-01 09:00:00', $company->timezone));

    $started = $service->perform($company->id, $draft->id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-10-01 09:00:00',
    ], $user->id);

    expect($started->id)->toBe($draft->id)
        ->and($started->status)->toBe(CrewAssignmentStatus::Active)
        ->and($started->started_at?->timezone($company->timezone)->toDateString())->toBe('2026-10-01')
        ->and($started->planned_join_at?->toDateString())->toBe('2026-10-10')
        ->and($started->planned_signoff_at?->toDateString())->toBe('2026-11-30');
});

test('open-ended direct start conflicts with a future crew planning reservation', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter([
        'crew_operations.planning.create',
    ]);
    $vessel = makeCrewMovementVessel('Future Plan Vessel', $company);

    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2026-10-01',
        'planned_leave_date' => '2026-11-30',
    ]);

    Carbon::setTestNow(Carbon::parse('2026-09-25 12:00:00', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
        ])
        ->assertSessionHasErrors(['employee_id']);

    expect(CrewAssignment::query()
        ->where('company_id', $company->id)
        ->where('status', CrewAssignmentStatus::Active)
        ->count())->toBe(0);
});

test('direct start with known end before a future planning reservation is allowed', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter([
        'crew_operations.planning.create',
    ]);
    $vessel = makeCrewMovementVessel('Bounded Start Vessel', $company);

    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2026-10-01',
        'planned_leave_date' => '2026-11-30',
    ]);

    Carbon::setTestNow(Carbon::parse('2026-09-25 12:00:00', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'planned_signoff_at' => '2026-09-30',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(CrewAssignment::query()
        ->where('company_id', $company->id)
        ->where('status', CrewAssignmentStatus::Active)
        ->count())->toBe(1);
});

test('direct start with known end overlapping a future planning reservation is blocked', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter([
        'crew_operations.planning.create',
    ]);
    $vessel = makeCrewMovementVessel('Overlap Start Vessel', $company);

    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2026-10-01',
        'planned_leave_date' => '2026-11-30',
    ]);

    Carbon::setTestNow(Carbon::parse('2026-09-25 12:00:00', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'planned_signoff_at' => '2026-10-15',
        ])
        ->assertSessionHasErrors(['employee_id']);

    expect(CrewAssignment::query()
        ->where('company_id', $company->id)
        ->where('status', CrewAssignmentStatus::Active)
        ->count())->toBe(0);
});

test('start conflict payload keeps planned_join_at null when join was not supplied', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter([
        'crew_operations.planning.create',
    ]);
    $vessel = makeCrewMovementVessel('Payload Plan Vessel', $company);

    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2026-10-01',
        'planned_leave_date' => '2026-11-30',
    ]);

    Carbon::setTestNow(Carbon::parse('2026-09-25 12:00:00', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
        ])
        ->assertSessionHasErrors(['employee_id', 'conflict']);

    $payload = json_decode((string) session('errors')->first('conflict'), true);

    expect($payload)->toBeArray()
        ->and($payload['blocking'])->toBeTrue()
        ->and($payload['new_assignment'])->toHaveKey('planned_join_at')
        ->and($payload['new_assignment']['planned_join_at'])->toBeNull();
});

test('direct start with sign-off before operational start is blocked', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();
    Carbon::setTestNow(Carbon::parse('2026-09-25 12:00:00', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'planned_join_at' => null,
            'planned_signoff_at' => '2026-09-20',
        ])
        ->assertSessionHasErrors(['employee_id']);

    expect(session('errors')->first('employee_id'))
        ->toBe('Expected Sign-Off cannot be before Assignment Start.')
        ->and(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('direct start with sign-off after operational start succeeds with null planned join', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();
    Carbon::setTestNow(Carbon::parse('2026-09-25 12:00:00', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'planned_signoff_at' => '2026-09-30',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->first();

    expect($assignment)->not->toBeNull()
        ->and($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->planned_join_at)->toBeNull()
        ->and($assignment->planned_signoff_at?->toDateString())->toBe('2026-09-30');
});

test('direct start with same-day expected sign-off is not rejected by assignment start ordering', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();
    Carbon::setTestNow(Carbon::parse('2026-09-25 12:00:00', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'planned_signoff_at' => '2026-09-25',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->first();

    expect($assignment)->not->toBeNull()
        ->and($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->planned_signoff_at?->toDateString())->toBe('2026-09-25');
});

test('direct start still blocks when expected sign-off is before expected vessel join', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();
    Carbon::setTestNow(Carbon::parse('2026-09-25 12:00:00', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'planned_join_at' => '2026-09-27',
            'planned_signoff_at' => '2026-09-26',
        ])
        ->assertSessionHasErrors();

    $messages = collect(session('errors')->all())->flatten()->all();

    expect($messages)->toContain('Expected Sign-off cannot be before Expected Vessel Join.')
        ->and($messages)->not->toContain('Expected Sign-Off cannot be before Assignment Start.')
        ->and(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('draft to active with future expected sign-off keeps forecasts on the same assignment', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();
    $vessel = makeCrewMovementVessel('Future Signoff Start Vessel', $company);
    $service = app(CrewMovementService::class);

    $draft = $service->createDraft($company->id, $employee->id, [
        'position_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-10-10',
        'planned_signoff_at' => '2026-11-30',
    ], $user->id);

    Carbon::setTestNow(Carbon::parse('2026-10-01 09:00:00', $company->timezone));

    $started = $service->perform($company->id, $draft->id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-10-01 09:00:00',
    ], $user->id);

    expect($started->id)->toBe($draft->id)
        ->and($started->status)->toBe(CrewAssignmentStatus::Active)
        ->and($started->started_at?->timezone($company->timezone)->toDateString())->toBe('2026-10-01')
        ->and($started->planned_join_at?->toDateString())->toBe('2026-10-10')
        ->and($started->planned_signoff_at?->toDateString())->toBe('2026-11-30');
});

test('already arrived start creates active p2a without fabricated p0', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();
    $vessel = makeCrewMovementVessel('Already Arrived Vessel', $company);
    Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_arrival_at' => '2026-10-04',
            'planned_join_at' => '2026-10-10',
            'already_arrived' => true,
            'accommodation_status' => 'no_accommodation',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()
        ->where('company_id', $company->id)
        ->with(['phases', 'currentPhase'])
        ->first();

    expect($assignment)->not->toBeNull()
        ->and($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->phases)->toHaveCount(1)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby)
        ->and($assignment->currentPhase?->status)->toBe(CrewPhaseStatus::Active)
        ->and($assignment->currentPhase?->sequence)->toBe(1)
        ->and($assignment->currentPhase?->actual_start_at?->timezone($company->timezone)->toDateString())->toBe('2026-10-04')
        ->and($assignment->started_at?->equalTo($assignment->currentPhase?->actual_start_at))->toBeTrue()
        ->and($assignment->planned_arrival_at)->toBeNull()
        ->and($assignment->phases->pluck('phase_code')->map->value->all())->toBe(['p1a'])
        ->and(CrewMovementAvailableActions::for($assignment->load(['currentPhase', 'employee', 'company'])))->toBe([
            CrewMovementAction::SendToTraining->value,
            CrewMovementAction::JoinVessel->value,
            CrewMovementAction::CancelAssignment->value,
        ])
        ->and(CrewArrivalResolver::timestamp($assignment)?->timezone($company->timezone)->toDateString())->toBe('2026-10-04')
        ->and((new CrewPhasePayCategoryResolver)->resolve(CrewPhaseCode::JoinStandby))
        ->toBe(CrewTimesheetPayCategory::SignOnStandby);

    $log = Activity::query()
        ->where('subject_type', CrewAssignment::class)
        ->where('subject_id', $assignment->id)
        ->where('properties->event', 'crew_assignment_started_already_arrived')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->properties['skipped_pre_mobilisation'] ?? null)->toBeTrue()
        ->and($log->properties['record_arrival_performed'] ?? null)->toBeFalse()
        ->and($log->properties['planned_arrival_at'] ?? null)->toBeNull();

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.show', $assignment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('assignment.current_phase.code', 'p1a')
            ->where('assignment.actual_arrival_at', '2026-10-04')
            ->where('assignment.planned_arrival_at', null)
            ->where('assignment.available_actions.0', CrewMovementAction::SendToTraining->value)
            ->has('assignment.phase_timeline', 1)
            ->where('assignment.phase_timeline.0.phase_code', 'p1a'));
});

test('already arrived start requires actual arrival date', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.create'))
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'already_arrived' => true,
            'accommodation_status' => 'no_accommodation',
        ])
        ->assertRedirect(route('organization.crew-assignments.create'))
        ->assertSessionHasErrors(['planned_arrival_at']);

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('already arrived start rejects future actual arrival date in company timezone', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();
    expect($company->timezone)->toBe('Asia/Dubai');
    Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00', 'Asia/Dubai'));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'planned_arrival_at' => '2026-10-14',
            'already_arrived' => true,
            'accommodation_status' => 'no_accommodation',
        ])
        ->assertSessionHasErrors(['planned_arrival_at' => 'Actual Arrival Date cannot be in the future.']);

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('already arrived start allows today in company timezone', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();
    Carbon::setTestNow(Carbon::parse('2026-10-13 23:30:00', 'Asia/Dubai'));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'planned_arrival_at' => '2026-10-13',
            'already_arrived' => true,
            'accommodation_status' => 'no_accommodation',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->first();

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby)
        ->and($assignment->currentPhase?->actual_start_at?->timezone('Asia/Dubai')->toDateString())->toBe('2026-10-13')
        ->and($assignment->planned_arrival_at)->toBeNull();
});

test('unchecked already arrived keeps the existing p0 start flow', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();
    Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'planned_arrival_at' => '2026-10-04',
            'already_arrived' => false,
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->with('phases')->first();

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreArrival)
        ->and($assignment->phases)->toHaveCount(1)
        ->and($assignment->started_at?->timezone($company->timezone)->format('Y-m-d H:i'))->toBe('2026-10-13 12:00')
        ->and($assignment->planned_arrival_at?->toDateString())->toBe('2026-10-04')
        ->and(CrewArrivalResolver::timestamp($assignment))->toBeNull();
});

test('already arrived start blocks employee with an existing active assignment', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();
    $vessel = makeCrewMovementVessel('Conflict Vessel', $company);
    app(CrewMovementService::class)->startAssignment($company->id, $employee->id, [
        'position_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'stage_started_at' => '2026-10-01 08:00:00',
    ], $user->id);
    Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'planned_arrival_at' => '2026-10-04',
            'already_arrived' => true,
            'accommodation_status' => 'no_accommodation',
        ])
        ->assertSessionHasErrors(['employee_id']);

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(1);
});

test('service already arrived start does not invent record arrival movement history', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();
    Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00', $company->timezone));

    $assignment = app(CrewMovementService::class)->startAssignment($company->id, $employee->id, [
        'already_arrived' => true,
        'accommodation_status' => 'no_accommodation',
        'actual_arrival_at' => '2026-10-04',
        'planned_arrival_at' => '2026-09-28',
    ], $user->id);

    expect($assignment->phases)->toHaveCount(1)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby)
        ->and($assignment->planned_arrival_at?->toDateString())->toBe('2026-09-28')
        ->and(CrewArrivalResolver::date($assignment, $company->timezone))->toBe('2026-10-04')
        ->and(Activity::query()
            ->where('subject_id', $assignment->id)
            ->where('properties->event', 'crew_movement_performed')
            ->count())->toBe(0);
});

test('draft submission ignores already arrived shortcut', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'draft',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'already_arrived' => true,
            'accommodation_status' => 'no_accommodation',
            'planned_arrival_at' => '2026-10-04',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->first();

    expect($assignment->status)->toBe(CrewAssignmentStatus::Draft)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreArrival)
        ->and($assignment->currentPhase?->status)->toBe(CrewPhaseStatus::Planned)
        ->and($assignment->currentPhase?->actual_start_at)->toBeNull()
        ->and($assignment->planned_arrival_at?->toDateString())->toBe('2026-10-04');
});

test('already arrived planning handoff preserves planned arrival independently of actual arrival', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();
    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.assignments.create',
        'crew_operations.assignments.view',
        'crew_operations.movements.perform',
    ]);
    $vessel = makeCrewMovementVessel('Planning Already Arrived Vessel', $company);
    Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00', $company->timezone));

    $vacant = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => null,
        'planned_arrival_date' => '2026-10-02',
        'planned_join_date' => '2026-10-10',
        'planned_leave_date' => '2026-11-30',
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create', [
            'planning_assignment_id' => $vacant->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('planning_context.planned_arrival_at', '2026-10-02'));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'client_id' => $vessel->client_id,
            'planned_join_at' => '2026-10-10',
            'planned_arrival_at' => '2026-10-04',
            'already_arrived' => true,
            'accommodation_status' => 'no_accommodation',
            'planning_assignment_id' => $vacant->id,
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()
        ->where('company_id', $company->id)
        ->with(['phases', 'currentPhase', 'planningAssignment'])
        ->first();

    expect($assignment)->not->toBeNull()
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby)
        ->and($assignment->planned_arrival_at?->toDateString())->toBe('2026-10-02')
        ->and(CrewArrivalResolver::date($assignment, $company->timezone))->toBe('2026-10-04')
        ->and($assignment->planningAssignment?->id)->toBe($vacant->id);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.show', $assignment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('assignment.planned_arrival_at', '2026-10-02')
            ->where('assignment.actual_arrival_at', '2026-10-04')
            ->where('assignment.current_phase.code', 'p1a'));
});

test('already arrived planning handoff does not reuse omitted arrival as actual from the planning slot', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();
    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.assignments.create',
        'crew_operations.movements.perform',
    ]);
    $vessel = makeCrewMovementVessel('Planning Prefill Guard Vessel', $company);
    Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00', $company->timezone));

    $vacant = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => null,
        'planned_arrival_date' => '2026-10-02',
        'planned_join_date' => '2026-10-10',
        'planned_leave_date' => '2026-11-30',
    ]);

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.create', [
            'planning_assignment_id' => $vacant->id,
        ]))
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'client_id' => $vessel->client_id,
            'planned_join_at' => '2026-10-10',
            'already_arrived' => true,
            'accommodation_status' => 'no_accommodation',
            'planning_assignment_id' => $vacant->id,
        ])
        ->assertRedirect(route('organization.crew-assignments.create', [
            'planning_assignment_id' => $vacant->id,
        ]))
        ->assertSessionHasErrors(['planned_arrival_at']);

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('already arrived planning handoff accepts an explicitly confirmed actual date matching the planning forecast', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();
    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.view',
        'crew_operations.assignments.create',
        'crew_operations.assignments.view',
        'crew_operations.movements.perform',
    ]);
    $vessel = makeCrewMovementVessel('Planning Confirmed Same Day Vessel', $company);
    Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00', $company->timezone));

    $vacant = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'employee_id' => null,
        'planned_arrival_date' => '2026-10-02',
        'planned_join_date' => '2026-10-10',
        'planned_leave_date' => '2026-11-30',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'client_id' => $vessel->client_id,
            'planned_join_at' => '2026-10-10',
            'planned_arrival_at' => '2026-10-02',
            'already_arrived' => true,
            'accommodation_status' => 'no_accommodation',
            'planning_assignment_id' => $vacant->id,
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()
        ->where('company_id', $company->id)
        ->with(['currentPhase', 'planningAssignment'])
        ->first();

    expect($assignment)->not->toBeNull()
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby)
        ->and($assignment->planned_arrival_at?->toDateString())->toBe('2026-10-02')
        ->and(CrewArrivalResolver::date($assignment, $company->timezone))->toBe('2026-10-02')
        ->and($assignment->planningAssignment?->id)->toBe($vacant->id);
});

test('already arrived assignment keeps planned and actual arrival distinct in movement history', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();
    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.movements.perform',
        'reports.crew_movement_history.view',
    ]);
    $vessel = makeCrewMovementVessel('History Already Arrived Vessel', $company);
    Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00', $company->timezone));

    $assignment = app(CrewMovementService::class)->startAssignment($company->id, $employee->id, [
        'position_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'already_arrived' => true,
        'accommodation_status' => 'no_accommodation',
        'actual_arrival_at' => '2026-10-04',
        'planned_arrival_at' => '2026-10-01',
        'planned_join_at' => '2026-10-10',
    ], $user->id);

    $presented = CrewDateProvenance::plannedArrival($assignment, $company->timezone);
    $actual = CrewDateProvenance::actualArrival($assignment, $company->timezone);

    expect($presented['value'])->toBe('2026-10-01')
        ->and($actual['value'])->toBe('2026-10-04')
        ->and($actual['origin'])->toBe(CrewDateProvenance::MovementActual);

    $this->actingAs($user)
        ->get(route('organization.reports.crew-movement-history.index', [
            'search' => $assignment->assignment_no,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('assignments.0.planned_arrival', '2026-10-01')
            ->where('assignments.0.actual_arrival', '2026-10-04'));
});

test('already arrived with hotel accommodation creates active p2a and pre-join stay', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter([
        'reports.hotel_checkin_checkout.view',
    ]);
    $vessel = makeCrewMovementVessel('Hotel Already Arrived Vessel', $company);
    $hotel = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Arrival Hotel']);
    Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_arrival_at' => '2026-10-04',
            'planned_join_at' => '2026-10-10',
            'already_arrived' => true,
            'accommodation_status' => CrewAccommodationStatus::Hotel->value,
            'hotel_id' => $hotel->id,
            'check_in_date' => '2026-10-04',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()
        ->where('company_id', $company->id)
        ->with(['currentPhase', 'accommodationStays'])
        ->first();

    expect($assignment)->not->toBeNull()
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby)
        ->and($assignment->accommodationStays)->toHaveCount(1)
        ->and($assignment->accommodationStays->first()?->accommodation_status)->toBe(CrewAccommodationStatus::Hotel)
        ->and($assignment->accommodationStays->first()?->check_in_date?->toDateString())->toBe('2026-10-04')
        ->and($assignment->accommodationStays->first()?->started_from_phase_id)->toBe($assignment->currentPhase?->id);

    $this->actingAs($user)
        ->get(route('organization.reports.hotel-checkin-checkout.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('stays', 1)
            ->where('stays.0.assignment.id', $assignment->id));
});

test('already arrived without accommodation choice is rejected', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();
    $vessel = makeCrewMovementVessel('Missing Accommodation Vessel', $company);
    Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_arrival_at' => '2026-10-04',
            'already_arrived' => true,
        ])
        ->assertSessionHasErrors(['accommodation_status']);

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('already arrived hotel validation failure does not create assignment', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();
    $vessel = makeCrewMovementVessel('Invalid Hotel Vessel', $company);
    Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_arrival_at' => '2026-10-04',
            'already_arrived' => true,
            'accommodation_status' => CrewAccommodationStatus::Hotel->value,
            'hotel_id' => 999999,
            'check_in_date' => '2026-10-04',
        ])
        ->assertSessionHasErrors(['hotel_id']);

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('already arrived join vessel can close the pre-join hotel stay', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();
    $vessel = makeCrewMovementVessel('Join After Already Arrived Hotel', $company);
    $hotel = Hotel::factory()->create(['company_id' => $company->id]);
    Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_arrival_at' => '2026-10-04',
            'planned_join_at' => '2026-10-10',
            'already_arrived' => true,
            'accommodation_status' => CrewAccommodationStatus::Hotel->value,
            'hotel_id' => $hotel->id,
            'check_in_date' => '2026-10-04',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->firstOrFail();

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::JoinVessel->value,
            'occurred_at' => '2026-10-10 08:00:00',
            'vessel_id' => $vessel->id,
            'position_id' => $rank->id,
            'check_out_date' => '2026-10-10',
        ])
        ->assertRedirect();

    $stay = CrewAccommodationStay::query()->where('crew_assignment_id', $assignment->id)->first();

    expect($stay?->check_out_date?->toDateString())->toBe('2026-10-10');
});

test('already arrived rejects future hotel check-in date', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();
    $vessel = makeCrewMovementVessel('Future Check-In Vessel', $company);
    $hotel = Hotel::factory()->create(['company_id' => $company->id]);
    Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_arrival_at' => '2026-10-04',
            'already_arrived' => true,
            'accommodation_status' => CrewAccommodationStatus::Hotel->value,
            'hotel_id' => $hotel->id,
            'check_in_date' => '2026-10-14',
        ])
        ->assertSessionHasErrors(['check_in_date']);

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('already arrived rejects invalid room type for selected hotel', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();
    $vessel = makeCrewMovementVessel('Invalid Room Type Vessel', $company);
    $hotel = Hotel::factory()->create(['company_id' => $company->id]);
    $otherHotel = Hotel::factory()->create(['company_id' => $company->id]);
    $roomType = RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => $otherHotel->id,
    ]);
    Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_arrival_at' => '2026-10-04',
            'already_arrived' => true,
            'accommodation_status' => CrewAccommodationStatus::Hotel->value,
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'check_in_date' => '2026-10-04',
        ])
        ->assertSessionHasErrors(['room_type_id']);

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('already arrived rejects a hotel from another company', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();
    ['company' => $otherCompany] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Foreign Hotel Vessel', $company);
    $foreignHotel = Hotel::factory()->create(['company_id' => $otherCompany->id, 'name' => 'Other Company Hotel']);
    Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_arrival_at' => '2026-10-04',
            'already_arrived' => true,
            'accommodation_status' => CrewAccommodationStatus::Hotel->value,
            'hotel_id' => $foreignHotel->id,
            'check_in_date' => '2026-10-04',
        ])
        ->assertSessionHasErrors(['hotel_id']);

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0)
        ->and(CrewAccommodationStay::query()->where('hotel_id', $foreignHotel->id)->exists())->toBeFalse();
});

test('already arrived no accommodation does not attach a hotel from another company', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();
    ['company' => $otherCompany] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Crafted Foreign Hotel Vessel', $company);
    $foreignHotel = Hotel::factory()->create(['company_id' => $otherCompany->id]);
    Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_arrival_at' => '2026-10-04',
            'already_arrived' => true,
            'accommodation_status' => CrewAccommodationStatus::NoAccommodation->value,
            'hotel_id' => $foreignHotel->id,
            'check_in_date' => '2026-10-04',
        ])
        ->assertSessionHasErrors(['hotel_id']);

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0)
        ->and(CrewAccommodationStay::query()->where('hotel_id', $foreignHotel->id)->exists())->toBeFalse();
});

test('already arrived rejects an inactive hotel', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();
    $vessel = makeCrewMovementVessel('Inactive Hotel Vessel', $company);
    $hotel = Hotel::factory()->create([
        'company_id' => $company->id,
        'is_active' => false,
    ]);
    Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_arrival_at' => '2026-10-04',
            'already_arrived' => true,
            'accommodation_status' => CrewAccommodationStatus::Hotel->value,
            'hotel_id' => $hotel->id,
            'check_in_date' => '2026-10-04',
        ])
        ->assertSessionHasErrors(['hotel_id']);

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('already arrived rejects hotel check-in before the actual arrival date', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();
    $vessel = makeCrewMovementVessel('Early Check-In Vessel', $company);
    $hotel = Hotel::factory()->create(['company_id' => $company->id]);
    Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00', $company->timezone));

    $response = $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_arrival_at' => '2026-10-04',
            'already_arrived' => true,
            'accommodation_status' => CrewAccommodationStatus::Hotel->value,
            'hotel_id' => $hotel->id,
            'check_in_date' => '2026-10-03',
        ]);

    $response->assertSessionHasErrors(['check_in_date']);
    expect(session('errors')->get('check_in_date')[0])
        ->toContain('Hotel check-in cannot be before the actual arrival date')
        ->and(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('already arrived rejects a room type owned by another company', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();
    ['company' => $otherCompany] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Foreign Room Type Vessel', $company);
    $hotel = Hotel::factory()->create(['company_id' => $company->id]);
    $foreignRoomType = RoomType::factory()->create([
        'company_id' => $otherCompany->id,
        'hotel_id' => $hotel->id,
    ]);
    Carbon::setTestNow(Carbon::parse('2026-10-13 12:00:00', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_arrival_at' => '2026-10-04',
            'already_arrived' => true,
            'accommodation_status' => CrewAccommodationStatus::Hotel->value,
            'hotel_id' => $hotel->id,
            'room_type_id' => $foreignRoomType->id,
            'check_in_date' => '2026-10-04',
        ])
        ->assertSessionHasErrors(['room_type_id']);

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0)
        ->and(CrewAccommodationStay::query()->where('room_type_id', $foreignRoomType->id)->exists())->toBeFalse();
});
