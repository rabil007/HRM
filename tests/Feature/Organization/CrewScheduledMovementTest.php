<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewScheduledMovementStatus;
use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\CrewScheduledMovement;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\Position;
use App\Models\User;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\CrewMovements\Scheduling\ProcessDueCrewScheduledMovements;
use App\Support\CrewMovements\Scheduling\ScheduleCrewMovement;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    freezeCrewMovementTestClock();
});

afterEach(function (): void {
    restoreCrewMovementTestClock();
});

/**
 * @return array{user: User, company: Company, employee: Employee, rank: Position}
 */
function makeCrewScheduledMovementFixtures(): array
{
    $fixtures = makeCrewAssignmentFixtures();

    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.assignments.update',
        'crew_operations.movements.perform',
        'crew_operations.movements.schedule',
        'crew_operations.movements.schedule.manage',
        'crew_operations.movements.schedule.view',
        'crew_operations.assignments.cancel',
    ]);
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);

    return $fixtures;
}

function advanceJoinStandbyAssignment(array $fixtures, ?object $vessel = null): CrewAssignment
{
    $service = app(CrewMovementService::class);
    $vessel ??= makeCrewMovementVessel('Schedule Vessel');

    $assignment = $service->createDraft($fixtures['company']->id, $fixtures['employee']->id, [
        'position_id' => $fixtures['rank']->id,
        'vessel_id' => $vessel->id,
    ], $fixtures['user']->id);

    $id = $assignment->id;
    $service->perform($fixtures['company']->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2027-01-08 08:00:00',
    ], $fixtures['user']->id);
    $service->perform($fixtures['company']->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2027-01-10 08:00:00',
        'next_phase' => CrewPhaseCode::JoinStandby->value,
        'accommodation_status' => 'no_accommodation',
    ], $fixtures['user']->id);

    return $assignment->fresh(['currentPhase']);
}

test('scheduling join vessel does not change actual phase or sea service', function () {
    $fixtures = makeCrewScheduledMovementFixtures();
    $assignment = advanceJoinStandbyAssignment($fixtures);
    $phaseBefore = $assignment->currentPhase?->phase_code;
    $seaBefore = EmployeeSeaService::query()->where('company_id', $fixtures['company']->id)->count();

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('organization.crew-assignments.scheduled-movements.store', $assignment), [
            'action' => CrewMovementAction::JoinVessel->value,
            'mode' => 'schedule_later',
            'scheduled_at' => '2027-01-20 09:00:00',
            'vessel_id' => $assignment->vessel_id,
            'position_id' => $assignment->position_id,
            'planned_signoff_choice' => 'manual_override',
            'planned_signoff_at' => '2027-03-20',
            'planned_signoff_override_reason' => 'Scheduled join demo',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment))
        ->assertSessionHas('success');

    $assignment->refresh()->load('currentPhase');
    $schedule = CrewScheduledMovement::query()
        ->where('crew_assignment_id', $assignment->id)
        ->first();

    expect($assignment->currentPhase?->phase_code)->toBe($phaseBefore)
        ->and($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and(EmployeeSeaService::query()->where('company_id', $fixtures['company']->id)->count())->toBe($seaBefore)
        ->and($schedule)->not->toBeNull()
        ->and($schedule->status)->toBe(CrewScheduledMovementStatus::Scheduled)
        ->and($schedule->movement_action)->toBe(CrewMovementAction::JoinVessel);
});

test('due scheduled join vessel executes once and advances to on vessel', function () {
    $fixtures = makeCrewScheduledMovementFixtures();
    $assignment = advanceJoinStandbyAssignment($fixtures);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('organization.crew-assignments.scheduled-movements.store', $assignment), [
            'action' => CrewMovementAction::JoinVessel->value,
            'mode' => 'schedule_later',
            'scheduled_at' => '2027-01-20 09:00:00',
            'vessel_id' => $assignment->vessel_id,
            'position_id' => $assignment->position_id,
            'planned_signoff_choice' => 'manual_override',
            'planned_signoff_at' => '2027-03-20',
            'planned_signoff_override_reason' => 'Scheduled join demo',
        ])
        ->assertRedirect();

    $schedule = CrewScheduledMovement::query()->where('crew_assignment_id', $assignment->id)->firstOrFail();

    Carbon::setTestNow(Carbon::parse('2027-01-20 09:00:00', 'Asia/Dubai'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-01-20 09:00:00', 'Asia/Dubai'));

    $first = app(ProcessDueCrewScheduledMovements::class)->handle(25, Carbon::now('UTC'));
    $second = app(ProcessDueCrewScheduledMovements::class)->handle(25, Carbon::now('UTC'));

    $schedule->refresh();
    $assignment->refresh()->load('currentPhase');

    expect($first['executed'])->toBe(1)
        ->and($second['executed'])->toBe(0)
        ->and($schedule->status)->toBe(CrewScheduledMovementStatus::Executed)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel)
        ->and($schedule->execution_attempts)->toBe(1);
});

test('cancellation prevents automatic execution', function () {
    $fixtures = makeCrewScheduledMovementFixtures();
    $assignment = advanceJoinStandbyAssignment($fixtures);

    $schedule = app(ScheduleCrewMovement::class)->handle(
        $fixtures['company']->id,
        $assignment,
        CrewMovementAction::JoinVessel,
        [
            'action' => CrewMovementAction::JoinVessel->value,
            'scheduled_at' => '2027-01-20 09:00:00',
            'vessel_id' => $assignment->vessel_id,
            'position_id' => $assignment->position_id,
            'planned_signoff_choice' => 'manual_override',
            'planned_signoff_at' => '2027-03-20',
            'planned_signoff_override_reason' => 'Will cancel',
        ],
        $fixtures['user'],
    );

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('organization.crew-scheduled-movements.cancel', $schedule), [
            'reason' => 'Plans changed',
        ])
        ->assertRedirect();

    Carbon::setTestNow(Carbon::parse('2027-01-20 09:05:00', 'Asia/Dubai'));
    app(ProcessDueCrewScheduledMovements::class)->handle(25, Carbon::now('UTC'));

    $schedule->refresh();
    $assignment->refresh()->load('currentPhase');

    expect($schedule->status)->toBe(CrewScheduledMovementStatus::Cancelled)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby);
});

test('second unresolved schedule is rejected', function () {
    $fixtures = makeCrewScheduledMovementFixtures();
    $assignment = advanceJoinStandbyAssignment($fixtures);

    app(ScheduleCrewMovement::class)->handle(
        $fixtures['company']->id,
        $assignment,
        CrewMovementAction::JoinVessel,
        [
            'action' => CrewMovementAction::JoinVessel->value,
            'scheduled_at' => '2027-01-20 09:00:00',
            'vessel_id' => $assignment->vessel_id,
            'position_id' => $assignment->position_id,
            'planned_signoff_choice' => 'manual_override',
            'planned_signoff_at' => '2027-03-20',
            'planned_signoff_override_reason' => 'First',
        ],
        $fixtures['user'],
    );

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('organization.crew-assignments.scheduled-movements.store', $assignment), [
            'action' => CrewMovementAction::SendToTraining->value,
            'mode' => 'schedule_later',
            'scheduled_at' => '2027-01-18 09:00:00',
            'provider' => 'Academy',
            'course' => 'STCW',
        ])
        ->assertSessionHasErrors('scheduled_at');

    expect(CrewScheduledMovement::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(1);
});

test('manual join before due schedule marks needs attention and does not duplicate phase', function () {
    $fixtures = makeCrewScheduledMovementFixtures();
    $assignment = advanceJoinStandbyAssignment($fixtures);
    $service = app(CrewMovementService::class);

    app(ScheduleCrewMovement::class)->handle(
        $fixtures['company']->id,
        $assignment,
        CrewMovementAction::JoinVessel,
        [
            'action' => CrewMovementAction::JoinVessel->value,
            'scheduled_at' => '2027-01-20 09:00:00',
            'vessel_id' => $assignment->vessel_id,
            'position_id' => $assignment->position_id,
            'planned_signoff_choice' => 'manual_override',
            'planned_signoff_at' => '2027-03-20',
            'planned_signoff_override_reason' => 'Manual will win',
        ],
        $fixtures['user'],
    );

    $service->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2027-01-15 09:00:00',
        'vessel_id' => $assignment->vessel_id,
        'position_id' => $assignment->position_id,
        'planned_signoff_choice' => 'manual_override',
        'planned_signoff_at' => '2027-03-20',
        'planned_signoff_override_reason' => 'Manual early join',
    ], $fixtures['user']->id);

    Carbon::setTestNow(Carbon::parse('2027-01-20 09:00:00', 'Asia/Dubai'));
    app(ProcessDueCrewScheduledMovements::class)->handle(25, Carbon::now('UTC'));

    $schedule = CrewScheduledMovement::query()->where('crew_assignment_id', $assignment->id)->firstOrFail();
    $p4Count = $assignment->phases()->where('phase_code', CrewPhaseCode::OnVessel)->count();

    expect($schedule->status)->toBe(CrewScheduledMovementStatus::NeedsAttention)
        ->and($p4Count)->toBe(1);
});

test('lateness beyond tolerance marks needs attention without executing', function () {
    $fixtures = makeCrewScheduledMovementFixtures();
    $assignment = advanceJoinStandbyAssignment($fixtures);

    app(ScheduleCrewMovement::class)->handle(
        $fixtures['company']->id,
        $assignment,
        CrewMovementAction::JoinVessel,
        [
            'action' => CrewMovementAction::JoinVessel->value,
            'scheduled_at' => '2027-01-20 09:00:00',
            'vessel_id' => $assignment->vessel_id,
            'position_id' => $assignment->position_id,
            'planned_signoff_choice' => 'manual_override',
            'planned_signoff_at' => '2027-03-20',
            'planned_signoff_override_reason' => 'Late processor',
        ],
        $fixtures['user'],
    );

    Carbon::setTestNow(Carbon::parse('2027-01-20 09:20:00', 'Asia/Dubai'));
    app(ProcessDueCrewScheduledMovements::class)->handle(25, Carbon::now('UTC'));

    $schedule = CrewScheduledMovement::query()->where('crew_assignment_id', $assignment->id)->firstOrFail();
    $assignment->refresh()->load('currentPhase');

    expect($schedule->status)->toBe(CrewScheduledMovementStatus::NeedsAttention)
        ->and($schedule->last_error_code)->toBe('lateness_exceeded')
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby);
});

test('users without schedule permission cannot create schedules', function () {
    $fixtures = makeCrewScheduledMovementFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.assignments.view',
        'crew_operations.movements.perform',
    ]);
    $assignment = advanceJoinStandbyAssignment($fixtures);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('organization.crew-assignments.scheduled-movements.store', $assignment), [
            'action' => CrewMovementAction::JoinVessel->value,
            'mode' => 'schedule_later',
            'scheduled_at' => '2027-01-20 09:00:00',
            'vessel_id' => $assignment->vessel_id,
            'position_id' => $assignment->position_id,
            'planned_signoff_choice' => 'manual_override',
            'planned_signoff_at' => '2027-03-20',
            'planned_signoff_override_reason' => 'Denied',
        ])
        ->assertForbidden();
});

test('schedule index requires view permission and is company scoped', function () {
    $fixtures = makeCrewScheduledMovementFixtures();

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('organization.crew-scheduled-movements.index'))
        ->assertOk();

    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.assignments.view',
    ]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->get(route('organization.crew-scheduled-movements.index'))
        ->assertForbidden();
});

test('artisan process command is registered and recovers safely', function () {
    $exit = Artisan::call('crew:process-scheduled-movements', ['--limit' => 5]);

    expect($exit)->toBe(0);
});

test('record now still rejects future dates when override is off', function () {
    $fixtures = makeCrewScheduledMovementFixtures();
    $assignment = advanceJoinStandbyAssignment($fixtures);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::JoinVessel->value,
            'occurred_at' => '2027-01-20 09:00:00',
            'vessel_id' => $assignment->vessel_id,
            'position_id' => $assignment->position_id,
            'planned_signoff_choice' => 'manual_override',
            'planned_signoff_at' => '2027-03-20',
            'planned_signoff_override_reason' => 'Future record now',
        ])
        ->assertSessionHasErrors('occurred_at');
});

test('demo lifecycle schedule then execute arrival join disembark return home', function () {
    $fixtures = makeCrewScheduledMovementFixtures();
    $service = app(CrewMovementService::class);
    $vessel = makeCrewMovementVessel('Demo Lifecycle Vessel');
    $processor = app(ProcessDueCrewScheduledMovements::class);

    $setNow = function (string $local): void {
        Carbon::setTestNow(Carbon::parse($local, 'Asia/Dubai'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse($local, 'Asia/Dubai'));
    };

    $setNow('2027-01-08 08:00:00');

    $assignment = $service->createDraft($fixtures['company']->id, $fixtures['employee']->id, [
        'position_id' => $fixtures['rank']->id,
        'vessel_id' => $vessel->id,
    ], $fixtures['user']->id);

    $service->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2027-01-08 08:00:00',
    ], $fixtures['user']->id);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('organization.crew-assignments.scheduled-movements.store', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'mode' => 'schedule_later',
            'scheduled_at' => '2027-01-10 08:00:00',
            'next_phase' => CrewPhaseCode::JoinStandby->value,
            'accommodation_status' => 'no_accommodation',
        ])
        ->assertSessionDoesntHaveErrors()
        ->assertRedirect();

    expect($assignment->fresh()->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreMobilisation)
        ->and(CrewScheduledMovement::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(1);

    $setNow('2027-01-10 08:00:00');
    $result = $processor->handle(25, Carbon::now('UTC'));
    expect($result['executed'])->toBe(1)
        ->and($assignment->fresh()->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('organization.crew-assignments.scheduled-movements.store', $assignment->fresh()), [
            'action' => CrewMovementAction::JoinVessel->value,
            'mode' => 'schedule_later',
            'scheduled_at' => '2027-01-20 09:00:00',
            'vessel_id' => $vessel->id,
            'position_id' => $fixtures['rank']->id,
            'planned_signoff_choice' => 'manual_override',
            'planned_signoff_at' => '2027-01-30',
            'planned_signoff_override_reason' => 'Demo signoff',
        ])
        ->assertRedirect();

    expect($assignment->fresh()->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby);

    $setNow('2027-01-20 09:00:00');
    $processor->handle(25, Carbon::now('UTC'));
    expect($assignment->fresh()->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('organization.crew-assignments.scheduled-movements.store', $assignment->fresh()), [
            'action' => CrewMovementAction::ConfirmDisembarkation->value,
            'mode' => 'schedule_later',
            'scheduled_at' => '2027-01-30 09:00:00',
            'next_phase' => CrewPhaseCode::DemobStandby->value,
            'accommodation_status' => 'no_accommodation',
        ])
        ->assertRedirect();

    $setNow('2027-01-30 09:00:00');
    $processor->handle(25, Carbon::now('UTC'));
    expect($assignment->fresh()->currentPhase?->phase_code)->toBe(CrewPhaseCode::DemobStandby);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('organization.crew-assignments.scheduled-movements.store', $assignment->fresh()), [
            'action' => CrewMovementAction::TravelHome->value,
            'mode' => 'schedule_later',
            'scheduled_at' => '2027-02-02 09:00:00',
            'completion_intent' => 'close',
        ])
        ->assertRedirect();

    $setNow('2027-02-02 09:00:00');
    $processor->handle(25, Carbon::now('UTC'));

    $final = $assignment->fresh(['currentPhase']);
    expect($final->status)->toBe(CrewAssignmentStatus::Completed)
        ->and($final->closed_at)->not->toBeNull();
});
