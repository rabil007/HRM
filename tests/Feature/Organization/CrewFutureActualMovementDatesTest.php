<?php

use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Exceptions\CrewMovementException;
use App\Models\CrewOperationsSetting;
use App\Support\CrewMovements\CrewActualMovementTimestampGuard;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\CrewOperations\CrewOperationsSettings;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

test('future actual movement timestamps are rejected by the movement service', function () {
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

test('legacy stored override never allows future actuals through the timestamp guard', function () {
    ['company' => $companyA] = makeCrewAssignmentFixtures();
    ['company' => $companyB] = makeCrewAssignmentFixtures();
    $companyA->update(['timezone' => 'Asia/Dubai']);
    $companyB->update(['timezone' => 'Asia/Dubai']);

    CrewOperationsSetting::query()->create([
        'company_id' => $companyA->id,
        'allow_future_actual_movement_dates' => true,
        'max_home_days' => 30,
    ]);
    CrewOperationsSettings::clearCache($companyA->id);

    Carbon::setTestNow(CarbonImmutable::parse('2026-09-25 12:00:00', 'Asia/Dubai'));
    $future = CarbonImmutable::parse('2026-09-30 08:00:00', 'Asia/Dubai');
    $guard = new CrewActualMovementTimestampGuard;

    expect(CrewOperationsSettings::allowFutureActualMovementDates($companyA->id))->toBeFalse();
    expect(fn () => $guard->assertNotFuture($companyA->id, $future))
        ->toThrow(CrewMovementException::class, 'Actual movement events cannot be recorded in the future.');
    expect(fn () => $guard->assertNotFuture($companyB->id, $future))
        ->toThrow(CrewMovementException::class, 'Actual movement events cannot be recorded in the future.');

    Carbon::setTestNow();
});

test('direct service perform still rejects future timestamps even when settings row is true', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    $company->update(['timezone' => 'Asia/Dubai']);
    setMappedCrewTourOfDutyDays($company, $rank, 90);

    CrewOperationsSetting::query()->create([
        'company_id' => $company->id,
        'allow_future_actual_movement_dates' => true,
        'max_home_days' => 30,
    ]);
    CrewOperationsSettings::clearCache($company->id);

    $vessel = makeCrewMovementVessel('Legacy Override Vessel', $company);
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
