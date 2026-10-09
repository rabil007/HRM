<?php

use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Exceptions\CrewMovementException;
use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\CrewOperationsSetting;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Support\CrewMovements\CrewActualMovementTimestampGuard;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\CrewOperations\CrewOperationsSettings;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

test('future actual movement timestamps are rejected by default', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    $company->update(['timezone' => 'Asia/Dubai']);
    setMappedCrewTourOfDutyDays($company, $rank, 90);
    $vessel = makeCrewMovementVessel('Future Join Off Vessel', $company);
    $assignment = makeCurrentCrewPhaseAssignment(
        $company,
        $employee,
        $rank,
        $vessel,
        CrewPhaseCode::JoinStandby,
    );

    Carbon::setTestNow(CarbonImmutable::parse('2026-09-25 12:00:00', 'Asia/Dubai'));

    $tomorrow = CarbonImmutable::parse('2026-09-26 10:00:00', 'Asia/Dubai')->format('Y-m-d H:i:s');

    expect(fn () => app(CrewMovementService::class)->perform(
        $company->id,
        $assignment->id,
        CrewMovementAction::JoinVessel,
        [
            'occurred_at' => $tomorrow,
            'vessel_id' => $vessel->id,
            'position_id' => $rank->id,
            'planned_signoff_choice' => 'tour_of_duty',
        ],
        $user->id,
    ))->toThrow(CrewMovementException::class, 'Actual movement events cannot be recorded in the future.');

    Carbon::setTestNow();
});

test('future actual movement timestamps are allowed when company override is enabled', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    $company->update(['timezone' => 'Asia/Dubai']);
    setMappedCrewTourOfDutyDays($company, $rank, 90);
    CrewOperationsSetting::query()->create([
        'company_id' => $company->id,
        'allow_future_actual_movement_dates' => true,
        'max_home_days' => 30,
    ]);
    CrewOperationsSettings::clearCache($company->id);

    $vessel = makeCrewMovementVessel('Future Join On Vessel', $company);
    $assignment = makeCurrentCrewPhaseAssignment(
        $company,
        $employee,
        $rank,
        $vessel,
        CrewPhaseCode::JoinStandby,
    );

    Carbon::setTestNow(CarbonImmutable::parse('2026-09-25 12:00:00', 'Asia/Dubai'));
    $tomorrow = CarbonImmutable::parse('2026-09-26 10:00:00', 'Asia/Dubai')->format('Y-m-d H:i:s');

    $joined = app(CrewMovementService::class)->perform(
        $company->id,
        $assignment->id,
        CrewMovementAction::JoinVessel,
        [
            'occurred_at' => $tomorrow,
            'vessel_id' => $vessel->id,
            'position_id' => $rank->id,
            'planned_signoff_choice' => 'tour_of_duty',
        ],
        $user->id,
    );

    expect($joined->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel)
        ->and($joined->currentPhase?->actual_start_at?->timezone('Asia/Dubai')->toDateString())->toBe('2026-09-26');

    Carbon::setTestNow();
});

test('future join standby is allowed when override is enabled', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    $company->update(['timezone' => 'Asia/Dubai']);
    CrewOperationsSettings::saveSettings($company->id, [], 30, true, [
        'allow_future_actual_movement_dates' => true,
        'actor_id' => $user->id,
    ]);

    $vessel = makeCrewMovementVessel('Future Standby Vessel', $company);
    $service = app(CrewMovementService::class);
    $assignment = $service->createDraft($company->id, $employee->id, [
        'position_id' => $rank->id,
        'vessel_id' => $vessel->id,
    ], $user->id);

    Carbon::setTestNow(CarbonImmutable::parse('2026-09-25 12:00:00', 'Asia/Dubai'));

    $service->perform($company->id, $assignment->id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-09-25 12:00:00',
    ], $user->id);

    $arrived = $service->perform($company->id, $assignment->id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-09-27 09:00:00',
        'next_phase' => CrewPhaseCode::JoinStandby->value,
    ], $user->id);

    expect($arrived->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby)
        ->and($arrived->currentPhase?->actual_start_at?->timezone('Asia/Dubai')->toDateString())->toBe('2026-09-27');

    Carbon::setTestNow();
});

test('override does not bypass chronological disembarkation before join', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    $company->update(['timezone' => 'Asia/Dubai']);
    setMappedCrewTourOfDutyDays($company, $rank, 90);
    CrewOperationsSettings::saveSettings($company->id, [], 30, false, [
        'allow_future_actual_movement_dates' => true,
        'actor_id' => $user->id,
    ]);

    $vessel = makeCrewMovementVessel('Chronology Vessel', $company);
    $assignment = makeCurrentCrewPhaseAssignment(
        $company,
        $employee,
        $rank,
        $vessel,
        CrewPhaseCode::JoinStandby,
    );

    Carbon::setTestNow(CarbonImmutable::parse('2026-09-25 12:00:00', 'Asia/Dubai'));
    $service = app(CrewMovementService::class);

    $joined = $service->perform(
        $company->id,
        $assignment->id,
        CrewMovementAction::JoinVessel,
        [
            'occurred_at' => '2026-09-30 10:00:00',
            'vessel_id' => $vessel->id,
            'position_id' => $rank->id,
            'planned_signoff_choice' => 'tour_of_duty',
        ],
        $user->id,
    );

    expect($joined->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel);

    expect(fn () => $service->perform(
        $company->id,
        $joined->id,
        CrewMovementAction::ConfirmDisembarkation,
        [
            'occurred_at' => '2026-09-28 08:00:00',
            'next_phase' => CrewPhaseCode::DemobStandby->value,
        ],
        $user->id,
    ))->toThrow(CrewMovementException::class, 'Phase actual end cannot be before actual start.');

    Carbon::setTestNow();
});

test('override allows chronologically valid later disembarkation in the future', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    $company->update(['timezone' => 'Asia/Dubai']);
    CrewOperationsSettings::saveSettings($company->id, [], 30, true, [
        'allow_future_actual_movement_dates' => true,
        'actor_id' => $user->id,
    ]);

    $vessel = makeCrewMovementVessel('Future Disembark Vessel', $company);
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    Carbon::setTestNow(CarbonImmutable::parse('2026-09-25 12:00:00', 'Asia/Dubai'));

    $disembarked = app(CrewMovementService::class)->perform(
        $company->id,
        $assignment->id,
        CrewMovementAction::ConfirmDisembarkation,
        [
            'occurred_at' => '2026-10-20 10:00:00',
            'next_phase' => CrewPhaseCode::DemobStandby->value,
        ],
        $user->id,
    );

    expect($disembarked->currentPhase?->phase_code)->toBe(CrewPhaseCode::DemobStandby)
        ->and($disembarked->currentPhase?->actual_start_at?->timezone('Asia/Dubai')->toDateString())->toBe('2026-10-20');

    Carbon::setTestNow();
});

test('timestamp guard respects company scoping of the override', function () {
    ['company' => $companyA] = makeCrewAssignmentFixtures();
    ['company' => $companyB] = makeCrewAssignmentFixtures();
    $companyA->update(['timezone' => 'Asia/Dubai']);
    $companyB->update(['timezone' => 'Asia/Dubai']);

    CrewOperationsSettings::saveSettings($companyA->id, [], 30, true, [
        'allow_future_actual_movement_dates' => true,
    ]);

    Carbon::setTestNow(CarbonImmutable::parse('2026-09-25 12:00:00', 'Asia/Dubai'));
    $future = CarbonImmutable::parse('2026-09-30 08:00:00', 'Asia/Dubai');
    $guard = new CrewActualMovementTimestampGuard;

    expect(fn () => $guard->assertNotFuture($companyA->id, $future))->not->toThrow(CrewMovementException::class);
    expect(fn () => $guard->assertNotFuture($companyB->id, $future))
        ->toThrow(CrewMovementException::class, 'Actual movement events cannot be recorded in the future.');

    Carbon::setTestNow();
});

/**
 * @return array{user: User, company: Company, employee: Employee, rank: Position, assignment: CrewAssignment}
 */
function makeFutureMovementHttpFixtures(bool $allowFuture): array
{
    $fixtures = makeCrewAssignmentFixtures();
    $fixtures['company']->update(['timezone' => 'Asia/Dubai']);

    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.assignments.update',
        'crew_operations.movements.perform',
    ]);
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);

    CrewOperationsSettings::saveSettings($fixtures['company']->id, [], 30, true, [
        'allow_future_actual_movement_dates' => $allowFuture,
        'actor_id' => $fixtures['user']->id,
    ]);

    $service = app(CrewMovementService::class);
    $assignment = $service->startAssignment($fixtures['company']->id, $fixtures['employee']->id, [
        'position_id' => $fixtures['rank']->id,
        'stage_started_at' => '2026-09-25 10:00:00',
    ], $fixtures['user']->id);

    return [
        ...$fixtures,
        'assignment' => $assignment,
    ];
}

test('http rejects future actual movements when testing override is disabled', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-25 12:00:00', 'Asia/Dubai'));

    ['user' => $user, 'assignment' => $assignment] = makeFutureMovementHttpFixtures(false);

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2026-09-27 09:00:00',
            'next_phase' => CrewPhaseCode::JoinStandby->value,
            'accommodation_status' => 'no_accommodation',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment))
        ->assertSessionHasErrors([
            'occurred_at' => 'Actual movement events cannot be recorded in the future.',
        ]);

    Carbon::setTestNow();
});

test('http accepts future actual movements when testing override is enabled', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-25 12:00:00', 'Asia/Dubai'));

    ['user' => $user, 'company' => $company, 'rank' => $rank, 'assignment' => $assignment] = makeFutureMovementHttpFixtures(true);
    $vessel = makeCrewMovementVessel('Future Http Vessel', $company);
    setMappedCrewTourOfDutyDays($company, $rank, 90);

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2026-09-27 09:00:00',
            'next_phase' => CrewPhaseCode::JoinStandby->value,
            'accommodation_status' => 'no_accommodation',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment))
        ->assertSessionHasNoErrors();

    $assignment->refresh()->load('currentPhase');
    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby)
        ->and($assignment->currentPhase?->actual_start_at?->timezone('Asia/Dubai')->toDateString())->toBe('2026-09-27');

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::JoinVessel->value,
            'occurred_at' => '2026-09-30 10:00:00',
            'vessel_id' => $vessel->id,
            'position_id' => $rank->id,
            'planned_signoff_choice' => 'tour_of_duty',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment))
        ->assertSessionHasNoErrors();

    $assignment->refresh()->load('currentPhase');
    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel)
        ->and($assignment->currentPhase?->actual_start_at?->timezone('Asia/Dubai')->toDateString())->toBe('2026-09-30');

    Carbon::setTestNow();
});

test('http still rejects chronologically invalid future disembarkation when override is enabled', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-25 12:00:00', 'Asia/Dubai'));

    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $company->update(['timezone' => 'Asia/Dubai']);
    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.movements.perform',
    ]);
    $user->update(['current_company_id' => $company->id]);

    CrewOperationsSettings::saveSettings($company->id, [], 30, true, [
        'allow_future_actual_movement_dates' => true,
        'actor_id' => $user->id,
    ]);

    $vessel = makeCrewMovementVessel('Chronology Http Vessel', $company);
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::ConfirmDisembarkation->value,
            'occurred_at' => '2025-12-31 08:00:00',
            'next_phase' => CrewPhaseCode::DemobStandby->value,
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment))
        ->assertSessionHasErrors(['occurred_at']);

    Carbon::setTestNow();
});

test('users without movement permission cannot use future dates even when override is enabled', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-25 12:00:00', 'Asia/Dubai'));

    ['user' => $user, 'company' => $company, 'assignment' => $assignment] = makeFutureMovementHttpFixtures(true);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2026-09-27 09:00:00',
            'next_phase' => CrewPhaseCode::JoinStandby->value,
            'accommodation_status' => 'no_accommodation',
        ])
        ->assertForbidden();

    Carbon::setTestNow();
});
