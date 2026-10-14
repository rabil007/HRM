<?php

use App\Enums\CrewMovementCorrectionStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewTimesheetPayCategory;
use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Enums\PayrollWorkAllocationStatus;
use App\Enums\PayrollWorkPeriodClassification;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentSequence;
use App\Models\CrewMovementCorrection;
use App\Models\CrewPlanningAssignment;
use App\Models\EmployeeContract;
use App\Models\EmployeeSeaService;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\PayrollWorkAllocation;
use App\Models\Vessel;
use App\Support\CrewMovements\CrewAssignmentNumberGenerator;
use App\Support\CrewOperations\Reset\CrewOperationsResetAnalyzer;
use App\Support\CrewOperations\Reset\CrewOperationsResetEnvironmentGuard;
use App\Support\CrewOperations\Reset\CrewOperationsResetExecutor;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;

const CREW_RESET_CONFIRMATION = 'RESET CREW OPERATIONS DATA';

function runCrewResetWithConfirmation(TestCase $test, ?int $companyId = null): PendingCommand
{
    $parameters = $companyId !== null ? ['--company' => $companyId] : [];

    return $test->artisan('crew:reset', $parameters)
        ->expectsQuestion('Type `'.CREW_RESET_CONFIRMATION.'` to permanently delete the rows listed above', CREW_RESET_CONFIRMATION);
}

test('dry run does not mutate crew operations data', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 1, '2026-07-01 08:00:00', '2026-07-10 18:00:00');

    $assignmentCount = CrewAssignment::query()->count();

    $this->artisan('crew:reset', ['--dry-run' => true, '--company' => $fixtures['company']->id])
        ->assertSuccessful();

    expect(CrewAssignment::query()->count())->toBe($assignmentCount);
});

test('environment guard refuses production runtime', function () {
    $originalEnv = app()->environment();

    app()->detectEnvironment(static fn () => 'production');
    config(['crew_operations_reset.allow_in_testing' => false]);

    try {
        app(CrewOperationsResetEnvironmentGuard::class)->assertMayRun();
        expect(false)->toBeTrue('Expected guard to throw');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toContain('only available in local or development');
    } finally {
        app()->detectEnvironment(static fn () => $originalEnv);
        config(['crew_operations_reset.allow_in_testing' => true]);
    }
});

test('reset requires exact confirmation phrase', function () {
    $fixtures = makeDailyCrewTimelineFixtures();

    $this->artisan('crew:reset', ['--company' => $fixtures['company']->id])
        ->expectsQuestion('Type `'.CREW_RESET_CONFIRMATION.'` to permanently delete the rows listed above', 'NOPE')
        ->assertFailed();

    expect(CrewAssignment::query()->where('company_id', $fixtures['company']->id)->count())->toBe(1);
});

test('executor clears crew assignments without console confirmation', function () {
    $fixtures = makeDailyCrewTimelineFixtures();

    $plan = app(CrewOperationsResetAnalyzer::class)
        ->analyze($fixtures['company']->id);

    app(CrewOperationsResetExecutor::class)->execute($plan);

    expect(CrewAssignment::query()->where('company_id', $fixtures['company']->id)->count())->toBe(0);
});

test('reset removes crew assignments planning movement and timesheet data', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 1, '2026-07-01 08:00:00', '2026-07-10 18:00:00');

    CrewPlanningAssignment::query()->create([
        'company_id' => $fixtures['company']->id,
        'employee_id' => $fixtures['employee']->id,
        'vessel_id' => $fixtures['vessel']->id,
        'position_id' => $fixtures['rank']->id,
        'crew_assignment_id' => $fixtures['assignment']->id,
        'planned_join_date' => '2026-07-01',
    ]);

    CrewMovementCorrection::query()->create([
        'company_id' => $fixtures['company']->id,
        'crew_assignment_id' => $fixtures['assignment']->id,
        'status' => CrewMovementCorrectionStatus::Pending,
        'original_values' => [],
        'proposed_values' => [],
        'reason' => 'test',
        'requested_by' => $fixtures['user']->id,
        'requested_at' => now(),
    ]);

    runCrewResetWithConfirmation($this, $fixtures['company']->id)->assertSuccessful();

    expect(CrewAssignment::query()->where('company_id', $fixtures['company']->id)->count())->toBe(0)
        ->and(CrewPlanningAssignment::query()->where('company_id', $fixtures['company']->id)->count())->toBe(0)
        ->and(CrewMovementCorrection::query()->where('company_id', $fixtures['company']->id)->count())->toBe(0)
        ->and(DB::table('crew_timesheets')->where('company_id', $fixtures['company']->id)->count())->toBe(0);
});

test('reset removes linked crew payroll but preserves office payroll and manual crew payroll', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 1, '2026-07-01 08:00:00', '2026-07-15 18:00:00', CrewPhaseStatus::Completed);
    runDailyCrewPayrollPipeline($fixtures);

    $linkedCrewRecordId = PayrollRecord::query()
        ->where('company_id', $fixtures['company']->id)
        ->where('payroll_category', PayrollCategory::Crew)
        ->value('id');

    expect($linkedCrewRecordId)->not->toBeNull();

    $officePeriod = PayrollPeriod::factory()->for($fixtures['company'])->create([
        'payroll_category' => PayrollCategory::Office,
        'status' => PayrollPeriodStatus::Draft,
    ]);

    $officeRecord = PayrollRecord::factory()->create([
        'company_id' => $fixtures['company']->id,
        'employee_id' => $fixtures['employee']->id,
        'period_id' => $officePeriod->id,
        'payroll_category' => PayrollCategory::Office,
    ]);

    $manualCrewPeriod = PayrollPeriod::factory()->for($fixtures['company'])->crewOperations()->create([
        'status' => PayrollPeriodStatus::Draft,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
    ]);

    $manualCrewRecord = PayrollRecord::factory()->create([
        'company_id' => $fixtures['company']->id,
        'employee_id' => $fixtures['employee']->id,
        'period_id' => $manualCrewPeriod->id,
        'payroll_category' => PayrollCategory::Crew,
    ]);

    runCrewResetWithConfirmation($this, $fixtures['company']->id)->assertSuccessful();

    expect(PayrollRecord::query()->whereKey($linkedCrewRecordId)->exists())->toBeFalse()
        ->and(PayrollRecord::query()->whereKey($officeRecord->id)->exists())->toBeTrue()
        ->and(PayrollRecord::query()->whereKey($manualCrewRecord->id)->exists())->toBeTrue()
        ->and($fixtures['employee']->fresh())->not->toBeNull()
        ->and(Vessel::query()->whereKey($fixtures['vessel']->id)->exists())->toBeTrue();
});

test('reset preserves manual sea service and only removes assignment-synced sea service', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $phase = addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 1, '2026-07-01 08:00:00', '2026-07-05 18:00:00');

    $synced = EmployeeSeaService::query()->create([
        'company_id' => $fixtures['company']->id,
        'employee_id' => $fixtures['employee']->id,
        'crew_assignment_phase_id' => $phase->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-05',
        'sort_order' => 1,
    ]);

    $manual = EmployeeSeaService::query()->create([
        'company_id' => $fixtures['company']->id,
        'employee_id' => $fixtures['employee']->id,
        'crew_assignment_phase_id' => null,
        'start_date' => '2020-01-01',
        'end_date' => '2020-06-01',
        'sort_order' => 2,
    ]);

    runCrewResetWithConfirmation($this, $fixtures['company']->id)->assertSuccessful();

    expect(EmployeeSeaService::query()->whereKey($synced->id)->exists())->toBeFalse()
        ->and(EmployeeSeaService::query()->whereKey($manual->id)->exists())->toBeTrue();
});

test('company scoped reset does not delete another company crew assignments', function () {
    $fixturesA = makeDailyCrewTimelineFixtures();
    $fixturesB = makeDailyCrewTimelineFixtures();

    runCrewResetWithConfirmation($this, $fixturesA['company']->id)->assertSuccessful();

    expect(CrewAssignment::query()->where('company_id', $fixturesA['company']->id)->count())->toBe(0)
        ->and(CrewAssignment::query()->where('company_id', $fixturesB['company']->id)->count())->toBe(1);
});

test('assignment numbering restarts at CA-2026-000001 after reset', function () {
    $fixtures = makeDailyCrewTimelineFixtures();

    CrewAssignmentSequence::query()->updateOrCreate(
        ['company_id' => $fixtures['company']->id, 'year' => 2026],
        ['last_number' => 42],
    );

    runCrewResetWithConfirmation($this, $fixtures['company']->id)->assertSuccessful();

    $generator = new CrewAssignmentNumberGenerator;
    $next = $generator->next($fixtures['company']->id, 2026);

    expect($next)->toBe('CA-2026-000001');
});

test('unexpected office payroll allocation linkage blocks reset', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $contractId = EmployeeContract::query()
        ->where('employee_id', $fixtures['employee']->id)
        ->where('company_id', $fixtures['company']->id)
        ->value('id');

    $officePeriod = PayrollPeriod::factory()->for($fixtures['company'])->create([
        'payroll_category' => PayrollCategory::Office,
        'status' => PayrollPeriodStatus::Draft,
    ]);

    $officeRecord = PayrollRecord::factory()->create([
        'company_id' => $fixtures['company']->id,
        'employee_id' => $fixtures['employee']->id,
        'period_id' => $officePeriod->id,
        'payroll_category' => PayrollCategory::Office,
        'contract_id' => $contractId,
    ]);

    PayrollWorkAllocation::query()->create([
        'company_id' => $fixtures['company']->id,
        'employee_id' => $fixtures['employee']->id,
        'payroll_period_id' => $officePeriod->id,
        'payroll_record_id' => $officeRecord->id,
        'crew_assignment_id' => $fixtures['assignment']->id,
        'contract_id' => $contractId,
        'work_date' => '2026-07-01',
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'period_classification' => PayrollWorkPeriodClassification::Current,
        'status' => PayrollWorkAllocationStatus::Reserved,
        'basic_daily_rate' => 0,
        'site_allowance_daily_rate' => 0,
        'supplementary_allowance_daily_rate' => 0,
        'basic_amount' => 0,
        'site_allowance_amount' => 0,
        'supplementary_allowance_amount' => 0,
        'total_amount' => 0,
        'active_allocation_key' => sprintf('%d:%d:%s', $fixtures['company']->id, $fixtures['employee']->id, '2026-07-01'),
    ]);

    $this->artisan('crew:reset', ['--dry-run' => true, '--company' => $fixtures['company']->id])
        ->assertFailed();

    expect(CrewAssignment::query()->whereKey($fixtures['assignment']->id)->exists())->toBeTrue();
});
