<?php

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewTimelineWarningCode;
use App\Enums\CrewTimesheetPayCategory;
use App\Enums\CrewTimesheetPreparationStatus;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollPeriodStatus;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetPreparation;
use App\Models\CrewTimesheetPreparationLine;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Models\SalaryInput;
use App\Models\SalaryInputType;
use App\Models\User;
use App\Support\Payroll\Actions\UpsertCrewTimesheet;
use App\Support\Payroll\CrewTimeline\Actions\ApplyCrewTimesheetPreparation;
use App\Support\Payroll\CrewTimeline\PrepareCrewTimesheetTimeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

test('approved fresh preparation can be applied', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantApplyPermissions($fixtures['user'], $fixtures['company']);
    ['preparation' => $preparation, 'approver' => $approver] = prepareApprovedTimeline($fixtures);

    $existing = CrewTimesheet::factory()->create([
        'company_id' => $fixtures['company']->id,
        'employee_id' => $fixtures['employee']->id,
        'period_id' => $fixtures['period']->id,
        'overtime_hours' => 4.5,
        'additional_amount' => 100,
        'deduction_amount' => 25,
        'remarks' => 'Keep me',
        'sign_on_standby_days' => 1,
        'onsite_days' => 1,
    ]);

    $type = SalaryInputType::query()->create([
        'company_id' => $fixtures['company']->id,
        'name' => 'Bonus',
        'code' => 'bonus_tl_'.fake()->unique()->numerify('###'),
        'is_addition' => true,
        'status' => 'active',
        'sort_order' => 1,
    ]);
    SalaryInput::query()->create([
        'company_id' => $fixtures['company']->id,
        'employee_id' => $fixtures['employee']->id,
        'period_id' => $fixtures['period']->id,
        'salary_input_type_id' => $type->id,
        'amount' => 50,
    ]);

    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.apply', [$fixtures['period'], $preparation]))
        ->assertRedirect(route('payroll.crew-timeline.show', [$fixtures['period'], $preparation]));

    $preparation->refresh();
    $timesheet = CrewTimesheet::query()
        ->where('employee_id', $fixtures['employee']->id)
        ->where('period_id', $fixtures['period']->id)
        ->firstOrFail();

    expect($preparation->status)->toBe(CrewTimesheetPreparationStatus::Applied)
        ->and($preparation->applied_by)->toBe($approver->id)
        ->and($preparation->applied_at)->not->toBeNull()
        ->and($timesheet->source)->toBe(CrewTimesheetSource::CrewOperations)
        ->and($timesheet->crew_timesheet_preparation_id)->toBe($preparation->id)
        ->and($timesheet->operational_approved_by)->toBe($preparation->approved_by)
        ->and($timesheet->movement_source_hash)->toBe($preparation->source_hash)
        ->and((float) $timesheet->sign_on_standby_days)->toBeGreaterThan(0)
        ->and((float) $timesheet->onsite_days)->toBeGreaterThan(0)
        ->and((float) $timesheet->sign_off_standby_days)->toBeGreaterThan(0)
        ->and((float) $timesheet->overtime_hours)->toBe(4.5)
        ->and((float) $timesheet->additional_amount)->toBe(100.0)
        ->and((float) $timesheet->deduction_amount)->toBe(25.0)
        ->and($timesheet->remarks)->toBe('Keep me')
        ->and(SalaryInput::query()->where('employee_id', $fixtures['employee']->id)->count())->toBe(1)
        ->and($existing->id)->toBe($timesheet->id);

    expect(Activity::query()->where('description', 'Crew timesheet preparation applied to timesheets')->exists())->toBeTrue();
});

test('non approved statuses cannot be applied', function (CrewTimesheetPreparationStatus $status) {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantApplyPermissions($fixtures['user'], $fixtures['company']);
    ['preparation' => $preparation] = prepareApprovedTimeline($fixtures);
    $preparation->update(['status' => $status]);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.apply', [$fixtures['period'], $preparation]))
        ->assertSessionHasErrors('preparation');
})->with([
    CrewTimesheetPreparationStatus::Draft,
    CrewTimesheetPreparationStatus::Submitted,
    CrewTimesheetPreparationStatus::Returned,
    CrewTimesheetPreparationStatus::Superseded,
]);

test('stale approved preparation cannot be applied', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantApplyPermissions($fixtures['user'], $fixtures['company']);
    ['preparation' => $preparation, 'approver' => $approver] = prepareApprovedTimeline($fixtures);

    $fixtures['assignment']->phases()
        ->where('phase_code', CrewPhaseCode::OnVessel)
        ->firstOrFail()
        ->update(['actual_end_at' => '2026-07-10 18:00:00']);

    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.apply', [$fixtures['period'], $preparation]))
        ->assertSessionHasErrors('preparation');

    expect($preparation->fresh()->status)->toBe(CrewTimesheetPreparationStatus::Approved)
        ->and($preparation->fresh()->source_hash)->toBe($preparation->source_hash);
});

test('non draft payroll period cannot be applied', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantApplyPermissions($fixtures['user'], $fixtures['company']);
    ['preparation' => $preparation, 'approver' => $approver] = prepareApprovedTimeline($fixtures);
    $fixtures['period']->update(['status' => PayrollPeriodStatus::Processing]);

    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.apply', [$fixtures['period'], $preparation]))
        ->assertSessionHasErrors('payroll_period_id');
});

test('blocking warnings prevent application while informational do not', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantApplyPermissions($fixtures['user'], $fixtures['company']);
    ['preparation' => $preparation, 'approver' => $approver] = prepareApprovedTimeline($fixtures);

    CrewTimesheetPreparationLine::factory()->forPreparation($preparation)->create([
        'employee_id' => $fixtures['employee']->id,
        'crew_assignment_id' => $fixtures['assignment']->id,
        'pay_category' => CrewTimesheetPayCategory::Excluded,
        'days' => 0,
        'warning_code' => CrewTimelineWarningCode::OverlappingPhases->value,
        'from_date' => '2026-07-10',
        'to_date' => '2026-07-10',
    ]);

    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.apply', [$fixtures['period'], $preparation]))
        ->assertSessionHasErrors('preparation');

    $preparation->lines()
        ->where('warning_code', CrewTimelineWarningCode::OverlappingPhases->value)
        ->delete();

    CrewTimesheetPreparationLine::factory()->forPreparation($preparation)->create([
        'employee_id' => $fixtures['employee']->id,
        'crew_assignment_id' => $fixtures['assignment']->id,
        'pay_category' => CrewTimesheetPayCategory::Excluded,
        'days' => 0,
        'warning_code' => CrewTimelineWarningCode::TravelInExcluded->value,
        'from_date' => '2026-07-01',
        'to_date' => '2026-07-01',
    ]);

    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.apply', [$fixtures['period'], $preparation]))
        ->assertRedirect();

    expect($preparation->fresh()->status)->toBe(CrewTimesheetPreparationStatus::Applied);
});

test('zero day and excluded lines do not contribute to timesheet totals', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantApplyPermissions($fixtures['user'], $fixtures['company']);
    ['preparation' => $preparation, 'approver' => $approver] = prepareApprovedTimeline($fixtures);

    $beforeSignOn = (float) $preparation->lines()
        ->where('pay_category', CrewTimesheetPayCategory::SignOnStandby)
        ->where('days', '>', 0)
        ->sum('days');

    CrewTimesheetPreparationLine::factory()->forPreparation($preparation)->create([
        'employee_id' => $fixtures['employee']->id,
        'crew_assignment_id' => $fixtures['assignment']->id,
        'pay_category' => CrewTimesheetPayCategory::SignOnStandby,
        'days' => 0,
        'from_date' => '2026-07-19',
        'to_date' => '2026-07-19',
        'warning_code' => CrewTimelineWarningCode::TimelineGap->value,
    ]);

    CrewTimesheetPreparationLine::factory()->forPreparation($preparation)->create([
        'employee_id' => $fixtures['employee']->id,
        'crew_assignment_id' => $fixtures['assignment']->id,
        'pay_category' => CrewTimesheetPayCategory::Excluded,
        'days' => 5,
        'from_date' => '2026-07-20',
        'to_date' => '2026-07-24',
    ]);

    app(ApplyCrewTimesheetPreparation::class)->handle(
        $fixtures['period'],
        $preparation,
        $approver,
        (int) $fixtures['company']->id,
    );

    $timesheet = CrewTimesheet::query()
        ->where('employee_id', $fixtures['employee']->id)
        ->where('period_id', $fixtures['period']->id)
        ->firstOrFail();

    expect((float) $timesheet->sign_on_standby_days)->toBe($beforeSignOn);
});

test('applied preparation populates only split operational fields', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantApplyPermissions($fixtures['user'], $fixtures['company']);
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', '2026-07-05 18:00:00');

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );
    $approver = User::factory()->create();
    grantApplyPermissions($approver, $fixtures['company']);
    $preparation->update([
        'status' => CrewTimesheetPreparationStatus::Approved,
        'submitted_by' => $fixtures['user']->id,
        'submitted_at' => now(),
        'approved_by' => $approver->id,
        'approved_at' => now(),
    ]);

    app(ApplyCrewTimesheetPreparation::class)->handle(
        $fixtures['period'],
        $preparation,
        $approver,
        (int) $fixtures['company']->id,
    );

    $timesheet = CrewTimesheet::query()
        ->where('employee_id', $fixtures['employee']->id)
        ->firstOrFail();

    expect($timesheet->sign_on_standby_from?->toDateString())->toBe('2026-07-01')
        ->and($timesheet->sign_on_standby_to?->toDateString())->toBe('2026-07-05')
        ->and((float) $timesheet->sign_on_standby_days)->toBeGreaterThan(0)
        ->and((float) $timesheet->sign_off_standby_days)->toBe(0.0);
});

test('manual update cannot overwrite applied operational fields but can update financial fields', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantApplyPermissions($fixtures['user'], $fixtures['company']);
    ['preparation' => $preparation, 'approver' => $approver] = prepareApprovedTimeline($fixtures);

    app(ApplyCrewTimesheetPreparation::class)->handle(
        $fixtures['period'],
        $preparation,
        $approver,
        (int) $fixtures['company']->id,
    );

    $timesheet = CrewTimesheet::query()
        ->where('employee_id', $fixtures['employee']->id)
        ->firstOrFail();

    $lockedOnsite = (float) $timesheet->onsite_days;
    $lockedSource = $timesheet->source;

    expect(fn () => app(UpsertCrewTimesheet::class)->handle($fixtures['period'], $fixtures['employee'], [
        'sign_on_standby_from' => '2026-07-01',
        'sign_on_standby_to' => '2026-07-02',
        'sign_on_standby_days' => 99,
        'onsite_from' => '2026-07-01',
        'onsite_to' => '2026-07-02',
        'onsite_days' => 99,
        'overtime_hours' => 8,
    ]))->toThrow(ValidationException::class);

    app(UpsertCrewTimesheet::class)->handle($fixtures['period'], $fixtures['employee'], [
        'overtime_hours' => 8,
        'additional_amount' => 10,
        'deduction_amount' => 5,
        'remarks' => 'Financial update',
    ]);

    $timesheet->refresh();

    expect((float) $timesheet->onsite_days)->toBe($lockedOnsite)
        ->and($timesheet->source)->toBe($lockedSource)
        ->and((float) $timesheet->overtime_hours)->toBe(8.0)
        ->and((float) $timesheet->additional_amount)->toBe(10.0)
        ->and((float) $timesheet->deduction_amount)->toBe(5.0)
        ->and($timesheet->remarks)->toBe('Financial update');
});

test('changing the applicable contract salary structure after approval makes the preparation stale', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantApplyPermissions($fixtures['user'], $fixtures['company']);
    ['preparation' => $preparation, 'approver' => $approver] = prepareApprovedTimeline($fixtures);

    EmployeeContract::query()
        ->where('employee_id', $fixtures['employee']->id)
        ->update(['salary_structure' => ContractSalaryStructure::Monthly]);

    expect(fn () => app(ApplyCrewTimesheetPreparation::class)->handle(
        $fixtures['period'],
        $preparation,
        $approver,
        (int) $fixtures['company']->id,
    ))->toThrow(ValidationException::class);

    expect(CrewTimesheet::query()->where('employee_id', $fixtures['employee']->id)->count())->toBe(0)
        ->and($preparation->fresh()->status)->toBe(CrewTimesheetPreparationStatus::Approved);
});

test('repeated apply is idempotent', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantApplyPermissions($fixtures['user'], $fixtures['company']);
    ['preparation' => $preparation, 'approver' => $approver] = prepareApprovedTimeline($fixtures);

    app(ApplyCrewTimesheetPreparation::class)->handle(
        $fixtures['period'],
        $preparation,
        $approver,
        (int) $fixtures['company']->id,
    );

    $result = app(ApplyCrewTimesheetPreparation::class)->handle(
        $fixtures['period'],
        $preparation->fresh(),
        $approver,
        (int) $fixtures['company']->id,
    );

    expect($result->idempotent)->toBeTrue()
        ->and(CrewTimesheet::query()->where('period_id', $fixtures['period']->id)->count())->toBe(1);
});

test('another applied preparation blocks application', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantApplyPermissions($fixtures['user'], $fixtures['company']);
    ['preparation' => $preparation, 'approver' => $approver] = prepareApprovedTimeline($fixtures);

    CrewTimesheetPreparation::factory()
        ->forPeriod($fixtures['period'])
        ->version(99)
        ->create([
            'status' => CrewTimesheetPreparationStatus::Applied,
            'applied_by' => $approver->id,
            'applied_at' => now(),
            'source_hash' => 'other',
        ]);

    expect(fn () => app(ApplyCrewTimesheetPreparation::class)->handle(
        $fixtures['period'],
        $preparation,
        $approver,
        (int) $fixtures['company']->id,
    ))->toThrow(ValidationException::class);
});

test('new preparation cannot be created after applied version exists', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantApplyPermissions($fixtures['user'], $fixtures['company']);
    ['preparation' => $preparation, 'approver' => $approver] = prepareApprovedTimeline($fixtures);

    app(ApplyCrewTimesheetPreparation::class)->handle(
        $fixtures['period'],
        $preparation,
        $approver,
        (int) $fixtures['company']->id,
    );

    expect(fn () => app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    ))->toThrow(ValidationException::class);
});

test('apply permission denial and cross company returns 404', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    ['preparation' => $preparation] = prepareApprovedTimeline($fixtures);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.apply', [$fixtures['period'], $preparation]))
        ->assertForbidden();

    grantApplyPermissions($fixtures['user'], $fixtures['company']);
    $other = makeDailyCrewTimelineFixtures();
    grantApplyPermissions($fixtures['user'], $other['company']);

    $this->actingAs($fixtures['user'])
        ->withSession(['current_company_id' => $other['company']->id])
        ->post(route('payroll.crew-timeline.apply', [$other['period'], $preparation]))
        ->assertNotFound();
});

test('preparation belonging to another payroll period returns 404 on apply', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantApplyPermissions($fixtures['user'], $fixtures['company']);
    ['preparation' => $preparation, 'approver' => $approver] = prepareApprovedTimeline($fixtures);

    $otherPeriod = PayrollPeriod::factory()->for($fixtures['company'])->create([
        'status' => PayrollPeriodStatus::Draft,
        'payroll_category' => $fixtures['period']->payroll_category,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
        'payment_date' => '2026-08-31',
    ]);

    $this->actingAs($approver)
        ->withSession(['current_company_id' => $fixtures['company']->id])
        ->post(route('payroll.crew-timeline.apply', [$otherPeriod, $preparation]))
        ->assertNotFound();
});

test('transaction rollback leaves preparation approved when application fails', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    grantApplyPermissions($fixtures['user'], $fixtures['company']);
    ['preparation' => $preparation, 'approver' => $approver] = prepareApprovedTimeline($fixtures);

    try {
        DB::transaction(function () use ($fixtures, $preparation, $approver): void {
            app(ApplyCrewTimesheetPreparation::class)->handle(
                $fixtures['period'],
                $preparation,
                $approver,
                (int) $fixtures['company']->id,
            );

            throw new RuntimeException('force rollback');
        });
    } catch (RuntimeException) {
    }

    expect($preparation->fresh()->status)->toBe(CrewTimesheetPreparationStatus::Approved)
        ->and(CrewTimesheet::query()->where('period_id', $fixtures['period']->id)->count())->toBe(0);
});
