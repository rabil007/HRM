<?php

use App\Enums\CrewAccommodationStatus;
use App\Enums\CrewAccommodationStayType;
use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementAction;
use App\Enums\CrewMovementCorrectionStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewScheduledMovementStatus;
use App\Models\Company;
use App\Models\Country;
use App\Models\CrewAccommodationStay;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewMovementCorrection;
use App\Models\CrewOperationsSetting;
use App\Models\CrewScheduledMovement;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\Hotel;
use App\Models\Position;
use App\Models\User;
use App\Support\CrewMovements\CrewAssignmentConflictContext;
use App\Support\CrewMovements\CrewAssignmentConflictEvaluator;
use App\Support\CrewMovements\CrewAssignmentOverlapDetector;
use App\Support\CrewMovements\CrewAssignmentVoidGuard;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\CrewMovements\Historical\HistoricalAssignmentIntervalOverlap;
use App\Support\CrewMovements\Historical\HistoricalCrewAssignmentData;
use App\Support\CrewMovements\Historical\HistoricalCrewAssignmentValidator;
use App\Support\CrewMovements\Scheduling\CrewScheduledMovementTimestamp;
use App\Support\CrewMovements\Scheduling\ExecuteCrewScheduledMovement;
use App\Support\CrewMovements\Scheduling\ProcessDueCrewScheduledMovements;
use App\Support\CrewMovements\Scheduling\ScheduleCrewMovement;
use App\Support\CrewOperations\CrewOperationsSettings;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    freezeCrewMovementTestClock();
});

afterEach(function (): void {
    restoreCrewMovementTestClock();
});

/**
 * @return array{user: User, company: Company, employee: Employee, rank: Position}
 */
function makeHardeningScheduledMovementFixtures(): array
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
        'crew_operations.assignments.void',
    ]);
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);

    return $fixtures;
}

function advanceHardeningJoinStandbyAssignment(array $fixtures, ?object $vessel = null): CrewAssignment
{
    $service = app(CrewMovementService::class);
    $vessel ??= makeCrewMovementVessel('Hardening Schedule Vessel', $fixtures['company']);

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

/**
 * @return array{user: User, company: Company}
 */
function makeHardeningFutureMovementSettingsFixtures(): array
{
    $user = User::factory()->create();
    $suffix = strtoupper(substr(uniqid(), -4));

    $country = Country::query()->create([
        'code' => 'H'.$suffix,
        'name' => 'Hardening Future Dates Land '.$suffix,
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'H'.$suffix,
        'name' => 'Hardening Future Dates Currency '.$suffix,
        'symbol' => 'H$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Hardening Future Dates Co',
        'slug' => 'hardening-future-dates-co-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    return compact('user', 'company');
}

/**
 * Completed assignment whose lifecycle started_at is later than the first actual movement.
 *
 * @return array{fixtures: array, prior: CrewAssignment}
 */
function makeHistoricalOccupancyPriorWithEarlierActual(array $fixtures): array
{
    $vessel = makeCrewMovementVessel('Historical Occupancy Vessel', $fixtures['company']);

    $prior = CrewAssignment::query()->create([
        'company_id' => $fixtures['company']->id,
        'assignment_no' => 'CA-HIST-OCC-001',
        'employee_id' => $fixtures['employee']->id,
        'position_id' => $fixtures['rank']->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Completed,
        'started_at' => CarbonImmutable::parse('2026-10-08 08:00:00', 'Asia/Dubai'),
        'closed_at' => CarbonImmutable::parse('2026-10-25 18:00:00', 'Asia/Dubai'),
        'source' => 'manual',
    ]);

    CrewAssignmentPhase::query()->create([
        'company_id' => $fixtures['company']->id,
        'crew_assignment_id' => $prior->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => CarbonImmutable::parse('2026-10-05 08:00:00', 'Asia/Dubai'),
        'actual_end_at' => CarbonImmutable::parse('2026-10-25 18:00:00', 'Asia/Dubai'),
    ]);

    return ['fixtures' => $fixtures, 'prior' => $prior->fresh(['phases'])];
}

test('operational occupancy uses earliest phase actual when earlier than started_at', function () {
    $fixtures = makeCrewAssignmentFixtures();
    ['prior' => $prior] = makeHistoricalOccupancyPriorWithEarlierActual($fixtures);

    $detector = new CrewAssignmentOverlapDetector;
    $window = $detector->operationalOccupancyDateWindow($prior, 'Asia/Dubai');

    expect($window)->not->toBeNull()
        ->and($window['start'])->toBe('2026-10-05')
        ->and($window['end'])->toBe('2026-10-25')
        ->and($detector->operationalStartDate($prior, 'Asia/Dubai'))->toBe('2026-10-05');
});

test('conflict evaluator rejects new assignment overlapping earlier historical actual movement', function () {
    $fixtures = makeCrewAssignmentFixtures();
    makeHistoricalOccupancyPriorWithEarlierActual($fixtures);

    $evaluator = new CrewAssignmentConflictEvaluator;
    $result = $evaluator->evaluate(new CrewAssignmentConflictContext(
        companyId: $fixtures['company']->id,
        employeeId: $fixtures['employee']->id,
        action: 'plan',
        plannedArrivalAt: CarbonImmutable::parse('2026-10-06', 'Asia/Dubai'),
        plannedJoinAt: CarbonImmutable::parse('2026-10-06', 'Asia/Dubai'),
        plannedSignoffAt: CarbonImmutable::parse('2026-10-07', 'Asia/Dubai'),
    ));

    expect($result->blocking)->toBeTrue()
        ->and($result->code)->toBe('historical_overlap')
        ->and($result->existingAssignment['start_date'] ?? null)->toBe('2026-10-05');
});

test('adjacent historical occupancy intervals do not false-overlap at the boundary', function () {
    expect(HistoricalAssignmentIntervalOverlap::dateStringsOverlap(
        '2026-10-01',
        '2026-10-10',
        '2026-10-10',
        '2026-10-20',
    ))->toBeFalse();

    $fixtures = makeCrewAssignmentFixtures();
    ['prior' => $prior] = makeHistoricalOccupancyPriorWithEarlierActual($fixtures);

    // Date-only planning starts the next calendar day after closed_at (18:00 on Oct 25).
    // Same calendar day would reserve midnight→midnight and correctly overlap the prior afternoon.
    $evaluator = new CrewAssignmentConflictEvaluator;
    $result = $evaluator->evaluate(new CrewAssignmentConflictContext(
        companyId: $fixtures['company']->id,
        employeeId: $fixtures['employee']->id,
        action: 'plan',
        plannedArrivalAt: CarbonImmutable::parse('2026-10-26', 'Asia/Dubai'),
        plannedJoinAt: CarbonImmutable::parse('2026-10-26', 'Asia/Dubai'),
        plannedSignoffAt: CarbonImmutable::parse('2026-11-10', 'Asia/Dubai'),
    ));

    expect($result->blocking)->toBeFalse()
        ->and($prior->assignment_no)->toBe('CA-HIST-OCC-001');
});

test('historical validator rejects import overlapping earlier actual occupancy', function () {
    $fixtures = makeCrewAssignmentFixtures();
    makeHistoricalOccupancyPriorWithEarlierActual($fixtures);

    $data = HistoricalCrewAssignmentData::fromArray(
        data: [
            'employee_id' => $fixtures['employee']->id,
            'vessel_id' => makeCrewMovementVessel('Import Overlap Vessel', $fixtures['company'])->id,
            'position_id' => $fixtures['rank']->id,
            'onsite_from' => '2026-10-06 08:00:00',
            'onsite_to' => '2026-10-07 18:00:00',
            'home_available_from' => '2026-10-08 08:00:00',
        ],
        companyId: $fixtures['company']->id,
        timezone: 'Asia/Dubai',
    );

    $result = app(HistoricalCrewAssignmentValidator::class)->validate($data, $fixtures['user']);

    expect($result->valid)->toBeFalse()
        ->and($result->errors)->toHaveKey('overlap');
});

test('void is blocked by unresolved scheduled movement and appears in impact preview', function () {
    $fixtures = makeCrewAssignmentFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.assignments.update',
        'crew_operations.movements.perform',
        'crew_operations.movements.schedule',
        'crew_operations.movements.schedule.manage',
        'crew_operations.assignments.void',
    ]);
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);

    $service = app(CrewMovementService::class);
    $vessel = makeCrewMovementVessel('Void Schedule Vessel', $fixtures['company']);
    $assignment = $service->createDraft($fixtures['company']->id, $fixtures['employee']->id, [
        'position_id' => $fixtures['rank']->id,
        'vessel_id' => $vessel->id,
    ], $fixtures['user']->id);

    $service->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2027-01-08 08:00:00',
    ], $fixtures['user']->id);
    $service->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2027-01-10 08:00:00',
        'next_phase' => CrewPhaseCode::JoinStandby->value,
        'accommodation_status' => 'no_accommodation',
    ], $fixtures['user']->id);

    app(ScheduleCrewMovement::class)->handle(
        $fixtures['company']->id,
        $assignment->fresh(),
        CrewMovementAction::JoinVessel,
        [
            'action' => CrewMovementAction::JoinVessel->value,
            'scheduled_at' => '2027-01-20 09:00:00',
            'vessel_id' => $vessel->id,
            'position_id' => $fixtures['rank']->id,
            'planned_signoff_choice' => 'manual_override',
            'planned_signoff_at' => '2027-03-20',
            'planned_signoff_override_reason' => 'Void block',
        ],
        $fixtures['user'],
    );

    $blockers = app(CrewAssignmentVoidGuard::class)->blockers($assignment->fresh(), $fixtures['company']->id);
    expect(collect($blockers)->pluck('code')->all())->toContain('unresolved_scheduled_movement');

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->postJson(route('organization.crew-assignments.void-preview'), [
            'assignment_ids' => [$assignment->id],
        ])
        ->assertOk()
        ->assertJsonPath('has_protected_blockers', true)
        ->assertJsonPath('has_unresolved_schedules', true)
        ->assertJsonPath('total_unresolved_schedules', 1);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('organization.crew-assignments.void', $assignment), [
            'void_reason' => 'Should be blocked by schedule',
        ])
        ->assertSessionHasErrors('void');

    expect($assignment->fresh()->voided_at)->toBeNull()
        ->and($assignment->fresh()->trashed())->toBeFalse();
});

test('void is blocked by pending correction request', function () {
    $fixtures = makeCrewAssignmentFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.assignments.void',
    ]);
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);

    $assignment = app(CrewMovementService::class)->createDraft(
        $fixtures['company']->id,
        $fixtures['employee']->id,
        ['position_id' => $fixtures['rank']->id],
        $fixtures['user']->id,
    );

    CrewMovementCorrection::factory()
        ->forAssignment($assignment)
        ->pending()
        ->create([
            'requested_by' => $fixtures['user']->id,
            'status' => CrewMovementCorrectionStatus::Pending,
        ]);

    $blockers = app(CrewAssignmentVoidGuard::class)->blockers($assignment->fresh(), $fixtures['company']->id);

    expect(collect($blockers)->pluck('code')->all())->toContain('pending_correction');

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('organization.crew-assignments.void', $assignment), [
            'void_reason' => 'Should be blocked by correction',
        ])
        ->assertSessionHasErrors('void');
});

test('quick reschedule earlier syncs auto-linked hotel checkout before validation', function () {
    $fixtures = makeHardeningScheduledMovementFixtures();
    $assignment = advanceHardeningJoinStandbyAssignment($fixtures);
    $hotel = Hotel::factory()->create([
        'company_id' => $fixtures['company']->id,
        'name' => 'Earlier Reschedule Hotel',
    ]);

    // Open pre-join hotel stay forces checkout chronology validation against scheduled join.
    CrewAccommodationStay::factory()->create([
        'company_id' => $fixtures['company']->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2027-01-10',
        'check_out_date' => null,
        'started_from_phase_id' => $assignment->current_phase_id,
        'created_by' => $fixtures['user']->id,
    ]);

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
            'planned_signoff_override_reason' => 'Earlier reschedule',
            'check_out_date' => '2027-01-20',
            'check_out_date_auto_synced' => true,
        ],
        $fixtures['user'],
    );

    // Date-only quick reschedule (no action_fields) moving earlier must sync checkout
    // before validation rejects checkout-after-join chronology.
    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->put(route('organization.crew-scheduled-movements.update', $schedule), [
            'scheduled_at' => '2027-01-18 09:00:00',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $schedule->refresh();

    expect($schedule->action_payload['check_out_date'] ?? null)->toBe('2027-01-18')
        ->and($schedule->action_payload['check_out_date_auto_synced'] ?? null)->toBeTrue()
        ->and($assignment->fresh()->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby);
});

test('manual hotel checkout override is preserved when rescheduling', function () {
    $fixtures = makeHardeningScheduledMovementFixtures();
    $assignment = advanceHardeningJoinStandbyAssignment($fixtures);

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
            'planned_signoff_override_reason' => 'Manual checkout',
            'check_out_date' => '2027-01-17',
            'check_out_date_auto_synced' => false,
        ],
        $fixtures['user'],
    );

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->put(route('organization.crew-scheduled-movements.update', $schedule), [
            'scheduled_at' => '2027-01-22 09:00:00',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($schedule->fresh()->action_payload['check_out_date'] ?? null)->toBe('2027-01-17')
        ->and($schedule->fresh()->action_payload['check_out_date_auto_synced'] ?? null)->toBeFalse();
});

test('recover scheduled movements --list is strictly read-only', function () {
    $fixtures = makeHardeningScheduledMovementFixtures();
    $assignment = advanceHardeningJoinStandbyAssignment($fixtures);

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
            'planned_signoff_override_reason' => 'List only',
        ],
        $fixtures['user'],
    );

    $schedule->update([
        'status' => CrewScheduledMovementStatus::Processing,
        'processing_started_at' => CrewScheduledMovementTimestamp::storeUtc(
            CarbonImmutable::parse('2027-01-20 04:00:00', 'UTC'),
        ),
    ]);

    Carbon::setTestNow(Carbon::parse('2027-01-20 09:10:00', 'Asia/Dubai'));

    $exit = Artisan::call('crew:recover-scheduled-movements', [
        '--list' => true,
        '--company' => (string) $fixtures['company']->id,
        '--process-due' => true,
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Read-only mode')
        ->and($output)->toContain('no modifications')
        ->and($schedule->fresh()->status)->toBe(CrewScheduledMovementStatus::Processing)
        ->and($schedule->fresh()->processing_started_at)->not->toBeNull()
        ->and(str_contains($output, 'Recovered'))->toBeFalse();
});

test('recover scheduled movements --company scopes recovery updates', function () {
    $fixturesA = makeHardeningScheduledMovementFixtures();
    $fixturesB = makeHardeningScheduledMovementFixtures();

    $assignmentA = advanceHardeningJoinStandbyAssignment($fixturesA);
    $assignmentB = advanceHardeningJoinStandbyAssignment($fixturesB);

    $staleA = app(ScheduleCrewMovement::class)->handle(
        $fixturesA['company']->id,
        $assignmentA,
        CrewMovementAction::JoinVessel,
        [
            'action' => CrewMovementAction::JoinVessel->value,
            'scheduled_at' => '2027-01-20 09:00:00',
            'vessel_id' => $assignmentA->vessel_id,
            'position_id' => $assignmentA->position_id,
            'planned_signoff_choice' => 'manual_override',
            'planned_signoff_at' => '2027-03-20',
            'planned_signoff_override_reason' => 'Scope A',
        ],
        $fixturesA['user'],
    );

    $staleB = app(ScheduleCrewMovement::class)->handle(
        $fixturesB['company']->id,
        $assignmentB,
        CrewMovementAction::JoinVessel,
        [
            'action' => CrewMovementAction::JoinVessel->value,
            'scheduled_at' => '2027-01-20 09:00:00',
            'vessel_id' => $assignmentB->vessel_id,
            'position_id' => $assignmentB->position_id,
            'planned_signoff_choice' => 'manual_override',
            'planned_signoff_at' => '2027-03-20',
            'planned_signoff_override_reason' => 'Scope B',
        ],
        $fixturesB['user'],
    );

    $stalePayload = [
        'status' => CrewScheduledMovementStatus::Processing,
        'processing_started_at' => CrewScheduledMovementTimestamp::storeUtc(
            CarbonImmutable::parse('2027-01-20 04:00:00', 'UTC'),
        ),
    ];
    $staleA->update($stalePayload);
    $staleB->update($stalePayload);

    Carbon::setTestNow(Carbon::parse('2027-01-20 09:10:00', 'Asia/Dubai'));

    Artisan::call('crew:recover-scheduled-movements', [
        '--company' => (string) $fixturesA['company']->id,
    ]);

    expect($staleA->fresh()->status)->toBe(CrewScheduledMovementStatus::NeedsAttention)
        ->and($staleB->fresh()->status)->toBe(CrewScheduledMovementStatus::Processing);
});

test('production environment rejects enabling future actual movement dates via HTTP', function () {
    $fixtures = makeHardeningFutureMovementSettingsFixtures();

    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.settings.view',
        'crew_operations.settings.update',
    ]);
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);

    $previousEnv = $this->app['env'] ?? null;
    $this->app['env'] = 'production';
    // ProductionSecurityDefaults forces secure cookies; keep HTTP tests workable.
    config(['session.secure' => false, 'app.env' => 'production']);
    // Switching APP_ENV mid-test can re-enable forgery checks; disable for this HTTP probe.
    $this->withoutMiddleware([
        PreventRequestForgery::class,
        ValidateCsrfToken::class,
        VerifyCsrfToken::class,
    ]);

    expect(app()->environment('production'))->toBeTrue()
        ->and(CrewOperationsSettings::futureActualOverrideEnvironmentsAllowed())->toBeFalse();

    expect(fn () => CrewOperationsSettings::saveSettings(
        $fixtures['company']->id,
        [],
        30,
        true,
        ['allow_future_actual_movement_dates' => true, 'actor_id' => $fixtures['user']->id],
    ))->toThrow(ValidationException::class);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->from(route('organization.crew-operations.settings.index'))
        ->put(route('organization.crew-operations.settings.update'), [
            'pool_department_ids' => [],
            'max_home_days' => 30,
            'sync_sea_service' => true,
            'sync_training_to_employee_training' => false,
            'allow_future_actual_movement_dates' => true,
            'notifications_enabled' => false,
            'notification_recipient_user_ids' => [],
            'alert_signoff_overdue' => true,
            'alert_signoff_no_relief' => true,
            'alert_relief_not_ready' => true,
            'alert_current_manning_gap' => true,
            'alert_projected_manning_gap' => true,
            'notification_email_delivery_mode' => 'scheduled',
            'notification_email_digest_at' => '08:00',
            'notification_email_critical_immediate' => false,
        ])
        ->assertSessionHasErrors('allow_future_actual_movement_dates');

    expect(CrewOperationsSetting::query()->where('company_id', $fixtures['company']->id)->value('allow_future_actual_movement_dates'))
        ->not->toBeTrue()
        ->and(CrewOperationsSettings::allowFutureActualMovementDates($fixtures['company']->id))->toBeFalse();

    // Legacy stored true remains in DB for compatibility but is never effective.
    CrewOperationsSetting::query()->updateOrCreate(
        ['company_id' => $fixtures['company']->id],
        ['allow_future_actual_movement_dates' => true],
    );

    expect(CrewOperationsSetting::query()->where('company_id', $fixtures['company']->id)->value('allow_future_actual_movement_dates'))->toBeTrue()
        ->and(CrewOperationsSettings::allowFutureActualMovementDates($fixtures['company']->id))->toBeFalse();

    $this->app['env'] = $previousEnv ?? 'testing';
    config(['app.env' => $previousEnv ?? 'testing']);
});

test('process due captures a fresh execution timestamp per movement', function () {
    $fixtures = makeHardeningScheduledMovementFixtures();
    $assignment = advanceHardeningJoinStandbyAssignment($fixtures);

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
            'planned_signoff_override_reason' => 'Fresh clock',
        ],
        $fixtures['user'],
    );

    // Advance the frozen clock after scheduling so execution time differs from batch selection.
    Carbon::setTestNow(Carbon::parse('2027-01-20 09:03:00', 'Asia/Dubai'));

    $selectionNow = Carbon::parse('2027-01-20 09:00:00', 'Asia/Dubai')->utc();
    $result = app(ProcessDueCrewScheduledMovements::class)->handle(25, $selectionNow);

    $schedule = CrewScheduledMovement::query()->where('crew_assignment_id', $assignment->id)->firstOrFail();

    expect($result['executed'])->toBe(1)
        ->and($schedule->status)->toBe(CrewScheduledMovementStatus::Executed)
        ->and($schedule->executed_at?->utc()->format('Y-m-d H:i:s'))->toBe('2027-01-20 05:03:00')
        ->and($schedule->effective_occurred_at?->utc()->format('Y-m-d H:i:s'))->toBe('2027-01-20 05:03:00');
});

test('scheduled execution refuses voided assignments', function () {
    $fixtures = makeHardeningScheduledMovementFixtures();
    $assignment = advanceHardeningJoinStandbyAssignment($fixtures);

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
            'planned_signoff_override_reason' => 'Void race',
        ],
        $fixtures['user'],
    );

    // Simulate a race where the schedule remained unresolved after void.
    $assignment->forceFill([
        'voided_at' => now(),
        'voided_by' => $fixtures['user']->id,
        'void_reason' => 'race simulation',
    ])->save();
    $assignment->delete();

    Carbon::setTestNow(Carbon::parse('2027-01-20 09:01:00', 'Asia/Dubai'));
    $now = Carbon::now('UTC');
    $claimed = app(ExecuteCrewScheduledMovement::class)->claim($schedule->id, $now);
    expect($claimed)->not->toBeNull();

    $result = app(ExecuteCrewScheduledMovement::class)->execute($claimed, $now);

    expect($result->status)->toBe(CrewScheduledMovementStatus::NeedsAttention)
        ->and($result->last_error_code)->toBe('assignment_voided');
});

test('open-ended historical occupancy blocks overlapping candidates', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Open Occupancy Vessel', $fixtures['company']);

    $active = CrewAssignment::query()->create([
        'company_id' => $fixtures['company']->id,
        'assignment_no' => 'CA-OPEN-OCC-001',
        'employee_id' => $fixtures['employee']->id,
        'position_id' => $fixtures['rank']->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Active,
        'started_at' => CarbonImmutable::parse('2026-10-08 08:00:00', 'Asia/Dubai'),
        'source' => 'manual',
    ]);

    CrewAssignmentPhase::query()->create([
        'company_id' => $fixtures['company']->id,
        'crew_assignment_id' => $active->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => CarbonImmutable::parse('2026-10-05 08:00:00', 'Asia/Dubai'),
        'actual_end_at' => null,
    ]);

    $detector = new CrewAssignmentOverlapDetector;
    $interval = $detector->operationalOccupancyInterval($active->fresh(['phases']));

    expect($interval)->not->toBeNull()
        ->and($interval['start']->timezone('Asia/Dubai')->toDateString())->toBe('2026-10-05')
        ->and($interval['end'])->toBeNull();
});

function makeSameDayCompletedAssignment(
    array $fixtures,
    string $assignmentNo,
    string $startAt,
    string $endAt,
    ?object $vessel = null,
): CrewAssignment {
    $vessel ??= makeCrewMovementVessel('Same Day Vessel '.$assignmentNo, $fixtures['company']);

    $assignment = CrewAssignment::query()->create([
        'company_id' => $fixtures['company']->id,
        'assignment_no' => $assignmentNo,
        'employee_id' => $fixtures['employee']->id,
        'position_id' => $fixtures['rank']->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Completed,
        'started_at' => CarbonImmutable::parse($startAt, 'Asia/Dubai'),
        'closed_at' => CarbonImmutable::parse($endAt, 'Asia/Dubai'),
        'source' => 'manual',
    ]);

    CrewAssignmentPhase::query()->create([
        'company_id' => $fixtures['company']->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => CarbonImmutable::parse($startAt, 'Asia/Dubai'),
        'actual_end_at' => CarbonImmutable::parse($endAt, 'Asia/Dubai'),
    ]);

    return $assignment->fresh(['phases']);
}

test('same-day overlapping assignments are rejected by timestamp occupancy', function () {
    $fixtures = makeCrewAssignmentFixtures();
    makeSameDayCompletedAssignment($fixtures, 'CA-SAME-DAY-A', '2026-10-10 08:00:00', '2026-10-10 18:00:00');

    $data = HistoricalCrewAssignmentData::fromArray(
        data: [
            'employee_id' => $fixtures['employee']->id,
            'vessel_id' => makeCrewMovementVessel('Same Day Overlap B', $fixtures['company'])->id,
            'position_id' => $fixtures['rank']->id,
            'onsite_from' => '2026-10-10 10:00:00',
            'onsite_to' => '2026-10-10 16:00:00',
            'home_available_from' => '2026-10-10 16:00:00',
        ],
        companyId: $fixtures['company']->id,
        timezone: 'Asia/Dubai',
    );

    $result = app(HistoricalCrewAssignmentValidator::class)->validate($data);

    expect($result->valid)->toBeFalse()
        ->and($result->errors)->toHaveKey('overlap');
});

test('same-day non-overlapping intervals are allowed', function () {
    $fixtures = makeCrewAssignmentFixtures();
    makeSameDayCompletedAssignment($fixtures, 'CA-SAME-DAY-OK-A', '2026-10-10 08:00:00', '2026-10-10 12:00:00');

    $data = HistoricalCrewAssignmentData::fromArray(
        data: [
            'employee_id' => $fixtures['employee']->id,
            'vessel_id' => makeCrewMovementVessel('Same Day OK B', $fixtures['company'])->id,
            'position_id' => $fixtures['rank']->id,
            'onsite_from' => '2026-10-10 12:00:00',
            'onsite_to' => '2026-10-10 18:00:00',
            'home_available_from' => '2026-10-10 18:00:00',
        ],
        companyId: $fixtures['company']->id,
        timezone: 'Asia/Dubai',
    );

    $result = app(HistoricalCrewAssignmentValidator::class)->validate($data);

    expect($result->valid)->toBeTrue();
});

test('exact transfer redeployment boundary is allowed at shared instant', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $prior = makeSameDayCompletedAssignment($fixtures, 'CA-XFER-A', '2026-10-10 08:00:00', '2026-10-10 16:30:00');

    $overlap = HistoricalAssignmentIntervalOverlap::intervalsOverlap(
        CarbonImmutable::parse('2026-10-10 16:30:00', 'Asia/Dubai'),
        CarbonImmutable::parse('2026-10-10 22:00:00', 'Asia/Dubai'),
        $prior->phases->first()->actual_start_at,
        $prior->phases->first()->actual_end_at,
    );

    expect($overlap)->toBeFalse();

    $data = HistoricalCrewAssignmentData::fromArray(
        data: [
            'employee_id' => $fixtures['employee']->id,
            'vessel_id' => makeCrewMovementVessel('Transfer Vessel B', $fixtures['company'])->id,
            'position_id' => $fixtures['rank']->id,
            'onsite_from' => '2026-10-10 16:30:00',
            'onsite_to' => '2026-10-10 22:00:00',
            'home_available_from' => '2026-10-10 22:00:00',
        ],
        companyId: $fixtures['company']->id,
        timezone: 'Asia/Dubai',
    );

    expect(app(HistoricalCrewAssignmentValidator::class)->validate($data)->valid)->toBeTrue();
});

test('one-minute timestamp overlap is rejected', function () {
    $fixtures = makeCrewAssignmentFixtures();
    makeSameDayCompletedAssignment($fixtures, 'CA-1MIN-A', '2026-10-10 08:00:00', '2026-10-10 16:30:00');

    $data = HistoricalCrewAssignmentData::fromArray(
        data: [
            'employee_id' => $fixtures['employee']->id,
            'vessel_id' => makeCrewMovementVessel('One Minute Overlap', $fixtures['company'])->id,
            'position_id' => $fixtures['rank']->id,
            'onsite_from' => '2026-10-10 16:29:00',
            'onsite_to' => '2026-10-10 22:00:00',
            'home_available_from' => '2026-10-10 22:00:00',
        ],
        companyId: $fixtures['company']->id,
        timezone: 'Asia/Dubai',
    );

    $result = app(HistoricalCrewAssignmentValidator::class)->validate($data);

    expect($result->valid)->toBeFalse()
        ->and($result->errors)->toHaveKey('overlap');
});

test('backdated initial arrival changes occupancy and blocks overlapping plans', function () {
    $fixtures = makeCrewAssignmentFixtures();
    ['prior' => $prior] = makeHistoricalOccupancyPriorWithEarlierActual($fixtures);

    $evaluator = new CrewAssignmentConflictEvaluator;
    $result = $evaluator->evaluate(new CrewAssignmentConflictContext(
        companyId: $fixtures['company']->id,
        employeeId: $fixtures['employee']->id,
        action: 'plan',
        plannedJoinAt: CarbonImmutable::parse('2026-10-06', 'Asia/Dubai'),
        plannedSignoffAt: CarbonImmutable::parse('2026-10-07', 'Asia/Dubai'),
    ));

    expect($result->blocking)->toBeTrue()
        ->and($result->code)->toBe('historical_overlap')
        ->and($prior->started_at->timezone('Asia/Dubai')->toDateString())->toBe('2026-10-08')
        ->and($result->existingAssignment['start_date'] ?? null)->toBe('2026-10-05');
});

test('completed assignment with unfinished phase cannot bypass validation', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Unreliable Completed Vessel', $fixtures['company']);

    $prior = CrewAssignment::query()->create([
        'company_id' => $fixtures['company']->id,
        'assignment_no' => 'CA-UNREL-001',
        'employee_id' => $fixtures['employee']->id,
        'position_id' => $fixtures['rank']->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Completed,
        'started_at' => CarbonImmutable::parse('2026-10-05 08:00:00', 'Asia/Dubai'),
        'closed_at' => null,
        'source' => 'manual',
    ]);

    CrewAssignmentPhase::query()->create([
        'company_id' => $fixtures['company']->id,
        'crew_assignment_id' => $prior->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => CarbonImmutable::parse('2026-10-05 08:00:00', 'Asia/Dubai'),
        'actual_end_at' => null,
    ]);

    $detector = new CrewAssignmentOverlapDetector;
    expect($detector->hasUnreliableCompletedOccupancy($prior->fresh(['phases'])))->toBeTrue();

    $evaluator = new CrewAssignmentConflictEvaluator;
    $result = $evaluator->evaluate(new CrewAssignmentConflictContext(
        companyId: $fixtures['company']->id,
        employeeId: $fixtures['employee']->id,
        action: 'plan',
        plannedJoinAt: CarbonImmutable::parse('2026-11-01', 'Asia/Dubai'),
        plannedSignoffAt: CarbonImmutable::parse('2026-11-30', 'Asia/Dubai'),
    ));

    expect($result->blocking)->toBeTrue()
        ->and($result->code)->toBe('historical_occupancy_unreliable');

    $data = HistoricalCrewAssignmentData::fromArray(
        data: [
            'employee_id' => $fixtures['employee']->id,
            'vessel_id' => makeCrewMovementVessel('Unreliable Manual', $fixtures['company'])->id,
            'position_id' => $fixtures['rank']->id,
            'onsite_from' => '2026-11-01 08:00:00',
            'onsite_to' => '2026-11-10 18:00:00',
            'home_available_from' => '2026-11-11 08:00:00',
        ],
        companyId: $fixtures['company']->id,
        timezone: 'Asia/Dubai',
    );

    $manualResult = app(HistoricalCrewAssignmentValidator::class)->validate($data);

    expect($manualResult->valid)->toBeFalse()
        ->and($manualResult->errors)->toHaveKey('overlap');
});

test('open-ended new assignment start checks completed historical occupancy', function () {
    $fixtures = makeCrewAssignmentFixtures();
    makeHistoricalOccupancyPriorWithEarlierActual($fixtures);

    $evaluator = new CrewAssignmentConflictEvaluator;
    $result = $evaluator->evaluate(new CrewAssignmentConflictContext(
        companyId: $fixtures['company']->id,
        employeeId: $fixtures['employee']->id,
        action: 'start',
        operationalStartAt: CarbonImmutable::parse('2026-10-08 10:00:00', 'Asia/Dubai'),
        plannedSignoffAt: null,
    ));

    expect($result->blocking)->toBeTrue()
        ->and($result->code)->toBe('historical_overlap');
});

test('date-only planning same-day reservation follows calendar-day policy', function () {
    expect(HistoricalAssignmentIntervalOverlap::dateStringsOverlap(
        '2026-10-10',
        '2026-10-10',
        '2026-10-10',
        '2026-10-10',
    ))->toBeTrue();

    expect(HistoricalAssignmentIntervalOverlap::dateStringsOverlap(
        '2026-10-01',
        '2026-10-10',
        '2026-10-10',
        '2026-10-20',
    ))->toBeFalse();

    $reservation = HistoricalAssignmentIntervalOverlap::calendarDayReservationInterval(
        '2026-10-10',
        '2026-10-10',
        'Asia/Dubai',
    );

    expect($reservation['start']->toDateTimeString())->toBe('2026-10-10 00:00:00')
        ->and($reservation['end']->toDateTimeString())->toBe('2026-10-11 00:00:00');

    $fixtures = makeCrewAssignmentFixtures();
    makeSameDayCompletedAssignment($fixtures, 'CA-DAY-RES-A', '2026-10-10 08:00:00', '2026-10-10 18:00:00');

    $evaluator = new CrewAssignmentConflictEvaluator;
    $result = $evaluator->evaluate(new CrewAssignmentConflictContext(
        companyId: $fixtures['company']->id,
        employeeId: $fixtures['employee']->id,
        action: 'plan',
        plannedJoinAt: CarbonImmutable::parse('2026-10-10', 'Asia/Dubai'),
        plannedSignoffAt: CarbonImmutable::parse('2026-10-10', 'Asia/Dubai'),
    ));

    expect($result->blocking)->toBeTrue()
        ->and($result->code)->toBe('historical_overlap');
});

test('historical manual entry and excel-style date strings share overlap semantics', function () {
    expect(HistoricalAssignmentIntervalOverlap::dateStringsOverlap(
        '2026-10-10',
        '2026-10-10',
        '2026-10-10',
        '2026-10-20',
    ))->toBeTrue();

    $fixtures = makeCrewAssignmentFixtures();
    makeSameDayCompletedAssignment($fixtures, 'CA-MANUAL-A', '2026-10-10 08:00:00', '2026-10-10 18:00:00');

    $manual = HistoricalCrewAssignmentData::fromArray(
        data: [
            'employee_id' => $fixtures['employee']->id,
            'vessel_id' => makeCrewMovementVessel('Manual Entry B', $fixtures['company'])->id,
            'position_id' => $fixtures['rank']->id,
            'onsite_from' => '2026-10-10 10:00:00',
            'onsite_to' => '2026-10-10 16:00:00',
            'home_available_from' => '2026-10-10 16:00:00',
        ],
        companyId: $fixtures['company']->id,
        timezone: 'Asia/Dubai',
        source: HistoricalCrewAssignmentData::SOURCE_MANUAL,
    );

    $import = HistoricalCrewAssignmentData::fromArray(
        data: [
            'employee_id' => $fixtures['employee']->id,
            'vessel_id' => makeCrewMovementVessel('Import Entry B', $fixtures['company'])->id,
            'position_id' => $fixtures['rank']->id,
            'onsite_from' => '2026-10-10 10:00:00',
            'onsite_to' => '2026-10-10 16:00:00',
            'home_available_from' => '2026-10-10 16:00:00',
        ],
        companyId: $fixtures['company']->id,
        timezone: 'Asia/Dubai',
        source: HistoricalCrewAssignmentData::SOURCE_IMPORT,
    );

    $validator = app(HistoricalCrewAssignmentValidator::class);
    $manualResult = $validator->validate($manual);
    $importResult = $validator->validate($import);

    expect($manualResult->valid)->toBeFalse()
        ->and($manualResult->errors)->toHaveKey('overlap')
        ->and($importResult->valid)->toBeFalse()
        ->and($importResult->errors)->toHaveKey('overlap');
});

test('historical overlap checks remain company-scoped', function () {
    $fixtures = makeCrewAssignmentFixtures();
    makeHistoricalOccupancyPriorWithEarlierActual($fixtures);

    $other = makeCrewAssignmentFixtures();

    $evaluator = new CrewAssignmentConflictEvaluator;
    $result = $evaluator->evaluate(new CrewAssignmentConflictContext(
        companyId: $other['company']->id,
        employeeId: $other['employee']->id,
        action: 'plan',
        plannedJoinAt: CarbonImmutable::parse('2026-10-06', 'Asia/Dubai'),
        plannedSignoffAt: CarbonImmutable::parse('2026-10-07', 'Asia/Dubai'),
    ));

    expect($result->blocking)->toBeFalse();
});

test('midnight and company timezone boundaries use local calendar-day reservation', function () {
    $tz = 'America/New_York';
    $detector = new CrewAssignmentOverlapDetector;

    $reservation = $detector->calendarDayReservationInterval('2026-10-10', '2026-10-10', $tz);
    expect($reservation['start']->timezone($tz)->toDateTimeString())->toBe('2026-10-10 00:00:00')
        ->and($reservation['end']->timezone($tz)->toDateTimeString())->toBe('2026-10-11 00:00:00')
        // Absolute instants differ from UTC midnight.
        ->and($reservation['start']->timezone('UTC')->toDateTimeString())->toBe('2026-10-10 04:00:00');

    $start = CarbonImmutable::parse('2026-10-10 00:30:00', $tz);
    $end = CarbonImmutable::parse('2026-10-10 23:30:00', $tz);

    // Crossing local midnight into the next day must not false-overlap the prior closed_at.
    expect(HistoricalAssignmentIntervalOverlap::intervalsOverlap(
        CarbonImmutable::parse('2026-10-11 00:00:00', $tz),
        CarbonImmutable::parse('2026-10-20 00:00:00', $tz),
        $start,
        $end,
    ))->toBeFalse();

    // Same local morning is still occupied when compared with timezone-aware instants.
    expect(HistoricalAssignmentIntervalOverlap::intervalsOverlap(
        $reservation['start'],
        $reservation['end'],
        $start,
        $end,
    ))->toBeTrue();

    // DST spring-forward boundary: 2026-03-08 02:00 does not exist in America/New_York.
    $beforeDst = CarbonImmutable::parse('2026-03-08 01:30:00', $tz);
    $afterDst = CarbonImmutable::parse('2026-03-08 03:30:00', $tz);
    expect(HistoricalAssignmentIntervalOverlap::intervalsOverlap(
        $beforeDst,
        $afterDst,
        CarbonImmutable::parse('2026-03-08 03:30:00', $tz),
        CarbonImmutable::parse('2026-03-08 05:00:00', $tz),
    ))->toBeFalse();
});

test('overlap hardening does not mutate existing payroll or sea service records', function () {
    $fixtures = makeCrewAssignmentFixtures();
    ['prior' => $prior] = makeHistoricalOccupancyPriorWithEarlierActual($fixtures);

    $sea = EmployeeSeaService::query()->create([
        'company_id' => $fixtures['company']->id,
        'employee_id' => $fixtures['employee']->id,
        'vessel_id' => $prior->vessel_id,
        'position_id' => $fixtures['rank']->id,
        'start_date' => '2026-10-05',
        'end_date' => '2026-10-25',
        'total_months' => 0,
        'total_days' => 21,
    ]);

    $seaId = $sea->id;
    $seaStart = $sea->start_date?->toDateString();
    $seaEnd = $sea->end_date?->toDateString();
    $priorStarted = $prior->started_at?->toIso8601String();
    $priorClosed = $prior->closed_at?->toIso8601String();
    $phaseStart = $prior->phases->first()->actual_start_at?->toIso8601String();
    $phaseEnd = $prior->phases->first()->actual_end_at?->toIso8601String();

    $evaluator = new CrewAssignmentConflictEvaluator;
    $evaluator->evaluate(new CrewAssignmentConflictContext(
        companyId: $fixtures['company']->id,
        employeeId: $fixtures['employee']->id,
        action: 'start',
        operationalStartAt: CarbonImmutable::parse('2026-10-08 10:00:00', 'Asia/Dubai'),
        plannedSignoffAt: null,
    ));

    $seaFresh = $sea->fresh();
    $priorFresh = $prior->fresh();
    $phaseFresh = $prior->phases()->first();

    expect($seaFresh->id)->toBe($seaId)
        ->and($seaFresh->start_date?->toDateString())->toBe($seaStart)
        ->and($seaFresh->end_date?->toDateString())->toBe($seaEnd)
        ->and($priorFresh->started_at?->toIso8601String())->toBe($priorStarted)
        ->and($priorFresh->closed_at?->toIso8601String())->toBe($priorClosed)
        ->and($priorFresh->status)->toBe(CrewAssignmentStatus::Completed)
        ->and($phaseFresh->actual_start_at?->toIso8601String())->toBe($phaseStart)
        ->and($phaseFresh->actual_end_at?->toIso8601String())->toBe($phaseEnd);
});
