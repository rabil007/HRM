<?php

use App\Enums\CrewMovementCorrectionStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewTimelineWarningCode;
use App\Enums\CrewTimesheetPayCategory;
use App\Enums\CrewTimesheetPreparationStatus;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollPeriodStatus;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewMovementCorrection;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetPreparation;
use App\Models\CrewTimesheetPreparationLine;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Support\Payroll\Actions\GenerateCrewPayroll;
use App\Support\Payroll\Actions\UpsertCrewTimesheet;
use App\Support\Payroll\CrewOperationsPayrollGenerationGuard;
use App\Support\Payroll\CrewTimeline\Actions\ApplyCrewTimesheetPreparation;
use App\Support\Payroll\CrewTimeline\PrepareCrewTimesheetTimeline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

function applyApprovedTimeline(array $fixtures): CrewTimesheetPreparation
{
    ['preparation' => $preparation, 'approver' => $approver] = prepareApprovedTimeline($fixtures);

    app(ApplyCrewTimesheetPreparation::class)->handle(
        $fixtures['period'],
        $preparation,
        $approver,
        (int) $fixtures['company']->id,
    );

    return $preparation->fresh();
}

test('overtime-only import preserves existing additions and deductions on an applied timesheet', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantApplyPermissions($fixtures['user'], $fixtures['company']);
    applyApprovedTimeline($fixtures);

    $timesheet = CrewTimesheet::query()
        ->where('employee_id', $fixtures['employee']->id)
        ->where('period_id', $fixtures['period']->id)
        ->firstOrFail();

    $timesheet->update([
        'additional_amount' => 500,
        'deduction_amount' => 100,
        'overtime_hours' => 3,
    ]);

    app(UpsertCrewTimesheet::class)->handle(
        $fixtures['period'],
        $fixtures['employee'],
        ['overtime_hours' => 8, 'source' => CrewTimesheetSource::Import],
    );

    $timesheet->refresh();

    expect((float) $timesheet->overtime_hours)->toBe(8.0)
        ->and((float) $timesheet->additional_amount)->toBe(500.0)
        ->and((float) $timesheet->deduction_amount)->toBe(100.0);
});

test('remarks-only import preserves all financial amounts on an applied timesheet', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantApplyPermissions($fixtures['user'], $fixtures['company']);
    applyApprovedTimeline($fixtures);

    $timesheet = CrewTimesheet::query()
        ->where('employee_id', $fixtures['employee']->id)
        ->where('period_id', $fixtures['period']->id)
        ->firstOrFail();

    $timesheet->update(['additional_amount' => 250, 'deduction_amount' => 75, 'overtime_hours' => 6]);

    app(UpsertCrewTimesheet::class)->handle(
        $fixtures['period'],
        $fixtures['employee'],
        ['remarks' => 'Reviewed by crewing'],
    );

    $timesheet->refresh();

    expect($timesheet->remarks)->toBe('Reviewed by crewing')
        ->and((float) $timesheet->additional_amount)->toBe(250.0)
        ->and((float) $timesheet->deduction_amount)->toBe(75.0)
        ->and((float) $timesheet->overtime_hours)->toBe(6.0);
});

test('explicit zero clears a financial value while explicit amount updates it', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantApplyPermissions($fixtures['user'], $fixtures['company']);
    applyApprovedTimeline($fixtures);

    $timesheet = CrewTimesheet::query()
        ->where('employee_id', $fixtures['employee']->id)
        ->where('period_id', $fixtures['period']->id)
        ->firstOrFail();

    $timesheet->update(['additional_amount' => 500, 'deduction_amount' => 100]);

    app(UpsertCrewTimesheet::class)->handle(
        $fixtures['period'],
        $fixtures['employee'],
        ['additional_amount' => 0, 'deduction_amount' => 250],
    );

    $timesheet->refresh();

    expect((float) $timesheet->additional_amount)->toBe(0.0)
        ->and((float) $timesheet->deduction_amount)->toBe(250.0);
});

test('ui readiness and backend generation return the same blocking reason when no applied timeline exists', function () {
    $fixtures = makeDailyCrewTimelineFixtures();

    $readiness = app(CrewOperationsPayrollGenerationGuard::class)
        ->readiness($fixtures['period'], (int) $fixtures['company']->id);

    $generationMessage = null;

    try {
        app(GenerateCrewPayroll::class)->handle($fixtures['period']->fresh());
    } catch (ValidationException $exception) {
        $generationMessage = $exception->errors()['period_id'][0] ?? null;
    }

    expect($readiness['ready'])->toBeFalse()
        ->and($readiness['blocking_reason'])->toBe(CrewOperationsPayrollGenerationGuard::MISSING_APPLIED_MESSAGE)
        ->and($generationMessage)->toBe($readiness['blocking_reason']);
});

test('excluded-only employee does not block crew operations generation readiness', function () {
    $fixtures = makeDailyCrewTimelineFixtures();

    $preparation = CrewTimesheetPreparation::query()->create([
        'company_id' => $fixtures['company']->id,
        'payroll_period_id' => $fixtures['period']->id,
        'version' => 1,
        'status' => CrewTimesheetPreparationStatus::Applied,
        'source_hash' => 'hash-excluded',
        'applied_by' => $fixtures['user']->id,
        'applied_at' => now(),
    ]);

    CrewTimesheetPreparationLine::query()->create([
        'company_id' => $fixtures['company']->id,
        'crew_timesheet_preparation_id' => $preparation->id,
        'employee_id' => $fixtures['employee']->id,
        'crew_assignment_id' => $fixtures['assignment']->id,
        'crew_assignment_phase_id' => null,
        'phase_code' => CrewPhaseCode::PreMobilisation,
        'pay_category' => CrewTimesheetPayCategory::Excluded,
        'from_date' => '2026-07-01',
        'to_date' => '2026-07-01',
        'days' => 0,
        'warning_code' => null,
        'remarks' => 'Excluded phase',
    ]);

    $readiness = app(CrewOperationsPayrollGenerationGuard::class)->validateReadiness(
        $fixtures['period']->fresh(),
        collect([$fixtures['employee']]),
        (int) $fixtures['company']->id,
    );

    expect($readiness['ready'])->toBeTrue();
});

test('payable daily employee without a linked timesheet blocks generation readiness', function () {
    $fixtures = makeDailyCrewTimelineFixtures();

    $preparation = CrewTimesheetPreparation::query()->create([
        'company_id' => $fixtures['company']->id,
        'payroll_period_id' => $fixtures['period']->id,
        'version' => 1,
        'status' => CrewTimesheetPreparationStatus::Applied,
        'source_hash' => 'hash-payable',
        'applied_by' => $fixtures['user']->id,
        'applied_at' => now(),
    ]);

    CrewTimesheetPreparationLine::query()->create([
        'company_id' => $fixtures['company']->id,
        'crew_timesheet_preparation_id' => $preparation->id,
        'employee_id' => $fixtures['employee']->id,
        'crew_assignment_id' => $fixtures['assignment']->id,
        'crew_assignment_phase_id' => null,
        'phase_code' => CrewPhaseCode::OnVessel,
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'from_date' => '2026-07-01',
        'to_date' => '2026-07-05',
        'days' => 5,
        'warning_code' => null,
        'remarks' => 'Onsite',
    ]);

    $readiness = app(CrewOperationsPayrollGenerationGuard::class)->validateReadiness(
        $fixtures['period']->fresh(),
        collect([$fixtures['employee']]),
        (int) $fixtures['company']->id,
    );

    expect($readiness['ready'])->toBeFalse()
        ->and($readiness['affected_employee_id'])->toBe((int) $fixtures['employee']->id);
});

test('empty approved preparation applies, repeated apply is idempotent, and generation does not error', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantApplyPermissions($fixtures['user'], $fixtures['company']);

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $preparation->update([
        'status' => CrewTimesheetPreparationStatus::Approved,
        'approved_by' => $fixtures['user']->id,
        'approved_at' => now(),
    ]);

    $first = app(ApplyCrewTimesheetPreparation::class)->handle(
        $fixtures['period'],
        $preparation,
        $fixtures['user'],
        (int) $fixtures['company']->id,
    );

    $second = app(ApplyCrewTimesheetPreparation::class)->handle(
        $fixtures['period'],
        $preparation->fresh(),
        $fixtures['user'],
        (int) $fixtures['company']->id,
    );

    $result = app(GenerateCrewPayroll::class)->handle($fixtures['period']->fresh());

    expect($first->appliedEmployeeCount)->toBe(0)
        ->and($preparation->fresh()->status)->toBe(CrewTimesheetPreparationStatus::Applied)
        ->and($second->idempotent)->toBeTrue()
        ->and($result->errors)->toBe([])
        ->and(CrewTimesheet::query()->where('period_id', $fixtures['period']->id)->count())->toBe(0);
});

test('a new pending movement correction makes an approved preparation stale', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantApplyPermissions($fixtures['user'], $fixtures['company']);
    ['preparation' => $preparation, 'approver' => $approver] = prepareApprovedTimeline($fixtures);

    $phase = CrewAssignmentPhase::query()
        ->where('crew_assignment_id', $fixtures['assignment']->id)
        ->firstOrFail();

    CrewMovementCorrection::factory()
        ->forAssignment($fixtures['assignment'], $phase)
        ->pending()
        ->create(['status' => CrewMovementCorrectionStatus::Pending]);

    expect(fn () => app(ApplyCrewTimesheetPreparation::class)->handle(
        $fixtures['period'],
        $preparation,
        $approver,
        (int) $fixtures['company']->id,
    ))->toThrow(ValidationException::class);
});

test('a phase missing actual start produces a blocking missing_actual_start warning', function () {
    $fixtures = makeDailyCrewTimelineFixtures();

    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 1, '2026-07-04 08:00:00', '2026-07-10 18:00:00');

    CrewAssignmentPhase::query()->create([
        'company_id' => $fixtures['company']->id,
        'crew_assignment_id' => $fixtures['assignment']->id,
        'phase_code' => CrewPhaseCode::DemobStandby,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Active,
        'planned_start_at' => CarbonImmutable::parse('2026-07-11 08:00:00', 'Asia/Dubai'),
        'planned_end_at' => CarbonImmutable::parse('2026-07-13 18:00:00', 'Asia/Dubai'),
        'actual_start_at' => null,
        'actual_end_at' => null,
    ]);

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $hasMissingActualStart = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('warning_code', CrewTimelineWarningCode::MissingActualStart->value)
        ->exists();

    expect($hasMissingActualStart)->toBeTrue()
        ->and(CrewTimelineWarningCode::MissingActualStart->isBlocking())->toBeTrue();
});

test('preparation soft delete columns exist and history is preserved after soft delete', function () {
    expect(Schema::hasColumn('crew_timesheet_preparations', 'deleted_at'))->toBeTrue()
        ->and(Schema::hasColumn('crew_timesheet_preparation_lines', 'deleted_at'))->toBeTrue();

    $fixtures = makeDailyCrewTimelineFixtures();
    ['preparation' => $preparation] = prepareApprovedTimeline($fixtures);

    $preparationId = $preparation->id;
    $lineCount = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparationId)
        ->count();

    $preparation->lines()->delete();
    $preparation->delete();

    expect(CrewTimesheetPreparation::query()->whereKey($preparationId)->exists())->toBeFalse()
        ->and(CrewTimesheetPreparation::withTrashed()->whereKey($preparationId)->exists())->toBeTrue()
        ->and(CrewTimesheetPreparationLine::withTrashed()
            ->where('crew_timesheet_preparation_id', $preparationId)
            ->count())->toBe($lineCount);
});

test('phases on a local payroll date that cross the UTC boundary are still allocated', function () {
    $fixtures = makeDailyCrewTimelineFixtures();

    addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::JoinStandby,
        1,
        '2026-07-01 02:00:00',
        '2026-07-01 03:00:00',
    );

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $payableLine = CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparation->id)
        ->where('pay_category', CrewTimesheetPayCategory::SignOnStandby->value)
        ->where('days', '>', 0)
        ->first();

    expect($payableLine)->not->toBeNull()
        ->and($payableLine->from_date->toDateString())->toBe('2026-07-01');
});

test('manual crew payroll still generates without an applied timeline', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->manualTimesheets()->create([
        'status' => PayrollPeriodStatus::Draft,
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
        'payment_date' => '2026-06-30',
    ]);

    $employee = createCrewEmployeeWithContract($company, 'CREW-REG-1', 150, 50, 75);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'sign_on_standby_days' => 5,
        'onsite_days' => 10,
        'source' => CrewTimesheetSource::Manual,
    ]);

    $result = app(GenerateCrewPayroll::class)->handle($period->fresh());

    expect($result->errors)->toBe([])
        ->and(PayrollRecord::query()->where('period_id', $period->id)->count())->toBeGreaterThan(0);
});
