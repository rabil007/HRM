<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementAction;
use App\Enums\CrewOperationalAlertType;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewScheduledMovementStatus;
use App\Exceptions\CrewMovementException;
use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\CrewScheduledMovement;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\Position;
use App\Models\User;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\CrewMovements\Scheduling\CancelCrewScheduledMovement;
use App\Support\CrewMovements\Scheduling\CrewScheduledMovementIndexQuery;
use App\Support\CrewMovements\Scheduling\CrewScheduledMovementPresenter;
use App\Support\CrewMovements\Scheduling\CrewScheduledMovementTimestamp;
use App\Support\CrewMovements\Scheduling\ExecuteCrewScheduledMovement;
use App\Support\CrewMovements\Scheduling\ProcessDueCrewScheduledMovements;
use App\Support\CrewMovements\Scheduling\ScheduleCrewMovement;
use App\Support\CrewOperations\CrewOperationsSettings;
use App\Support\CrewOperations\DetectCrewOperationalAlerts;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

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

function advanceOnVesselAssignment(array $fixtures, ?object $vessel = null): CrewAssignment
{
    $assignment = advanceJoinStandbyAssignment($fixtures, $vessel);
    $service = app(CrewMovementService::class);

    // Clock is frozen at 2027-01-15 — keep actual occurred_at in the past.
    $service->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2027-01-12 09:00:00',
        'vessel_id' => $assignment->vessel_id,
        'position_id' => $assignment->position_id,
        'planned_signoff_choice' => 'manual_override',
        'planned_signoff_at' => '2027-03-20',
        'planned_signoff_override_reason' => 'Fixture signoff',
    ], $fixtures['user']->id);

    return $assignment->fresh(['currentPhase']);
}

function advanceDemobStandbyAssignment(array $fixtures, ?object $vessel = null): CrewAssignment
{
    $assignment = advanceOnVesselAssignment($fixtures, $vessel);
    $service = app(CrewMovementService::class);

    $service->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2027-01-14 09:00:00',
        'next_phase' => CrewPhaseCode::DemobStandby->value,
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

test('scheduled_at is stored as utc while display stays company local for dubai', function () {
    $fixtures = makeCrewScheduledMovementFixtures();
    $fixtures['company']->update(['timezone' => 'Asia/Dubai']);
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
            'planned_signoff_override_reason' => 'UTC store dubai',
        ],
        $fixtures['user'],
    );

    $schedule = CrewScheduledMovement::query()->where('crew_assignment_id', $assignment->id)->firstOrFail();
    $raw = DB::table('crew_scheduled_movements')->where('id', $schedule->id)->value('scheduled_at');

    expect((string) $raw)->toStartWith('2027-01-20 05:00:00')
        ->and($schedule->scheduled_at?->timezone('UTC')->format('Y-m-d H:i:s'))->toBe('2027-01-20 05:00:00')
        ->and($schedule->scheduled_timezone)->toBe('Asia/Dubai');

    $card = app(CrewScheduledMovementPresenter::class)
        ->card($schedule, 'Asia/Dubai', $fixtures['user']);

    expect($card['scheduled_at'])->toBe('2027-01-20 09:00:00');
});

test('utc company timezone stores local wall as identical utc digits', function () {
    $fixtures = makeCrewScheduledMovementFixtures();
    $fixtures['company']->update(['timezone' => 'UTC']);
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
            'planned_signoff_override_reason' => 'UTC company',
        ],
        $fixtures['user'],
    );

    $schedule = CrewScheduledMovement::query()->where('crew_assignment_id', $assignment->id)->firstOrFail();
    $raw = DB::table('crew_scheduled_movements')->where('id', $schedule->id)->value('scheduled_at');

    expect((string) $raw)->toStartWith('2027-01-20 09:00:00');
});

test('america new york timezone behind utc stores offset correctly and executes when due', function () {
    $fixtures = makeCrewScheduledMovementFixtures();
    $fixtures['company']->update(['timezone' => 'America/New_York']);
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
            'planned_signoff_override_reason' => 'NY schedule',
        ],
        $fixtures['user'],
    );

    $schedule = CrewScheduledMovement::query()->where('crew_assignment_id', $assignment->id)->firstOrFail();
    $raw = DB::table('crew_scheduled_movements')->where('id', $schedule->id)->value('scheduled_at');

    // America/New_York in January is UTC-5 → 09:00 local = 14:00 UTC
    expect((string) $raw)->toStartWith('2027-01-20 14:00:00');

    Carbon::setTestNow(Carbon::parse('2027-01-20 09:00:00', 'America/New_York'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-01-20 09:00:00', 'America/New_York'));

    $result = app(ProcessDueCrewScheduledMovements::class)->handle(25, Carbon::now('UTC'));
    $schedule->refresh();
    $assignment->refresh()->load('currentPhase');

    expect($result['executed'])->toBe(1)
        ->and($schedule->status)->toBe(CrewScheduledMovementStatus::Executed)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel);
});

test('update rejects unknown action_fields and preserves hotel manual override', function () {
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
            'planned_signoff_override_reason' => 'Edit fields',
            'check_out_date' => '2027-01-19',
            'check_out_date_auto_synced' => false,
        ],
        $fixtures['user'],
    );

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->put(route('organization.crew-scheduled-movements.update', $schedule), [
            'scheduled_at' => '2027-01-21 10:00:00',
            'action_fields' => [
                'vessel_id' => $assignment->vessel_id,
                'position_id' => $assignment->position_id,
                'planned_signoff_choice' => 'manual_override',
                'planned_signoff_at' => '2027-03-20',
                'planned_signoff_override_reason' => 'Edit fields',
                'not_a_real_field' => 'nope',
            ],
        ])
        ->assertSessionHasErrors('action_fields');

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->put(route('organization.crew-scheduled-movements.update', $schedule), [
            'scheduled_at' => '2027-01-21 10:00:00',
            'action_fields' => [
                'vessel_id' => $assignment->vessel_id,
                'position_id' => $assignment->position_id,
                'planned_signoff_choice' => 'manual_override',
                'planned_signoff_at' => '2027-03-20',
                'planned_signoff_override_reason' => 'Edit fields',
                'check_out_date' => '2027-01-19',
                'check_out_date_auto_synced' => false,
            ],
        ])
        ->assertRedirect();

    $schedule->refresh();
    expect($schedule->action_payload['check_out_date'] ?? null)->toBe('2027-01-19')
        ->and($schedule->scheduled_at?->timezone('UTC')->format('Y-m-d H:i:s'))->toBe('2027-01-21 06:00:00');
});

test('stale processing recovery uses utc processing_started_at', function () {
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
            'planned_signoff_override_reason' => 'Stale',
        ],
        $fixtures['user'],
    );

    $schedule->update([
        'status' => CrewScheduledMovementStatus::Processing,
        'processing_started_at' => '2027-01-20 04:50:00',
        'execution_attempts' => 1,
    ]);

    Carbon::setTestNow(Carbon::parse('2027-01-20 05:00:00', 'UTC'));
    $recovered = app(ExecuteCrewScheduledMovement::class)
        ->recoverStaleProcessing(Carbon::now('UTC'), 300);

    $schedule->refresh();

    expect($recovered)->toBe(1)
        ->and($schedule->status)->toBe(CrewScheduledMovementStatus::NeedsAttention)
        ->and($schedule->last_error_code)->toBe('stale_processing');
});

test('needs attention schedules are detected for operational alerts without duplicates', function () {
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
            'planned_signoff_override_reason' => 'Alert',
        ],
        $fixtures['user'],
    );

    $schedule->update([
        'status' => CrewScheduledMovementStatus::NeedsAttention,
        'last_error_code' => 'lateness_exceeded',
        'last_error_message' => 'Delayed beyond tolerance.',
    ]);

    CrewOperationsSettings::saveSettings($fixtures['company']->id, [], 30, true, [
        'notifications_enabled' => true,
        'notification_recipient_user_ids' => [$fixtures['user']->id],
        'alert_scheduled_movement_needs_attention' => true,
    ]);

    $detector = app(DetectCrewOperationalAlerts::class);
    $types = [CrewOperationalAlertType::ScheduledMovementNeedsAttention];
    $first = $detector->forCompany($fixtures['company']->id, $types);
    $second = $detector->forCompany($fixtures['company']->id, $types);

    expect($first)->toHaveCount(1)
        ->and($second)->toHaveCount(1)
        ->and($first[0]['dedupe_key'])->toBe('scheduled_movement_needs_attention:schedule:'.$schedule->id)
        ->and($first[0]['context']['employee_id'])->toBe($fixtures['employee']->id)
        ->and($first[0]['dedupe_key'])->toBe($second[0]['dedupe_key']);
});

test('concurrent claim executes only once', function () {
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
            'planned_signoff_override_reason' => 'Concurrent',
        ],
        $fixtures['user'],
    );

    Carbon::setTestNow(Carbon::parse('2027-01-20 09:00:00', 'Asia/Dubai'));
    $executor = app(ExecuteCrewScheduledMovement::class);
    $now = Carbon::now('UTC');

    $firstClaim = $executor->claim($schedule->id, $now);
    $secondClaim = $executor->claim($schedule->id, $now);

    expect($firstClaim)->not->toBeNull()
        ->and($secondClaim)->toBeNull();

    $executor->execute($firstClaim, $now);
    $secondRun = app(ProcessDueCrewScheduledMovements::class)->handle(25, $now);

    expect($secondRun['executed'])->toBe(0)
        ->and($assignment->fresh()->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel)
        ->and(EmployeeSeaService::query()->where('company_id', $fixtures['company']->id)->count())->toBeGreaterThan(0);
});

test('cancelled schedule is not due and sea service untouched before execution', function () {
    $fixtures = makeCrewScheduledMovementFixtures();
    $assignment = advanceJoinStandbyAssignment($fixtures);
    $seaBefore = EmployeeSeaService::query()->where('company_id', $fixtures['company']->id)->count();

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
            'planned_signoff_override_reason' => 'Cancel before due',
        ],
        $fixtures['user'],
    );

    app(CancelCrewScheduledMovement::class)->handle(
        $fixtures['company']->id,
        $schedule,
        $fixtures['user'],
        'Operator cancelled',
    );

    Carbon::setTestNow(Carbon::parse('2027-01-20 09:05:00', 'Asia/Dubai'));
    $result = app(ProcessDueCrewScheduledMovements::class)->handle(25, Carbon::now('UTC'));

    expect($result['executed'])->toBe(0)
        ->and($schedule->fresh()->status)->toBe(CrewScheduledMovementStatus::Cancelled)
        ->and(EmployeeSeaService::query()->where('company_id', $fixtures['company']->id)->count())->toBe($seaBefore)
        ->and($assignment->fresh()->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby);
});

test('uae 09:00 schedule executes with company-local 09:00 occurred wall', function () {
    $fixtures = makeCrewScheduledMovementFixtures();
    $fixtures['company']->update(['timezone' => 'Asia/Dubai']);
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
            'planned_signoff_override_reason' => 'UAE exact',
        ],
        $fixtures['user'],
    );

    Carbon::setTestNow(Carbon::parse('2027-01-20 09:00:00', 'Asia/Dubai'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-01-20 09:00:00', 'Asia/Dubai'));

    $result = app(ProcessDueCrewScheduledMovements::class)->handle(25, Carbon::now('UTC'));
    $schedule = CrewScheduledMovement::query()->where('crew_assignment_id', $assignment->id)->firstOrFail();
    $phase = $assignment->fresh(['currentPhase'])->currentPhase;

    expect($result['executed'])->toBe(1)
        ->and($schedule->status)->toBe(CrewScheduledMovementStatus::Executed)
        ->and($schedule->scheduled_at?->utc()->format('Y-m-d H:i:s'))->toBe('2027-01-20 05:00:00')
        ->and($schedule->effective_occurred_at?->utc()->format('Y-m-d H:i:s'))->toBe('2027-01-20 05:00:00')
        ->and($schedule->effective_occurred_at?->timezone('Asia/Dubai')->format('Y-m-d H:i:s'))->toBe('2027-01-20 09:00:00')
        ->and($phase?->phase_code)->toBe(CrewPhaseCode::OnVessel)
        ->and($phase?->actual_start_at?->format('Y-m-d H:i:s'))->toBe('2027-01-20 09:00:00');
});

test('index due and upcoming counts use utc instants for america new york', function () {
    $fixtures = makeCrewScheduledMovementFixtures();
    $fixtures['company']->update(['timezone' => 'America/New_York']);
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
            'planned_signoff_override_reason' => 'Index counts',
        ],
        $fixtures['user'],
    );

    Carbon::setTestNow(Carbon::parse('2027-01-20 08:59:00', 'America/New_York'));
    $before = app(CrewScheduledMovementIndexQuery::class)
        ->paginate($fixtures['company']->id, $fixtures['user'], ['tab' => 'upcoming']);

    expect($before['counts']['upcoming'])->toBe(1)
        ->and($before['counts']['due'])->toBe(0);

    Carbon::setTestNow(Carbon::parse('2027-01-20 09:00:00', 'America/New_York'));
    $after = app(CrewScheduledMovementIndexQuery::class)
        ->paginate($fixtures['company']->id, $fixtures['user'], ['tab' => 'due']);

    expect($after['counts']['upcoming'])->toBe(0)
        ->and($after['counts']['due'])->toBe(1);
});

test('cancel is blocked while schedule is processing', function () {
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
            'planned_signoff_override_reason' => 'Processing cancel',
        ],
        $fixtures['user'],
    );

    $schedule->update([
        'status' => CrewScheduledMovementStatus::Processing,
        'processing_started_at' => '2027-01-20 05:00:00',
    ]);

    expect(fn () => app(CancelCrewScheduledMovement::class)->handle(
        $fixtures['company']->id,
        $schedule->fresh(),
        $fixtures['user'],
        'race',
    ))->toThrow(CrewMovementException::class);

    expect($schedule->fresh()->status)->toBe(CrewScheduledMovementStatus::Processing);
});

test('unexpected infrastructure failures store generic operator message without sql', function () {
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
            'planned_signoff_override_reason' => 'Infra fail',
        ],
        $fixtures['user'],
    );

    Carbon::setTestNow(Carbon::parse('2027-01-20 09:00:00', 'Asia/Dubai'));
    $executor = app(ExecuteCrewScheduledMovement::class);
    $claimed = $executor->claim($schedule->id, Carbon::now('UTC'));

    // Corrupt the enum cast path so hydration inside execute() throws unexpectedly.
    DB::table('crew_scheduled_movements')->where('id', $claimed->id)->update([
        'movement_action' => 'not_a_real_action',
    ]);

    $result = $executor->execute($claimed, Carbon::now('UTC'));

    expect($result->status)->toBe(CrewScheduledMovementStatus::NeedsAttention)
        ->and($result->last_error_message)->not->toContain('SQLSTATE')
        ->and($result->last_error_message)->not->toContain('not_a_real_action')
        ->and($result->last_error_code)->toBe('movement_failed')
        ->and($assignment->fresh()->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby);

    $sanitize = new ReflectionMethod(ExecuteCrewScheduledMovement::class, 'sanitizeMessage');
    $clean = $sanitize->invoke(
        $executor,
        'Automatic execution failed: SQLSTATE[HY000] Connection: /secret/path',
    );
    expect($clean)->not->toContain('SQLSTATE')
        ->and($clean)->not->toContain('/secret/path');
});

test('reschedule with auto synced checkout follows new date and keeps join standby', function () {
    $fixtures = makeCrewScheduledMovementFixtures();
    $assignment = advanceJoinStandbyAssignment($fixtures);
    $seaBefore = EmployeeSeaService::query()->where('company_id', $fixtures['company']->id)->count();

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
            'planned_signoff_override_reason' => 'Auto sync',
            'check_out_date' => '2027-01-20',
            'check_out_date_auto_synced' => true,
        ],
        $fixtures['user'],
    );

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->put(route('organization.crew-scheduled-movements.update', $schedule), [
            'scheduled_at' => '2027-01-21 10:00:00',
            'action_fields' => [
                'vessel_id' => $assignment->vessel_id,
                'position_id' => $assignment->position_id,
                'planned_signoff_choice' => 'manual_override',
                'planned_signoff_at' => '2027-03-20',
                'planned_signoff_override_reason' => 'Auto sync',
                'check_out_date' => '2027-01-20',
                'check_out_date_auto_synced' => true,
            ],
        ])
        ->assertRedirect();

    $schedule->refresh();
    $assignment->refresh()->load('currentPhase');

    expect($schedule->action_payload['check_out_date'] ?? null)->toBe('2027-01-21')
        ->and($schedule->status)->toBe(CrewScheduledMovementStatus::Scheduled)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby)
        ->and(EmployeeSeaService::query()->where('company_id', $fixtures['company']->id)->count())->toBe($seaBefore);
});

test('needs attention alert clears after successful reschedule', function () {
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
            'planned_signoff_override_reason' => 'Alert clear',
        ],
        $fixtures['user'],
    );

    $schedule->update([
        'status' => CrewScheduledMovementStatus::NeedsAttention,
        'last_error_code' => 'lateness_exceeded',
        'last_error_message' => 'Delayed beyond tolerance.',
    ]);

    CrewOperationsSettings::saveSettings($fixtures['company']->id, [], 30, true, [
        'notifications_enabled' => true,
        'notification_recipient_user_ids' => [$fixtures['user']->id],
        'alert_scheduled_movement_needs_attention' => true,
    ]);

    $detector = app(DetectCrewOperationalAlerts::class);
    $types = [CrewOperationalAlertType::ScheduledMovementNeedsAttention];
    expect($detector->forCompany($fixtures['company']->id, $types))->toHaveCount(1);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->put(route('organization.crew-scheduled-movements.update', $schedule), [
            'scheduled_at' => '2027-01-25 09:00:00',
        ])
        ->assertRedirect();

    expect($schedule->fresh()->status)->toBe(CrewScheduledMovementStatus::Scheduled)
        ->and($schedule->fresh()->last_error_code)->toBeNull()
        ->and($detector->forCompany($fixtures['company']->id, $types))->toHaveCount(0);
});

test('cross company user cannot update another company schedule', function () {
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
            'planned_signoff_override_reason' => 'Cross company',
        ],
        $fixtures['user'],
    );

    $other = makeCrewAssignmentFixtures();
    grantCompanyPermissions($other['user'], $other['company'], [
        'crew_operations.movements.schedule.manage',
        'crew_operations.movements.schedule.view',
        'crew_operations.assignments.view',
    ]);
    $other['user']->update(['current_company_id' => $other['company']->id]);

    $this->actingAs($other['user'])
        ->withSession(['current_company_id' => $other['company']->id])
        ->put(route('organization.crew-scheduled-movements.update', $schedule), [
            'scheduled_at' => '2027-01-25 09:00:00',
        ])
        ->assertNotFound();

    expect($schedule->fresh()->scheduled_at?->utc()->format('Y-m-d H:i:s'))->toBe('2027-01-20 05:00:00');
});

test('dst nonexistent and ambiguous local walls are rejected', function () {
    expect(fn () => CrewScheduledMovementTimestamp::fromCompanyLocal(
        '2026-03-08 02:30:00',
        'America/New_York',
    ))->toThrow(CrewMovementException::class);

    expect(fn () => CrewScheduledMovementTimestamp::fromCompanyLocal(
        '2026-11-01 01:30:00',
        'America/New_York',
    ))->toThrow(CrewMovementException::class);
});

test('edit join vessel schedule persists only permitted fields and leaves phase and sea service unchanged', function () {
    $fixtures = makeCrewScheduledMovementFixtures();
    $assignment = advanceJoinStandbyAssignment($fixtures);
    $phaseBefore = $assignment->currentPhase?->phase_code;
    $seaBefore = EmployeeSeaService::query()->where('company_id', $fixtures['company']->id)->count();

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
            'planned_signoff_override_reason' => 'Original',
            'check_out_date' => '2027-01-19',
            'check_out_date_auto_synced' => false,
        ],
        $fixtures['user'],
    );

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->put(route('organization.crew-scheduled-movements.update', $schedule), [
            'scheduled_at' => '2027-01-22 11:00:00',
            'action_fields' => [
                'vessel_id' => $assignment->vessel_id,
                'position_id' => $assignment->position_id,
                'client_id' => null,
                'planned_signoff_choice' => 'manual_override',
                'planned_signoff_at' => '2027-04-01',
                'planned_signoff_override_reason' => 'Rescheduled join',
                'check_out_date' => '2027-01-19',
                'check_out_date_auto_synced' => false,
                'remarks' => 'Updated via edit dialog',
            ],
            'check_out_date_auto_synced' => false,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $schedule->refresh();
    $assignment->refresh()->load('currentPhase');
    $payload = $schedule->action_payload;

    expect($assignment->currentPhase?->phase_code)->toBe($phaseBefore)
        ->and($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and(EmployeeSeaService::query()->where('company_id', $fixtures['company']->id)->count())->toBe($seaBefore)
        ->and($schedule->status)->toBe(CrewScheduledMovementStatus::Scheduled)
        ->and($payload['planned_signoff_at'] ?? null)->toBe('2027-04-01')
        ->and($payload['check_out_date'] ?? null)->toBe('2027-01-19')
        ->and($payload['check_out_date_auto_synced'] ?? null)->toBeFalse()
        ->and($payload)->not->toHaveKey('provider')
        ->and($payload)->not->toHaveKey('planned_start_at')
        ->and($payload)->not->toHaveKey('reason')
        ->and($payload)->not->toHaveKey('accommodation_status')
        ->and($schedule->scheduled_at?->timezone('UTC')->format('Y-m-d H:i:s'))->toBe('2027-01-22 07:00:00');
});

test('edit send to training schedule allows planned dates and does not advance phase', function () {
    $fixtures = makeCrewScheduledMovementFixtures();
    $assignment = advanceJoinStandbyAssignment($fixtures);
    $phaseBefore = $assignment->currentPhase?->phase_code;

    $schedule = app(ScheduleCrewMovement::class)->handle(
        $fixtures['company']->id,
        $assignment,
        CrewMovementAction::SendToTraining,
        [
            'action' => CrewMovementAction::SendToTraining->value,
            'scheduled_at' => '2027-01-18 09:00:00',
            'provider' => 'Academy',
            'course' => 'STCW',
            'planned_start_at' => '2027-01-18 09:00:00',
            'planned_end_at' => '2027-01-25',
        ],
        $fixtures['user'],
    );

    expect($schedule->action_payload['planned_start_at'] ?? null)->toBe('2027-01-18 09:00:00')
        ->and($schedule->action_payload['planned_end_at'] ?? null)->toBe('2027-01-25');

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->put(route('organization.crew-scheduled-movements.update', $schedule), [
            'scheduled_at' => '2027-01-19 10:00:00',
            'action_fields' => [
                'provider' => 'Updated Academy',
                'course' => 'Advanced STCW',
                'planned_start_at' => '2027-01-19 10:00:00',
                'planned_end_at' => '2027-01-28',
                'remarks' => 'Training window moved',
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $schedule->refresh();
    $assignment->refresh()->load('currentPhase');

    expect($assignment->currentPhase?->phase_code)->toBe($phaseBefore)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby)
        ->and($schedule->action_payload['provider'] ?? null)->toBe('Updated Academy')
        ->and($schedule->action_payload['course'] ?? null)->toBe('Advanced STCW')
        ->and($schedule->action_payload['planned_start_at'] ?? null)->toBe('2027-01-19 10:00:00')
        ->and($schedule->action_payload['planned_end_at'] ?? null)->toBe('2027-01-28')
        ->and($schedule->action_payload)->not->toHaveKey('vessel_id')
        ->and($schedule->action_payload)->not->toHaveKey('check_out_date');
});

test('edit confirm disembarkation schedule preserves phase until execution', function () {
    $fixtures = makeCrewScheduledMovementFixtures();
    $assignment = advanceOnVesselAssignment($fixtures);
    $phaseBefore = $assignment->currentPhase?->phase_code;
    $seaBefore = EmployeeSeaService::query()->where('company_id', $fixtures['company']->id)->count();

    $schedule = app(ScheduleCrewMovement::class)->handle(
        $fixtures['company']->id,
        $assignment,
        CrewMovementAction::ConfirmDisembarkation,
        [
            'action' => CrewMovementAction::ConfirmDisembarkation->value,
            'scheduled_at' => '2027-01-30 09:00:00',
            'next_phase' => CrewPhaseCode::DemobStandby->value,
            'accommodation_status' => 'no_accommodation',
        ],
        $fixtures['user'],
    );

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->put(route('organization.crew-scheduled-movements.update', $schedule), [
            'scheduled_at' => '2027-01-31 08:00:00',
            'action_fields' => [
                'next_phase' => CrewPhaseCode::DemobStandby->value,
                'accommodation_status' => 'no_accommodation',
                'remarks' => 'Disembark moved one day',
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $schedule->refresh();
    $assignment->refresh()->load('currentPhase');

    expect($assignment->currentPhase?->phase_code)->toBe($phaseBefore)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel)
        ->and(EmployeeSeaService::query()->where('company_id', $fixtures['company']->id)->count())->toBe($seaBefore)
        ->and($schedule->action_payload['next_phase'] ?? null)->toBe(CrewPhaseCode::DemobStandby->value)
        ->and($schedule->action_payload['remarks'] ?? null)->toBe('Disembark moved one day')
        ->and($schedule->action_payload)->not->toHaveKey('completion_intent')
        ->and($schedule->scheduled_at?->timezone('UTC')->format('Y-m-d H:i:s'))->toBe('2027-01-31 04:00:00');
});

test('edit return home schedule keeps demob standby and completion intent', function () {
    $fixtures = makeCrewScheduledMovementFixtures();
    $assignment = advanceDemobStandbyAssignment($fixtures);
    $phaseBefore = $assignment->currentPhase?->phase_code;
    $statusBefore = $assignment->status;

    $schedule = app(ScheduleCrewMovement::class)->handle(
        $fixtures['company']->id,
        $assignment,
        CrewMovementAction::TravelHome,
        [
            'action' => CrewMovementAction::TravelHome->value,
            'scheduled_at' => '2027-02-02 09:00:00',
            'completion_intent' => 'close',
        ],
        $fixtures['user'],
    );

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->put(route('organization.crew-scheduled-movements.update', $schedule), [
            'scheduled_at' => '2027-02-03 10:00:00',
            'action_fields' => [
                'completion_intent' => 'close',
                'remarks' => 'Return home rescheduled',
            ],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $schedule->refresh();
    $assignment->refresh()->load('currentPhase');

    expect($assignment->currentPhase?->phase_code)->toBe($phaseBefore)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::DemobStandby)
        ->and($assignment->status)->toBe($statusBefore)
        ->and($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->closed_at)->toBeNull()
        ->and($schedule->action_payload['completion_intent'] ?? null)->toBe('close')
        ->and($schedule->action_payload['remarks'] ?? null)->toBe('Return home rescheduled')
        ->and($schedule->action_payload)->not->toHaveKey('planned_travel_at')
        ->and($schedule->scheduled_at?->timezone('UTC')->format('Y-m-d H:i:s'))->toBe('2027-02-03 06:00:00');
});

test('reschedule without action_fields preserves existing payload including hotel override', function () {
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
            'planned_signoff_override_reason' => 'Keep payload',
            'check_out_date' => '2027-01-18',
            'check_out_date_auto_synced' => false,
            'remarks' => 'Original remarks',
        ],
        $fixtures['user'],
    );

    $payloadBefore = $schedule->action_payload;

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->put(route('organization.crew-scheduled-movements.update', $schedule), [
            'scheduled_at' => '2027-01-23 09:00:00',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $schedule->refresh();
    $assignment->refresh()->load('currentPhase');

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby)
        ->and($schedule->action_payload['check_out_date'] ?? null)->toBe('2027-01-18')
        ->and($schedule->action_payload['check_out_date_auto_synced'] ?? null)->toBeFalse()
        ->and($schedule->action_payload['remarks'] ?? null)->toBe('Original remarks')
        ->and($schedule->action_payload['planned_signoff_at'] ?? null)->toBe($payloadBefore['planned_signoff_at'] ?? null)
        ->and($schedule->scheduled_at?->timezone('UTC')->format('Y-m-d H:i:s'))->toBe('2027-01-23 05:00:00');
});

test('update rejects generic form fields that are not allowlisted', function () {
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
            'planned_signoff_override_reason' => 'Reject junk',
        ],
        $fixtures['user'],
    );

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->put(route('organization.crew-scheduled-movements.update', $schedule), [
            'scheduled_at' => '2027-01-21 09:00:00',
            'action_fields' => [
                'vessel_id' => $assignment->vessel_id,
                'position_id' => $assignment->position_id,
                'planned_signoff_choice' => 'manual_override',
                'planned_signoff_at' => '2027-03-20',
                'planned_signoff_override_reason' => 'Reject junk',
                'reason' => 'cancel-only field',
                'planned_travel_at' => '2027-02-01',
                'mode' => 'schedule_later',
            ],
        ])
        ->assertSessionHasErrors('action_fields');

    expect($schedule->fresh()->action_payload)->not->toHaveKey('reason')
        ->and($schedule->fresh()->action_payload)->not->toHaveKey('planned_travel_at');
});
