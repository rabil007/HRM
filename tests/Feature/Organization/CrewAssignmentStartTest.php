<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Exceptions\CrewMovementException;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewPlanningAssignment;
use App\Models\EmployeeSeaService;
use App\Support\CrewMovements\CrewAssignmentPresenter;
use App\Support\CrewMovements\CrewAssignmentStatusResolver;
use App\Support\CrewMovements\CrewMovementAvailableActions;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\CrewPlanning\SyncPlanningAssignmentFromCrewAssignment;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

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
            'rank_id' => $rank->id,
        ])
        ->assertRedirect(route('dashboard'));

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->first();

    expect($assignment)->not->toBeNull()
        ->and($assignment->status)->toBe(CrewAssignmentStatus::Draft)
        ->and($assignment->started_at)->toBeNull()
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreMobilisation)
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
            'rank_id' => $rank->id,
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
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::TravelIn)
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
            'rank_id' => $rank->id,
            'current_stage' => 'p0',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->first();

    expect($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreMobilisation)
        ->and($assignment->currentPhase?->status)->toBe(CrewPhaseStatus::Active)
        ->and($assignment->started_at?->timezone($company->timezone)->format('Y-m-d H:i'))->toBe('2026-09-15 12:00');
});

test('explicit travel in start without stage_started_at uses identical company-local timestamps', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();
    Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', $company->timezone));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'current_stage' => 'p1',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->first();

    expect($assignment)->not->toBeNull()
        ->and($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->started_at?->timezone($company->timezone)->format('Y-m-d H:i'))->toBe('2026-09-15 12:00')
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::TravelIn)
        ->and($assignment->currentPhase?->actual_start_at?->equalTo($assignment->started_at))->toBeTrue();
});

test('save as draft does not require stage started at and stays planned p0', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'draft',
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
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
        ->and($assignment->currentPhase?->actual_start_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-09-15 08:00:00');
});

test('supplied assignment start date and time is parsed in the company timezone', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = actingCrewStarter();
    expect($company->timezone)->toBe('Asia/Dubai');
    Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', 'Asia/Dubai'));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'stage_started_at' => '2026-09-15T10:30',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->first();

    expect($assignment->started_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-09-15 06:30:00')
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::TravelIn)
        ->and($assignment->currentPhase?->actual_start_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-09-15 06:30:00');
});

test('date-only stage started at is rejected', function () {
    ['user' => $user, 'employee' => $employee] = actingCrewStarter();

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.create'))
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'stage_started_at' => '2026-09-15',
        ])
        ->assertRedirect(route('organization.crew-assignments.create'))
        ->assertSessionHasErrors('stage_started_at');
});

test('future stage started at is rejected', function () {
    ['user' => $user, 'employee' => $employee, 'company' => $company] = actingCrewStarter();
    Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', $company->timezone));

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.create'))
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'current_stage' => 'p0',
            'stage_started_at' => '2026-09-16T08:00',
        ])
        ->assertRedirect(route('organization.crew-assignments.create'))
        ->assertSessionHasErrors('stage_started_at');
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
    'p1' => ['p1', CrewPhaseCode::TravelIn],
    'p0' => ['p0', CrewPhaseCode::PreMobilisation],
]);

test('disallowed direct start stages are rejected', function (string $stage) {
    ['user' => $user, 'employee' => $employee] = actingCrewStarter();

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.create'))
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'current_stage' => $stage,
            'stage_started_at' => '2026-09-15T08:30',
        ])
        ->assertRedirect(route('organization.crew-assignments.create'))
        ->assertSessionHasErrors('current_stage');
})->with(['p2a', 'p3', 'p2b', 'p4', 'p5', 'p6']);

test('service rejects payable join-standby stages as a direct start', function (string $stage) {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();

    expect(fn () => app(CrewMovementService::class)->startAssignment($company->id, $employee->id, [
        'current_stage' => $stage,
        'stage_started_at' => '2026-09-15 08:30:00',
    ], $user->id))->toThrow(
        CrewMovementException::class,
        'Assignments cannot start directly in this stage.',
    );
})->with(['p2a', 'p3']);

test('manual quick create ignores planned sign-off and travel input', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = actingCrewStarter();
    $vessel = makeCrewMovementVessel('Ignore Planning Dates', $company);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'start',
            'employee_id' => $employee->id,
            'rank_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-09-20',
            'planned_signoff_at' => '2026-11-01',
            'planned_travel_at' => '2026-11-05',
            'stage_started_at' => '2026-09-15T10:30',
        ])
        ->assertRedirect();

    $assignment = CrewAssignment::query()->where('company_id', $company->id)->first();

    expect($assignment->planned_join_at?->toDateString())->toBe('2026-09-20')
        ->and($assignment->planned_signoff_at)->toBeNull()
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

test('start travel from active p0 completes p0 and opens p1 without rewriting started_at', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();
    $service = app(CrewMovementService::class);

    $assignment = $service->startAssignment($company->id, $employee->id, [
        'current_stage' => 'p0',
        'stage_started_at' => '2026-09-15 08:00:00',
    ], $user->id);

    $originalStart = $assignment->started_at?->copy();
    $p0Id = $assignment->current_phase_id;

    $assignment = $service->perform($company->id, $assignment->id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-09-15 14:15:00',
    ], $user->id);

    $p0 = CrewAssignmentPhase::query()->find($p0Id);

    expect($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->started_at?->equalTo($originalStart))->toBeTrue()
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::TravelIn)
        ->and($assignment->currentPhase?->status)->toBe(CrewPhaseStatus::Active)
        ->and($assignment->currentPhase?->actual_start_at?->timezone($company->timezone)->format('Y-m-d H:i'))->toBe('2026-09-15 14:15')
        ->and($p0?->status)->toBe(CrewPhaseStatus::Completed)
        ->and($p0?->actual_start_at?->timezone($company->timezone)->format('Y-m-d H:i'))->toBe('2026-09-15 08:00')
        ->and($p0?->actual_end_at?->timezone($company->timezone)->format('Y-m-d H:i'))->toBe('2026-09-15 14:15')
        ->and($assignment->phases)->toHaveCount(2)
        ->and($assignment->tour_of_duty_days)->toBeNull()
        ->and(EmployeeSeaService::query()->where('employee_id', $employee->id)->count())->toBe(0);
});

test('legacy draft p0 start travel remains backward compatible', function () {
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
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::TravelIn)
        ->and($p0?->status)->toBe(CrewPhaseStatus::Completed)
        ->and($p0?->actual_start_at?->timezone($company->timezone)->format('Y-m-d H:i'))->toBe('2026-09-15 11:00')
        ->and($p0?->actual_end_at?->timezone($company->timezone)->format('Y-m-d H:i'))->toBe('2026-09-15 11:00');
});

test('active p0 exposes start travel as the available mobilisation action', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    grantCompanyPermissions($user, $company, startAssignmentPermissions());
    $user->update(['current_company_id' => $company->id]);

    $assignment = app(CrewMovementService::class)->startAssignment($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'current_stage' => 'p0',
        'stage_started_at' => '2026-09-15 08:00:00',
    ], $user->id)->load(['currentPhase', 'employee', 'company']);

    expect(CrewMovementAvailableActions::for($assignment))->toBe([
        CrewMovementAction::ApproveMobilisation->value,
        CrewMovementAction::CancelAssignment->value,
    ])
        ->and(CrewMovementAction::ApproveMobilisation->label())->toBe('Start Travel');

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.show', $assignment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('assignment.available_actions.0', CrewMovementAction::ApproveMobilisation->value)
            ->where('assignment.recommended_action.action', CrewMovementAction::ApproveMobilisation->value)
            ->where('assignment.recommended_action.label', 'Start Travel')
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
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-09-20',
        'planned_signoff_at' => '2026-12-01',
    ], $user->id);

    app(SyncPlanningAssignmentFromCrewAssignment::class)->sync($assignment);

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
        'rank_id' => $rank->id,
        'planned_join_at' => '2026-09-20',
        'current_stage' => 'p0',
        'stage_started_at' => '2026-09-15 08:00:00',
    ], $user->id)->load(['employee', 'rank', 'vessel', 'client', 'currentPhase', 'company']);

    $item = CrewAssignmentPresenter::listItem($assignment);

    expect($item['planned_join_at'])->toBe('2026-09-20')
        ->and($item['available_actions'])->toContain(CrewMovementAction::ApproveMobilisation->value)
        ->and($item['current_phase']['code'])->toBe('p0')
        ->and($item['current_phase']['status'])->toBe('active');
});
