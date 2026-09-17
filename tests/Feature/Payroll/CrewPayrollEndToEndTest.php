<?php

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewTimesheetPayCategory;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewMovementCorrection;
use App\Models\CrewTimesheetPreparationLine;
use App\Models\CrewTimesheetSegment;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Support\Payroll\Actions\GenerateCrewPayroll;
use App\Support\Payroll\Actions\SyncContractSalaryComponentsFromContract;
use App\Support\Payroll\CrewOperationsPayrollGenerationGuard;
use App\Support\Payroll\CrewTimeline\Actions\ApplyCrewTimesheetPreparation;
use App\Support\Payroll\CrewTimeline\Actions\ApproveCrewTimesheetPreparation;
use App\Support\Payroll\CrewTimeline\Actions\SubmitCrewTimesheetPreparation;
use App\Support\Payroll\CrewTimeline\CrewTimelineFreshnessChecker;
use App\Support\Payroll\CrewTimeline\PayableCrewPreparationLines;
use App\Support\Payroll\CrewTimeline\PrepareCrewTimesheetTimeline;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function setDailyCrewContractRates(array $fixtures, float $basic, float $site, float $supp): EmployeeContract
{
    $contract = EmployeeContract::query()
        ->where('employee_id', $fixtures['employee']->id)
        ->where('company_id', $fixtures['company']->id)
        ->firstOrFail();

    $contract->update([
        'basic_salary' => $basic,
        'site_allowance' => $site,
        'supplementary_allowance' => $supp,
    ]);

    $contract = $contract->fresh();
    (new SyncContractSalaryComponentsFromContract)->handle($contract);

    return $contract->fresh();
}

function assertPayrollReconciles(PayrollRecord $record): void
{
    $gross = (float) $record->gross_salary;
    $net = (float) $record->net_salary;
    $deductions = (float) $record->other_deductions;
    $bonus = (float) $record->bonus;

    expect(round($gross - $deductions, 2))->toBe(round($net, 2))
        ->and(round((float) $record->basic_salary + (float) $record->other_allowances + (float) $record->overtime_pay + $bonus, 2))
        ->toBeGreaterThanOrEqual(round($gross - 0.01, 2));
}

test('normal mobilisation produces correct payable days and payroll record', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-21 12:00:00', 'Asia/Dubai'));

    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['company']->update(['timezone' => 'Asia/Dubai']);
    setDailyCrewContractRates($fixtures, 100, 30, 20);
    makeSeptemberDailyCrewPeriod($fixtures);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::PreMobilisation, 1, '2026-09-01 08:00:00', '2026-09-02 08:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::TravelIn, 2, '2026-09-02 08:00:00', '2026-09-03 08:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 3, '2026-09-03 08:00:00', '2026-09-06 08:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::ReadyToJoin, 4, '2026-09-06 08:00:00', '2026-09-07 08:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 5, '2026-09-07 08:00:00', '2026-09-20 18:00:00', CrewPhaseStatus::Completed);

    ['preparation' => $preparation, 'timesheet' => $timesheet, 'record' => $record] = runDailyCrewPayrollPipeline($fixtures);

    $standbyLine = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('pay_category', CrewTimesheetPayCategory::SignOnStandby)
        ->firstOrFail();
    $onsiteLine = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('pay_category', CrewTimesheetPayCategory::Onsite)
        ->firstOrFail();

    expect((float) $standbyLine->days)->toBe(4.0)
        ->and((float) $onsiteLine->days)->toBe(14.0)
        ->and((float) $timesheet->sign_on_standby_days)->toBe(4.0)
        ->and((float) $timesheet->onsite_days)->toBe(14.0)
        ->and($timesheet->source)->toBe(CrewTimesheetSource::CrewOperations)
        ->and($timesheet->movement_source_hash)->toBe($preparation->source_hash)
        ->and($record)->not->toBeNull()
        ->and((float) $record->basic_salary)->toBe(1800.0)
        ->and((float) $record->calculation_breakdown['sign_on_standby_days'])->toBe(4.0)
        ->and((float) $record->calculation_breakdown['onsite_days'])->toBe(14.0);

    assertPayrollReconciles($record);
});

test('training loop preserves payable standby days once without duplicate calendar dates', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-21 12:00:00', 'Asia/Dubai'));

    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['company']->update(['timezone' => 'Asia/Dubai']);
    setDailyCrewContractRates($fixtures, 100, 30, 20);
    makeSeptemberDailyCrewPeriod($fixtures);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-09-03 08:00:00', '2026-09-04 18:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::Training, 2, '2026-09-05 08:00:00', '2026-09-06 18:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::ReadyToJoin, 3, '2026-09-07 08:00:00', '2026-09-08 08:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::Training, 4, '2026-09-09 08:00:00', '2026-09-10 18:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::ReadyToJoin, 5, '2026-09-11 08:00:00', '2026-09-12 08:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 6, '2026-09-12 08:00:00', '2026-09-18 18:00:00', CrewPhaseStatus::Completed);

    ['preparation' => $preparation, 'record' => $record] = runDailyCrewPayrollPipeline($fixtures);

    $standbyDays = (float) CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('pay_category', CrewTimesheetPayCategory::SignOnStandby)
        ->sum('days');

    expect($standbyDays)->toBe(9.0)
        ->and(overlapWarningExists($preparation->id))->toBeFalse()
        ->and((float) $record->calculation_breakdown['onsite_days'])->toBe(7.0);

    assertPayrollReconciles($record);
});

test('vessel transfer allocates shared calendar day once without duplicate payroll payment', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-21 12:00:00', 'Asia/Dubai'));

    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['company']->update(['timezone' => 'Asia/Dubai']);
    setDailyCrewContractRates($fixtures, 100, 30, 20);
    makeSeptemberDailyCrewPeriod($fixtures);

    $vesselB = makeCrewMovementVessel('Transfer B', $fixtures['company']);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 1, '2026-09-10 08:00:00', '2026-09-15 12:00:00', CrewPhaseStatus::Completed);

    $destination = CrewAssignment::query()->create([
        'company_id' => $fixtures['company']->id,
        'assignment_no' => 'CA-DST-'.fake()->unique()->numerify('######'),
        'employee_id' => $fixtures['employee']->id,
        'rank_id' => $fixtures['rank']->id,
        'vessel_id' => $vesselB->id,
        'previous_assignment_id' => $fixtures['assignment']->id,
        'status' => CrewAssignmentStatus::Active,
        'source' => 'vessel_transfer',
    ]);

    addTimelinePhase($destination, CrewPhaseCode::OnVessel, 1, '2026-09-15 12:00:00', '2026-09-20 18:00:00', CrewPhaseStatus::Completed);

    ['preparation' => $preparation, 'timesheet' => $timesheet, 'record' => $record] = runDailyCrewPayrollPipeline($fixtures);

    expect(overlapWarningExists($preparation->id))->toBeFalse()
        ->and((float) $timesheet->onsite_days)->toBe(11.0)
        ->and(CrewTimesheetSegment::query()->where('crew_timesheet_id', $timesheet->id)->count())->toBe(2);

    assertPayrollReconciles($record);
});

test('cancellation after actual standby preserves legitimate standby pay through payroll', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-10 12:00:00', 'Asia/Dubai'));

    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['company']->update(['timezone' => 'Asia/Dubai']);
    setDailyCrewContractRates($fixtures, 100, 30, 20);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-03 08:00:00', '2026-07-06 18:00:00', CrewPhaseStatus::Completed);
    $fixtures['assignment']->update(['status' => CrewAssignmentStatus::Cancelled]);

    ['record' => $record] = runDailyCrewPayrollPipeline($fixtures);

    expect((float) $record->calculation_breakdown['sign_on_standby_days'])->toBe(4.0)
        ->and((float) $record->basic_salary)->toBe(400.0)
        ->and((float) $record->gross_salary)->toBeGreaterThan(400.0);

    assertPayrollReconciles($record);
});

test('cancellation before activity produces zero payable payroll', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-10 12:00:00', 'Asia/Dubai'));

    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['company']->update(['timezone' => 'Asia/Dubai']);
    setDailyCrewContractRates($fixtures, 100, 30, 20);

    CrewAssignmentPhase::query()->create([
        'company_id' => $fixtures['assignment']->company_id,
        'crew_assignment_id' => $fixtures['assignment']->id,
        'phase_code' => CrewPhaseCode::JoinStandby,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Planned,
        'planned_start_at' => CarbonImmutable::parse('2026-07-03 08:00:00', 'Asia/Dubai'),
        'actual_start_at' => null,
        'actual_end_at' => null,
    ]);
    $fixtures['assignment']->update(['status' => CrewAssignmentStatus::Cancelled]);

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $payableLines = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('days', '>', 0)
        ->whereIn('pay_category', PayableCrewPreparationLines::payableCategories())
        ->count();

    expect($payableLines)->toBe(0);

    expect(fn () => app(GenerateCrewPayroll::class)->handle($fixtures['period']->fresh()))
        ->toThrow(ValidationException::class);
});

test('disembarkation p4 p5 p6 classification and payroll are correct', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-21 12:00:00', 'Asia/Dubai'));

    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['company']->update(['timezone' => 'Asia/Dubai']);
    setDailyCrewContractRates($fixtures, 100, 30, 20);
    makeSeptemberDailyCrewPeriod($fixtures);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 1, '2026-09-10 08:00:00', '2026-09-15 12:00:00', CrewPhaseStatus::Completed);
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::DemobStandby, 2, '2026-09-15 12:00:00', '2026-09-17 18:00:00', CrewPhaseStatus::Completed);
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::HomeRedeploy, 3, '2026-09-18 08:00:00', '2026-09-20 18:00:00', CrewPhaseStatus::Completed);

    ['record' => $record] = runDailyCrewPayrollPipeline($fixtures);

    expect((float) $record->calculation_breakdown['onsite_days'])->toBe(6.0)
        ->and((float) $record->calculation_breakdown['sign_off_standby_days'])->toBe(2.0)
        ->and((float) $record->calculation_breakdown['lines']['site_allowance'] ?? 0)->toBeGreaterThan(0);

    assertPayrollReconciles($record);
});

test('movement correction invalidates unapplied preparation and rebuilt payroll uses corrected timeline', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-21 12:00:00', 'Asia/Dubai'));

    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['company']->update(['timezone' => 'Asia/Dubai']);
    setDailyCrewContractRates($fixtures, 100, 30, 20);
    makeSeptemberDailyCrewPeriod($fixtures);

    $phase = addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::OnVessel,
        1,
        '2026-09-10 08:00:00',
        '2026-09-15 18:00:00',
        CrewPhaseStatus::Completed,
    );

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $correction = CrewMovementCorrection::factory()
        ->forAssignment($fixtures['assignment'], $phase)
        ->pending()
        ->create([
            'company_id' => $fixtures['company']->id,
            'requested_by' => $fixtures['user']->id,
        ]);

    expect(app(CrewTimelineFreshnessChecker::class)->isFresh($preparation, $fixtures['period']))->toBeFalse();

    $phase->update(['actual_end_at' => CarbonImmutable::parse('2026-09-17 18:00:00', 'Asia/Dubai')]);
    $correction->delete();

    ['record' => $record] = runDailyCrewPayrollPipeline($fixtures);

    expect((float) $record->calculation_breakdown['onsite_days'])->toBe(8.0);

    assertPayrollReconciles($record);
});

test('month boundary allocates august and september payroll separately', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-21 12:00:00', 'Asia/Dubai'));

    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['company']->update(['timezone' => 'Asia/Dubai']);
    setDailyCrewContractRates($fixtures, 100, 30, 20);

    $august = PayrollPeriod::factory()->for($fixtures['company'])->crewOperations()->create([
        'status' => PayrollPeriodStatus::Draft,
        'payroll_category' => PayrollCategory::Crew,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
        'payment_date' => '2026-08-31',
    ]);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-08-29 08:00:00', '2026-08-31 18:00:00', CrewPhaseStatus::Completed);
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 2, '2026-09-01 08:00:00', '2026-09-20 18:00:00', CrewPhaseStatus::Completed);

    $augustFixtures = $fixtures;
    $augustFixtures['period'] = $august;
    ['record' => $augustRecord] = runDailyCrewPayrollPipeline($augustFixtures);

    $fixtures['period'] = makeSeptemberDailyCrewPeriod($fixtures);
    ['record' => $septemberRecord] = runDailyCrewPayrollPipeline($fixtures);

    expect((float) $augustRecord->calculation_breakdown['sign_on_standby_days'])->toBe(3.0)
        ->and((float) $augustRecord->calculation_breakdown['onsite_days'])->toBe(0.0)
        ->and((float) $septemberRecord->calculation_breakdown['onsite_days'])->toBe(20.0)
        ->and((float) $septemberRecord->calculation_breakdown['sign_on_standby_days'])->toBe(0.0);
});

test('open onboard employee is capped at company local current date', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-17 12:00:00', 'Asia/Dubai'));

    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['company']->update(['timezone' => 'Asia/Dubai']);
    setDailyCrewContractRates($fixtures, 100, 30, 20);
    makeSeptemberDailyCrewPeriod($fixtures);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 1, '2026-09-10 08:00:00', null, CrewPhaseStatus::Active);

    ['preparation' => $preparation, 'record' => $record] = runDailyCrewPayrollPipeline($fixtures);

    expect($preparation->effective_cutoff_date?->toDateString())->toBe('2026-09-17')
        ->and((float) $record->calculation_breakdown['onsite_days'])->toBe(8.0);

    assertPayrollReconciles($record);
});

test('contract rate boundary resolves per work date across payroll generation', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-21 12:00:00', 'Asia/Dubai'));

    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['company']->update(['timezone' => 'Asia/Dubai']);
    makeSeptemberDailyCrewPeriod($fixtures);

    EmployeeContract::query()
        ->where('employee_id', $fixtures['employee']->id)
        ->where('company_id', $fixtures['company']->id)
        ->update(['status' => 'inactive']);

    $contractA = EmployeeContract::factory()->create([
        'employee_id' => $fixtures['employee']->id,
        'company_id' => $fixtures['company']->id,
        'payroll_category' => PayrollCategory::Crew,
        'salary_structure' => ContractSalaryStructure::Daily,
        'status' => 'active',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-15',
        'basic_salary' => 100,
        'site_allowance' => 30,
        'supplementary_allowance' => 20,
    ]);
    (new SyncContractSalaryComponentsFromContract)->handle($contractA);

    $contractB = EmployeeContract::factory()->create([
        'employee_id' => $fixtures['employee']->id,
        'company_id' => $fixtures['company']->id,
        'payroll_category' => PayrollCategory::Crew,
        'salary_structure' => ContractSalaryStructure::Daily,
        'status' => 'active',
        'start_date' => '2026-09-16',
        'end_date' => null,
        'basic_salary' => 110,
        'site_allowance' => 35,
        'supplementary_allowance' => 25,
    ]);
    (new SyncContractSalaryComponentsFromContract)->handle($contractB);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 1, '2026-09-14 08:00:00', '2026-09-17 18:00:00', CrewPhaseStatus::Completed);

    ['record' => $record] = runDailyCrewPayrollPipeline($fixtures);

    expect((float) $record->calculation_breakdown['onsite_days'])->toBe(4.0)
        ->and((float) $record->basic_salary)->toBe(420.0)
        ->and((float) $record->gross_salary)->toBeGreaterThan(420.0);

    assertPayrollReconciles($record);
});

test('standby payroll keeps supplementary allowance out of basic salary component', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-21 12:00:00', 'Asia/Dubai'));

    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['company']->update(['timezone' => 'Asia/Dubai']);
    setDailyCrewContractRates($fixtures, 100, 0, 20);
    makeSeptemberDailyCrewPeriod($fixtures);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-09-01 08:00:00', '2026-09-03 18:00:00', CrewPhaseStatus::Completed);

    ['record' => $record] = runDailyCrewPayrollPipeline($fixtures);

    expect((float) $record->calculation_breakdown['sign_on_standby_days'])->toBe(3.0)
        ->and((float) $record->basic_salary)->toBe(300.0)
        ->and((float) $record->other_allowances)->toBe(60.0)
        ->and((float) $record->gross_salary)->toBe(360.0)
        ->and((float) $record->net_salary)->toBe(360.0);

    assertPayrollReconciles($record);
});

test('three onsite days assert exact basic site and supplementary payroll components', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-21 12:00:00', 'Asia/Dubai'));

    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['company']->update(['timezone' => 'Asia/Dubai']);
    makeSeptemberDailyCrewPeriod($fixtures);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 1, '2026-09-10 08:00:00', '2026-09-12 18:00:00', CrewPhaseStatus::Completed);

    ['record' => $record] = runDailyCrewPayrollPipeline($fixtures);

    $breakdown = $record->calculation_breakdown;

    expect((float) $breakdown['onsite_days'])->toBe(3.0)
        ->and((float) $record->basic_salary)->toBe(300.0)
        ->and((float) $breakdown['lines']['site_allowance'])->toBe(90.0)
        ->and((float) $breakdown['lines']['supplementary_allowance'])->toBe(60.0)
        ->and((float) $record->other_allowances)->toBe(150.0)
        ->and((float) $record->gross_salary)->toBe(450.0)
        ->and((float) $record->net_salary)->toBe(450.0);

    assertPayrollReconciles($record);
});

test('additions and deductions survive apply and affect generated payroll net', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-21 12:00:00', 'Asia/Dubai'));

    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['company']->update(['timezone' => 'Asia/Dubai']);
    setDailyCrewContractRates($fixtures, 100, 30, 20);
    makeSeptemberDailyCrewPeriod($fixtures);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 1, '2026-09-10 08:00:00', '2026-09-12 18:00:00', CrewPhaseStatus::Completed);

    ['record' => $record] = runDailyCrewPayrollPipeline($fixtures, null, [
        'overtime_hours' => 5,
        'overtime_amount' => 0,
        'additional_amount' => 150,
        'deduction_amount' => 40,
    ]);

    expect((float) $record->bonus)->toBe(150.0)
        ->and((float) $record->other_deductions)->toBe(40.0)
        ->and((float) $record->net_salary)->toBe((float) $record->gross_salary - 40.0);
});

test('monthly crew contract produces no payable daily preparation lines', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-21 12:00:00', 'Asia/Dubai'));

    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['company']->update(['timezone' => 'Asia/Dubai']);
    makeSeptemberDailyCrewPeriod($fixtures);

    EmployeeContract::query()
        ->where('employee_id', $fixtures['employee']->id)
        ->where('company_id', $fixtures['company']->id)
        ->update(['status' => 'inactive']);

    $contract = EmployeeContract::factory()->create([
        'employee_id' => $fixtures['employee']->id,
        'company_id' => $fixtures['company']->id,
        'payroll_category' => PayrollCategory::Crew,
        'salary_structure' => ContractSalaryStructure::Monthly,
        'status' => 'active',
        'start_date' => '2026-01-01',
        'end_date' => null,
        'basic_salary' => 8000,
    ]);
    (new SyncContractSalaryComponentsFromContract)->handle($contract);

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 1, '2026-09-10 08:00:00', '2026-09-15 18:00:00', CrewPhaseStatus::Completed);

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $lines = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('employee_id', $fixtures['employee']->id)
        ->get();

    $payableDailyLines = $lines
        ->filter(fn (CrewTimesheetPreparationLine $line): bool => PayableCrewPreparationLines::isPayable($line))
        ->count();

    expect($payableDailyLines)->toBe(0);
});

test('payroll generation readiness requires applied crew operations preparation', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    makeSeptemberDailyCrewPeriod($fixtures);
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 1, '2026-09-10 08:00:00', '2026-09-15 18:00:00', CrewPhaseStatus::Completed);

    $guard = app(CrewOperationsPayrollGenerationGuard::class);

    expect($guard->readiness($fixtures['period'], (int) $fixtures['company']->id)['ready'])->toBeFalse();

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    expect($guard->readiness($fixtures['period']->fresh(), (int) $fixtures['company']->id)['ready'])->toBeFalse();

    app(SubmitCrewTimesheetPreparation::class)->handle(
        $fixtures['period'],
        $preparation,
        $fixtures['user'],
        (int) $fixtures['company']->id,
    );
    app(ApproveCrewTimesheetPreparation::class)->handle(
        $fixtures['period'],
        $preparation->fresh(),
        $fixtures['user'],
        (int) $fixtures['company']->id,
    );

    expect($guard->readiness($fixtures['period']->fresh(), (int) $fixtures['company']->id)['ready'])->toBeFalse();

    grantApplyPermissions($fixtures['user'], $fixtures['company']);
    app(ApplyCrewTimesheetPreparation::class)->handle(
        $fixtures['period'],
        $preparation->fresh(),
        $fixtures['user'],
        (int) $fixtures['company']->id,
    );

    expect($guard->readiness($fixtures['period']->fresh(), (int) $fixtures['company']->id)['ready'])->toBeTrue();
});

test('applied payroll snapshot remains unchanged when live crew movement advances later', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-17 12:00:00', 'Asia/Dubai'));

    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['company']->update(['timezone' => 'Asia/Dubai']);
    setDailyCrewContractRates($fixtures, 100, 30, 20);
    makeSeptemberDailyCrewPeriod($fixtures);

    $phase = addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 1, '2026-09-10 08:00:00', null, CrewPhaseStatus::Active);

    ['timesheet' => $timesheet, 'record' => $record] = runDailyCrewPayrollPipeline($fixtures);
    $originalGross = (float) $record->gross_salary;
    $originalHash = $timesheet->movement_source_hash;

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-20 12:00:00', 'Asia/Dubai'));
    $phase->update([
        'actual_end_at' => CarbonImmutable::parse('2026-09-19 18:00:00', 'Asia/Dubai'),
        'status' => CrewPhaseStatus::Completed,
    ]);

    $timesheet->refresh();
    $record->refresh();

    expect((float) $timesheet->onsite_days)->toBe(8.0)
        ->and($timesheet->movement_source_hash)->toBe($originalHash)
        ->and((float) $record->gross_salary)->toBe($originalGross);
});

test('apply rejects cross company payroll period access', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-21 12:00:00', 'Asia/Dubai'));

    $fixtures = makeDailyCrewTimelineFixtures();
    makeSeptemberDailyCrewPeriod($fixtures);
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 1, '2026-09-10 08:00:00', '2026-09-12 18:00:00', CrewPhaseStatus::Completed);

    $other = makeCrewAssignmentFixtures();
    grantApplyPermissions($fixtures['user'], $fixtures['company']);

    ['preparation' => $preparation] = runDailyCrewPayrollPipeline($fixtures);

    expect(fn () => app(ApplyCrewTimesheetPreparation::class)->handle(
        $fixtures['period'],
        $preparation->fresh(),
        $fixtures['user'],
        (int) $other['company']->id,
    ))->toThrow(ModelNotFoundException::class);
});
