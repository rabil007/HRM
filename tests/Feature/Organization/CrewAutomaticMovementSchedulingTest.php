<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewScheduledMovementStatus;
use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\CrewOperationsSetting;
use App\Models\CrewScheduledMovement;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\Position;
use App\Models\User;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\CrewMovements\Scheduling\ProcessDueCrewScheduledMovements;
use App\Support\CrewMovements\Scheduling\ResolveCrewMovementTiming;
use App\Support\CrewOperations\CrewOperationsSettings;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    freezeCrewMovementTestClock();
});

afterEach(function (): void {
    restoreCrewMovementTestClock();
});

/**
 * @return array{user: User, company: Company, employee: Employee, rank: Position}
 */
function makeAutomaticSchedulingFixtures(bool $withSchedulePermission = true): array
{
    $fixtures = makeCrewAssignmentFixtures();
    $fixtures['company']->update(['timezone' => 'Asia/Dubai']);

    $permissions = [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.assignments.update',
        'crew_operations.movements.perform',
        'crew_operations.assignments.cancel',
    ];

    if ($withSchedulePermission) {
        $permissions = [
            ...$permissions,
            'crew_operations.movements.schedule',
            'crew_operations.movements.schedule.manage',
            'crew_operations.movements.schedule.view',
        ];
    }

    grantCompanyPermissions($fixtures['user'], $fixtures['company'], $permissions);
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);

    return $fixtures;
}

function makeActivePreArrivalAssignment(array $fixtures): CrewAssignment
{
    $service = app(CrewMovementService::class);
    $vessel = makeCrewMovementVessel('Auto Schedule Vessel', $fixtures['company']);

    $assignment = $service->createDraft($fixtures['company']->id, $fixtures['employee']->id, [
        'position_id' => $fixtures['rank']->id,
        'vessel_id' => $vessel->id,
    ], $fixtures['user']->id);

    $service->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2027-01-08 08:00:00',
    ], $fixtures['user']->id);

    return $assignment->fresh(['currentPhase', 'phases']);
}

test('resolve crew movement timing treats equal and past as immediate', function () {
    $fixtures = makeAutomaticSchedulingFixtures();
    Carbon::setTestNow(CarbonImmutable::parse('2027-01-15 12:00:00', 'Asia/Dubai'));

    expect(ResolveCrewMovementTiming::isFuture('2027-01-15 12:00:00', $fixtures['company']->id, 'Asia/Dubai'))->toBeFalse()
        ->and(ResolveCrewMovementTiming::isFuture('2027-01-15 11:59:59', $fixtures['company']->id, 'Asia/Dubai'))->toBeFalse()
        ->and(ResolveCrewMovementTiming::isFuture('2027-01-15 12:00:01', $fixtures['company']->id, 'Asia/Dubai'))->toBeTrue()
        ->and(ResolveCrewMovementTiming::shouldSchedule(
            CrewMovementAction::RecordArrival,
            '2027-01-15 12:00:01',
            $fixtures['company']->id,
            'Asia/Dubai',
        ))->toBeTrue()
        ->and(ResolveCrewMovementTiming::shouldSchedule(
            CrewMovementAction::CancelAssignment,
            '2027-01-20 09:00:00',
            $fixtures['company']->id,
            'Asia/Dubai',
        ))->toBeFalse();
});

test('past movement records immediately through the actions endpoint', function () {
    $fixtures = makeAutomaticSchedulingFixtures();
    $assignment = makeActivePreArrivalAssignment($fixtures);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2027-01-10 08:00:00',
            'next_phase' => CrewPhaseCode::JoinStandby->value,
            'accommodation_status' => 'no_accommodation',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment))
        ->assertSessionHas('success');

    $assignment->refresh()->load('currentPhase');

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby)
        ->and(CrewScheduledMovement::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(0);
});

test('current movement records immediately through the actions endpoint', function () {
    $fixtures = makeAutomaticSchedulingFixtures();
    $assignment = makeActivePreArrivalAssignment($fixtures);

    Carbon::setTestNow(CarbonImmutable::parse('2027-01-15 09:00:00', 'Asia/Dubai'));

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2027-01-15 09:00:00',
            'next_phase' => CrewPhaseCode::JoinStandby->value,
            'accommodation_status' => 'no_accommodation',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment))
        ->assertSessionHas('success');

    $assignment->refresh()->load('currentPhase');

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby)
        ->and(CrewScheduledMovement::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(0);
});

test('future arrival creates schedule without changing current phase or side effects', function () {
    $fixtures = makeAutomaticSchedulingFixtures();
    $assignment = makeActivePreArrivalAssignment($fixtures);
    $phaseBefore = $assignment->currentPhase?->phase_code;
    $seaBefore = EmployeeSeaService::query()->where('company_id', $fixtures['company']->id)->count();

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2027-01-20 08:59:00',
            'next_phase' => CrewPhaseCode::JoinStandby->value,
            'accommodation_status' => 'no_accommodation',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment))
        ->assertSessionHas('success');

    $assignment->refresh()->load('currentPhase');
    $schedule = CrewScheduledMovement::query()
        ->where('crew_assignment_id', $assignment->id)
        ->first();

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreArrival)
        ->and($assignment->currentPhase?->phase_code)->toBe($phaseBefore)
        ->and($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and(EmployeeSeaService::query()->where('company_id', $fixtures['company']->id)->count())->toBe($seaBefore)
        ->and($schedule)->not->toBeNull()
        ->and($schedule->status)->toBe(CrewScheduledMovementStatus::Scheduled)
        ->and($schedule->movement_action)->toBe(CrewMovementAction::RecordArrival)
        ->and($schedule->expected_result_phase_code)->toBe(CrewPhaseCode::JoinStandby->value);
});

test('forged schedule_later mode with a past timestamp still records immediately', function () {
    $fixtures = makeAutomaticSchedulingFixtures();
    $assignment = makeActivePreArrivalAssignment($fixtures);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'mode' => 'schedule_later',
            'scheduled_at' => '2027-01-10 08:00:00',
            'occurred_at' => '2027-01-10 08:00:00',
            'next_phase' => CrewPhaseCode::JoinStandby->value,
            'accommodation_status' => 'no_accommodation',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment))
        ->assertSessionHas('success');

    $assignment->refresh()->load('currentPhase');

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby)
        ->and(CrewScheduledMovement::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(0);
});

test('forged record_now mode with a future timestamp schedules instead of executing', function () {
    $fixtures = makeAutomaticSchedulingFixtures();
    $assignment = makeActivePreArrivalAssignment($fixtures);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'mode' => 'record_now',
            'occurred_at' => '2027-01-22 09:00:00',
            'next_phase' => CrewPhaseCode::JoinStandby->value,
            'accommodation_status' => 'no_accommodation',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment))
        ->assertSessionHas('success');

    $assignment->refresh()->load('currentPhase');

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreArrival)
        ->and(CrewScheduledMovement::query()->where('crew_assignment_id', $assignment->id)->exists())->toBeTrue();
});

test('future cancel is rejected and does not schedule or cancel the assignment', function () {
    $fixtures = makeAutomaticSchedulingFixtures();
    $assignment = makeActivePreArrivalAssignment($fixtures);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::CancelAssignment->value,
            'occurred_at' => '2027-01-20 09:00:00',
            'reason' => 'Client postponed mobilisation',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment))
        ->assertSessionHasErrors([
            'occurred_at' => 'This movement action cannot be scheduled for a future date and time.',
        ]);

    $assignment->refresh()->load('currentPhase');

    expect($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreArrival)
        ->and(CrewScheduledMovement::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(0);
});

test('unauthorized scheduling is rejected and never falls back to immediate execution', function () {
    $fixtures = makeAutomaticSchedulingFixtures(withSchedulePermission: false);
    $assignment = makeActivePreArrivalAssignment($fixtures);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2027-01-22 09:00:00',
            'next_phase' => CrewPhaseCode::JoinStandby->value,
            'accommodation_status' => 'no_accommodation',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment))
        ->assertSessionHasErrors('occurred_at');

    $assignment->refresh()->load('currentPhase');

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreArrival)
        ->and(CrewScheduledMovement::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(0);
});

test('pending schedule uniqueness is enforced on automatic routing', function () {
    $fixtures = makeAutomaticSchedulingFixtures();
    $assignment = makeActivePreArrivalAssignment($fixtures);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2027-01-20 08:59:00',
            'next_phase' => CrewPhaseCode::JoinStandby->value,
            'accommodation_status' => 'no_accommodation',
        ])
        ->assertSessionHas('success');

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2027-01-21 08:59:00',
            'next_phase' => CrewPhaseCode::JoinStandby->value,
            'accommodation_status' => 'no_accommodation',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment))
        ->assertSessionHasErrors('scheduled_at');

    expect(CrewScheduledMovement::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(1);
});

test('due scheduled arrival executes and advances p0 to p2a', function () {
    $fixtures = makeAutomaticSchedulingFixtures();
    $assignment = makeActivePreArrivalAssignment($fixtures);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2027-01-16 08:00:00',
            'next_phase' => CrewPhaseCode::JoinStandby->value,
            'accommodation_status' => 'no_accommodation',
        ])
        ->assertSessionHas('success');

    $schedule = CrewScheduledMovement::query()
        ->where('crew_assignment_id', $assignment->id)
        ->firstOrFail();

    Carbon::setTestNow(CarbonImmutable::parse('2027-01-16 08:01:00', 'Asia/Dubai'));
    app(ProcessDueCrewScheduledMovements::class)->handle(25, Carbon::now('UTC'));

    $assignment->refresh()->load('currentPhase');
    $schedule->refresh();

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby)
        ->and($schedule->status)->toBe(CrewScheduledMovementStatus::Executed);
});

test('failed scheduled execution keeps p0 and marks needs attention', function () {
    $fixtures = makeAutomaticSchedulingFixtures();
    $assignment = makeActivePreArrivalAssignment($fixtures);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2027-01-16 08:00:00',
            'next_phase' => CrewPhaseCode::JoinStandby->value,
            'accommodation_status' => 'no_accommodation',
        ])
        ->assertSessionHas('success');

    // Process more than 15 minutes late so lateness policy marks Needs Attention.
    Carbon::setTestNow(CarbonImmutable::parse('2027-01-16 08:20:00', 'Asia/Dubai'));
    app(ProcessDueCrewScheduledMovements::class)->handle(25, Carbon::now('UTC'));

    $assignment->refresh()->load('currentPhase');
    $schedule = CrewScheduledMovement::query()
        ->where('crew_assignment_id', $assignment->id)
        ->firstOrFail();

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreArrival)
        ->and($schedule->status)->toBe(CrewScheduledMovementStatus::NeedsAttention)
        ->and($schedule->last_error_code)->toBe('lateness_exceeded');
});

test('valid backdated initial arrival reconciles p0 safely', function () {
    $fixtures = makeAutomaticSchedulingFixtures();
    $assignment = makeActivePreArrivalAssignment($fixtures);
    $p0StartBefore = $assignment->currentPhase?->actual_start_at?->timezone('Asia/Dubai')->toDateTimeString();

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2027-01-05 08:59:00',
            'next_phase' => CrewPhaseCode::JoinStandby->value,
            'accommodation_status' => 'no_accommodation',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment))
        ->assertSessionHas('success');

    $assignment->refresh()->load(['currentPhase', 'phases']);
    $closedP0 = $assignment->phases->first(
        fn ($phase) => $phase->phase_code === CrewPhaseCode::PreArrival,
    );

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby)
        ->and($p0StartBefore)->not->toBeNull()
        ->and($closedP0?->actual_start_at?->lte($closedP0->actual_end_at))->toBeTrue();
});

test('legacy stored future actual override never allows early actual execution', function () {
    $fixtures = makeAutomaticSchedulingFixtures();
    CrewOperationsSetting::query()->updateOrCreate(
        ['company_id' => $fixtures['company']->id],
        ['allow_future_actual_movement_dates' => true, 'max_home_days' => 30],
    );
    CrewOperationsSettings::clearCache($fixtures['company']->id);

    expect(CrewOperationsSettings::allowFutureActualMovementDates($fixtures['company']->id))->toBeFalse();

    $assignment = makeActivePreArrivalAssignment($fixtures);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2027-01-25 09:00:00',
            'next_phase' => CrewPhaseCode::JoinStandby->value,
            'accommodation_status' => 'no_accommodation',
        ])
        ->assertSessionHas('success');

    $assignment->refresh()->load('currentPhase');

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreArrival)
        ->and(CrewScheduledMovement::query()->where('crew_assignment_id', $assignment->id)->exists())->toBeTrue();
});

test('company timezone midnight boundary uses full timestamps', function () {
    $fixtures = makeAutomaticSchedulingFixtures();
    $fixtures['company']->update(['timezone' => 'Pacific/Auckland']);
    Carbon::setTestNow(CarbonImmutable::parse('2027-01-15 00:00:00', 'Pacific/Auckland'));

    expect(ResolveCrewMovementTiming::isFuture('2027-01-14 23:59:59', $fixtures['company']->id, 'Pacific/Auckland'))->toBeFalse()
        ->and(ResolveCrewMovementTiming::isFuture('2027-01-15 00:00:00', $fixtures['company']->id, 'Pacific/Auckland'))->toBeFalse()
        ->and(ResolveCrewMovementTiming::isFuture('2027-01-15 00:00:01', $fixtures['company']->id, 'Pacific/Auckland'))->toBeTrue();
});
