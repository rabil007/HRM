<?php

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewTimesheetPayCategory;
use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\CrewAssignment;
use App\Models\CrewTimesheetPreparationLine;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Support\Contracts\Actions\ApplyContractSalaryRevision;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\Payroll\Actions\SyncContractSalaryComponentsFromContract;
use Carbon\CarbonImmutable;

afterEach(function () {
    CarbonImmutable::setTestNow();
    restoreCrewMovementTestClock();
});

function movementPayrollService(): CrewMovementService
{
    return app(CrewMovementService::class);
}

/**
 * @return array{
 *     user: mixed,
 *     company: mixed,
 *     employee: mixed,
 *     rank: mixed,
 *     period: PayrollPeriod,
 *     vessel: mixed,
 *     contract: EmployeeContract
 * }
 */
function makeMovementPayrollFixtures(): array
{
    freezeCrewMovementTestClock();

    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $company->update(['timezone' => 'Asia/Dubai']);
    $rank->update(['max_tour_of_duty_days' => 90]);

    $contract = EmployeeContract::factory()->create([
        'employee_id' => $employee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'salary_structure' => ContractSalaryStructure::Daily,
        'status' => 'active',
        'start_date' => '2026-01-01',
        'end_date' => null,
        'basic_salary' => 100,
        'site_allowance' => 30,
        'supplementary_allowance' => 20,
    ]);
    (new SyncContractSalaryComponentsFromContract)->handle($contract);
    app(ApplyContractSalaryRevision::class)->handle(
        $contract->fresh(),
        [
            'basic_salary' => 100,
            'site_allowance' => 30,
            'supplementary_allowance' => 20,
        ],
        '2026-01-01',
        'Movement payroll fixture rates',
    );

    $period = PayrollPeriod::factory()->for($company)->crewOperations()->create([
        'status' => PayrollPeriodStatus::Draft,
        'payroll_category' => PayrollCategory::Crew,
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
        'payment_date' => '2026-09-30',
    ]);

    $vessel = makeCrewMovementVessel('Movement Payroll Vessel', $company);

    return compact('user', 'company', 'employee', 'rank', 'period', 'vessel', 'contract');
}

function preparationPayableDays(int $preparationId, CrewTimesheetPayCategory $category): float
{
    return (float) CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparationId)
        ->where('pay_category', $category)
        ->sum('days');
}

test('real crew movement happy path produces correct payroll through apply', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-21 12:00:00', 'Asia/Dubai'));

    $fixtures = makeMovementPayrollFixtures();
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user, 'vessel' => $vessel] = $fixtures;
    $service = movementPayrollService();

    $assignment = $service->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
    ], $user->id);
    $id = $assignment->id;

    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-09-01 08:00:00',
    ], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-09-03 08:00:00',
        'next_phase' => 'p2a',
    ], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-09-07 08:00:00',
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
    ], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-09-15 12:00:00',
        'next_phase' => 'p5',
    ], $user->id);
    $assignment = $service->perform($company->id, $id, CrewMovementAction::TravelHome, [
        'occurred_at' => '2026-09-18 14:00:00',
        'completion_intent' => 'close',
    ], $user->id);

    expect($assignment->status)->toBe(CrewAssignmentStatus::Completed)
        ->and($assignment->phases()->count())->toBe(5);

    $fixtures['assignment'] = $assignment->fresh(['phases']);
    ['preparation' => $preparation, 'record' => $record] = runDailyCrewPayrollPipeline($fixtures);

    $standbyDays = preparationPayableDays($preparation->id, CrewTimesheetPayCategory::SignOnStandby);
    $onsiteDays = preparationPayableDays($preparation->id, CrewTimesheetPayCategory::Onsite);
    $signOffDays = preparationPayableDays($preparation->id, CrewTimesheetPayCategory::SignOffStandby);

    expect($standbyDays)->toBe(4.0)
        ->and($onsiteDays)->toBe(9.0)
        ->and($signOffDays)->toBe(3.0)
        ->and($record)->not->toBeNull()
        ->and((float) $record->basic_salary)->toBe(round(($standbyDays + $onsiteDays + $signOffDays) * 100, 2))
        ->and((float) $record->calculation_breakdown['rates']['basic_daily'])->toBe(100.0);

    assertPayrollReconciles($record);
});

test('real transfer vessel action allocates shared day once through payroll', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-21 12:00:00', 'Asia/Dubai'));

    $fixtures = makeMovementPayrollFixtures();
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user, 'vessel' => $sourceVessel] = $fixtures;
    $destinationVessel = makeCrewMovementVessel('Transfer Destination', $company);
    $service = movementPayrollService();

    $source = $service->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $sourceVessel->id,
    ], $user->id);
    $sourceId = $source->id;

    $service->perform($company->id, $sourceId, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-09-01 08:00:00',
    ], $user->id);
    $service->perform($company->id, $sourceId, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-09-02 08:00:00',
        'next_phase' => 'p2a',
    ], $user->id);
    $source = $service->perform($company->id, $sourceId, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-09-10 08:00:00',
        'vessel_id' => $sourceVessel->id,
        'rank_id' => $rank->id,
    ], $user->id);

    $destination = $service->perform(
        $company->id,
        $source->id,
        CrewMovementAction::TransferVessel,
        [
            'occurred_at' => '2026-09-15 12:00:00',
            'vessel_id' => $destinationVessel->id,
            'rank_id' => $rank->id,
        ],
        $user->id,
    );

    $source->refresh()->load(['phases', 'currentPhase']);

    expect($source->status)->toBe(CrewAssignmentStatus::Completed)
        ->and($source->currentPhase?->actual_end_at?->toDateTimeString())
        ->toBe($destination->currentPhase?->actual_start_at?->toDateTimeString())
        ->and($destination->previous_assignment_id)->toBe($source->id)
        ->and($destination->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel);

    $fixtures['assignment'] = $source;
    ['preparation' => $preparation, 'record' => $record] = runDailyCrewPayrollPipeline($fixtures);

    $onsiteDays = preparationPayableDays($preparation->id, CrewTimesheetPayCategory::Onsite);

    expect(overlapWarningExists($preparation->id))->toBeFalse()
        ->and($onsiteDays)->toBeGreaterThan(6.0)
        ->and($record)->not->toBeNull()
        ->and((float) $record->gross_salary)->toBeGreaterThan((float) $record->basic_salary);

    assertPayrollReconciles($record);
});

test('real cancel assignment after standby preserves legitimate standby pay through payroll', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-10 12:00:00', 'Asia/Dubai'));

    $fixtures = makeMovementPayrollFixtures();
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user, 'vessel' => $vessel] = $fixtures;
    $service = movementPayrollService();

    $assignment = $service->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
    ], $user->id);
    $id = $assignment->id;

    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-09-01 08:00:00',
    ], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-09-03 08:00:00',
        'next_phase' => 'p2a',
    ], $user->id);

    $assignment = $service->perform($company->id, $id, CrewMovementAction::CancelAssignment, [
        'occurred_at' => '2026-09-06 18:00:00',
        'reason' => 'Vessel deployment cancelled',
    ], $user->id);

    expect($assignment->status)->toBe(CrewAssignmentStatus::Cancelled);

    $fixtures['assignment'] = $assignment->fresh(['phases']);
    ['preparation' => $preparation, 'record' => $record] = runDailyCrewPayrollPipeline($fixtures);

    $standbyDays = preparationPayableDays($preparation->id, CrewTimesheetPayCategory::SignOnStandby);

    expect($standbyDays)->toBe(4.0)
        ->and((float) $record->calculation_breakdown['sign_on_standby_days'])->toBe(4.0)
        ->and((float) $record->basic_salary)->toBe(400.0)
        ->and((float) $record->other_allowances)->toBe(80.0)
        ->and((float) $record->gross_salary)->toBe(480.0)
        ->and((float) $record->net_salary)->toBe(480.0);

    assertPayrollReconciles($record);
});

test('real redeploy closes source assignment and pays combined timeline once', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-21 12:00:00', 'Asia/Dubai'));

    $fixtures = makeMovementPayrollFixtures();
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user, 'vessel' => $vessel] = $fixtures;
    $service = movementPayrollService();

    $source = $service->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
    ], $user->id);
    $sourceId = $source->id;

    $service->perform($company->id, $sourceId, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-09-01 08:00:00',
    ], $user->id);
    $service->perform($company->id, $sourceId, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-09-02 08:00:00',
        'next_phase' => 'p2a',
    ], $user->id);
    $source = $service->perform($company->id, $sourceId, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-09-05 08:00:00',
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
    ], $user->id);

    $service->perform($company->id, $source->id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-09-12 08:00:00',
        'next_phase' => 'p6',
    ], $user->id);

    $destination = $service->perform($company->id, $source->id, CrewMovementAction::Redeploy, [
        'occurred_at' => '2026-09-14 10:00:00',
        'starting_phase' => 'p2a',
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
    ], $user->id);

    $source->refresh();

    expect($source->status)->toBe(CrewAssignmentStatus::Completed)
        ->and($destination->previous_assignment_id)->toBe($source->id)
        ->and($destination->source)->toBe('redeployment')
        ->and(CrewAssignment::query()->where('company_id', $company->id)->where('employee_id', $employee->id)->where('status', CrewAssignmentStatus::Active)->count())
        ->toBe(1);

    $fixtures['assignment'] = $source;
    ['preparation' => $preparation, 'record' => $record] = runDailyCrewPayrollPipeline($fixtures);

    expect(overlapWarningExists($preparation->id))->toBeFalse()
        ->and(preparationPayableDays($preparation->id, CrewTimesheetPayCategory::SignOnStandby))->toBeGreaterThan(0)
        ->and(preparationPayableDays($preparation->id, CrewTimesheetPayCategory::Onsite))->toBeGreaterThan(0)
        ->and($record)->not->toBeNull();

    assertPayrollReconciles($record);
});
