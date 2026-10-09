<?php

use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewTimesheetPayCategory;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\Company;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetSegment;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\PayrollWorkAllocation;
use App\Models\User;
use App\Support\Payroll\Actions\GenerateCrewPayroll;
use App\Support\Payroll\BuildCrewPayrollGenerationPreview;
use App\Support\Payroll\CrewTimeline\PopulateCrewTimesheetsFromAssignments;
use App\Support\Payroll\SyncCrewTimesheetParentFromSegments;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/**
 * @return array{user: User, company: Company, period: PayrollPeriod, employee: Employee}
 */
function makeOctoberCrewPayrollAckFixtures(): array
{
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-09 12:00:00', 'Asia/Dubai'));

    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $company->update(['timezone' => 'Asia/Dubai']);

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'status' => PayrollPeriodStatus::Draft,
        'payroll_category' => PayrollCategory::Crew,
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-30',
        'payment_date' => '2026-10-30',
    ]);

    $employee = createCrewEmployeeWithContract($company, 'ACK-FUT-1', 100, 50, 25);

    grantCompanyPermissions($user, $company, [
        'payroll.periods.update',
        'payroll.periods.view',
        'payroll.crew_timesheets.prepare',
        'payroll.crew_timesheets.view',
    ]);

    return compact('user', 'company', 'period', 'employee');
}

function seedFutureOnsiteSegment(
    PayrollPeriod $period,
    int $companyId,
    int $employeeId,
    string $from,
    string $to,
    CrewTimesheetSource $source = CrewTimesheetSource::Manual,
): CrewTimesheet {
    $fromDate = CarbonImmutable::parse($from);
    $toDate = CarbonImmutable::parse($to);
    $days = (int) $fromDate->diffInDays($toDate) + 1;

    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $companyId,
        'employee_id' => $employeeId,
        'period_id' => $period->id,
        'source' => $source,
        'onsite_from' => $from,
        'onsite_to' => $to,
        'onsite_days' => $days,
    ]);

    CrewTimesheetSegment::factory()->create([
        'company_id' => $companyId,
        'crew_timesheet_id' => $timesheet->id,
        'sequence' => 1,
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'from_date' => $from,
        'to_date' => $to,
        'days' => $days,
        'source' => $source,
    ]);

    app(SyncCrewTimesheetParentFromSegments::class)->handle($timesheet->fresh());

    return $timesheet->fresh(['segments']);
}

test('populate from crew assignments copies future actual days without acknowledgment', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['company']->update(['timezone' => 'Asia/Dubai']);
    $fixtures['period']->update([
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-30',
        'payment_date' => '2026-10-30',
    ]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-09 12:00:00', 'Asia/Dubai'));

    addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::OnVessel,
        1,
        '2026-10-15 08:00:00',
        '2026-10-20 18:00:00',
        CrewPhaseStatus::Completed,
    );

    $result = app(PopulateCrewTimesheetsFromAssignments::class)->handle(
        $fixtures['period'],
        $fixtures['user'],
        (int) $fixtures['company']->id,
    );

    $timesheet = CrewTimesheet::query()
        ->where('period_id', $fixtures['period']->id)
        ->where('employee_id', $fixtures['employee']->id)
        ->firstOrFail();

    expect($result['applied_employee_count'])->toBe(1)
        ->and((float) $timesheet->onsite_days)->toBe(6.0)
        ->and($timesheet->onsite_from?->toDateString())->toBe('2026-10-15')
        ->and($timesheet->onsite_to?->toDateString())->toBe('2026-10-20');
});

test('generation preview reports future payable days for ready crew employees', function () {
    ['user' => $user, 'company' => $company, 'period' => $period, 'employee' => $employee] = makeOctoberCrewPayrollAckFixtures();

    seedFutureOnsiteSegment($period, (int) $company->id, (int) $employee->id, '2026-10-15', '2026-10-20');

    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle(
        $period,
        (int) $company->id,
        [],
        $user,
    );

    expect($preview->readyCount)->toBe(1)
        ->and($preview->requiresFutureDaysAcknowledgment)->toBeTrue()
        ->and($preview->futurePayableDaysCount)->toBe(6)
        ->and($preview->futurePayableEmployeeCount)->toBe(1)
        ->and($preview->futurePayableFrom)->toBe('2026-10-15')
        ->and($preview->futurePayableTo)->toBe('2026-10-20')
        ->and($preview->toPublicArray()['requires_future_days_acknowledgment'])->toBeTrue();
});

test('generate payroll without acknowledgment is rejected when future payable days exist', function () {
    ['user' => $user, 'company' => $company, 'period' => $period, 'employee' => $employee] = makeOctoberCrewPayrollAckFixtures();

    seedFutureOnsiteSegment($period, (int) $company->id, (int) $employee->id, '2026-10-15', '2026-10-20');

    expect(fn () => app(GenerateCrewPayroll::class)->handle($period, [], $user, false))
        ->toThrow(ValidationException::class);

    try {
        app(GenerateCrewPayroll::class)->handle($period, [], $user, false);
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('acknowledge_future_payable_days');
    }

    expect(PayrollRecord::query()->where('period_id', $period->id)->count())->toBe(0);
});

test('generate payroll with acknowledgment succeeds when future payable days exist', function () {
    ['user' => $user, 'company' => $company, 'period' => $period, 'employee' => $employee] = makeOctoberCrewPayrollAckFixtures();

    seedFutureOnsiteSegment($period, (int) $company->id, (int) $employee->id, '2026-10-15', '2026-10-20');

    $result = app(GenerateCrewPayroll::class)->handle($period, [], $user, true);

    expect($result->generatedCount)->toBe(1)
        ->and(PayrollRecord::query()->where('period_id', $period->id)->where('employee_id', $employee->id)->exists())->toBeTrue();

    $activity = Activity::query()
        ->where('subject_type', PayrollPeriod::class)
        ->where('subject_id', $period->id)
        ->where('description', 'Crew payroll generated')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties->get('future_payable_days_acknowledged'))->toBeTrue()
        ->and($activity->properties->get('future_payable_days_count'))->toBe(6)
        ->and($activity->properties->get('acknowledged_by'))->toBe($user->id)
        ->and($activity->causer_id)->toBe($user->id);
});

test('past-only payroll generation needs no acknowledgment', function () {
    ['user' => $user, 'company' => $company, 'period' => $period, 'employee' => $employee] = makeOctoberCrewPayrollAckFixtures();

    seedFutureOnsiteSegment($period, (int) $company->id, (int) $employee->id, '2026-10-01', '2026-10-08');

    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle($period, (int) $company->id, [], $user);

    expect($preview->requiresFutureDaysAcknowledgment)->toBeFalse()
        ->and($preview->futurePayableDaysCount)->toBe(0);

    $result = app(GenerateCrewPayroll::class)->handle($period, [], $user, false);

    expect($result->generatedCount)->toBe(1);
});

test('excluded employees do not trigger future payable acknowledgment', function () {
    ['user' => $user, 'company' => $company, 'period' => $period, 'employee' => $futureEmployee] = makeOctoberCrewPayrollAckFixtures();
    $pastEmployee = createCrewEmployeeWithContract($company, 'ACK-PAST-1', 100, 50, 25);

    seedFutureOnsiteSegment($period, (int) $company->id, (int) $futureEmployee->id, '2026-10-15', '2026-10-20');
    seedFutureOnsiteSegment($period, (int) $company->id, (int) $pastEmployee->id, '2026-10-01', '2026-10-05');

    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle(
        $period,
        (int) $company->id,
        [(int) $futureEmployee->id],
        $user,
    );

    expect($preview->readyCount)->toBe(1)
        ->and($preview->readyEmployeeIds)->toContain((int) $pastEmployee->id)
        ->and($preview->requiresFutureDaysAcknowledgment)->toBeFalse()
        ->and($preview->futurePayableDaysCount)->toBe(0);

    $result = app(GenerateCrewPayroll::class)->handle(
        $period,
        [(int) $futureEmployee->id],
        $user,
        false,
    );

    expect($result->generatedCount)->toBe(1)
        ->and(PayrollRecord::query()->where('employee_id', $pastEmployee->id)->exists())->toBeTrue()
        ->and(PayrollRecord::query()->where('employee_id', $futureEmployee->id)->exists())->toBeFalse();
});

test('excluded p6 and nonpayable days do not trigger acknowledgment', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    $fixtures['company']->update(['timezone' => 'Asia/Dubai']);
    $fixtures['period']->update([
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-30',
        'payment_date' => '2026-10-30',
    ]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-09 12:00:00', 'Asia/Dubai'));

    addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::OnVessel,
        1,
        '2026-10-01 08:00:00',
        '2026-10-05 10:00:00',
        CrewPhaseStatus::Completed,
    );
    addTimelinePhase(
        $fixtures['assignment'],
        CrewPhaseCode::HomeRedeploy,
        2,
        '2026-10-05 10:00:00',
        null,
        CrewPhaseStatus::Active,
    );

    app(PopulateCrewTimesheetsFromAssignments::class)->handle(
        $fixtures['period'],
        $fixtures['user'],
        (int) $fixtures['company']->id,
    );

    grantCompanyPermissions($fixtures['user'], $fixtures['company'], ['payroll.periods.update']);

    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle(
        $fixtures['period']->fresh(),
        (int) $fixtures['company']->id,
        [],
        $fixtures['user'],
    );

    expect($preview->readyCount)->toBe(1)
        ->and($preview->requiresFutureDaysAcknowledgment)->toBeFalse()
        ->and($preview->futurePayableDaysCount)->toBe(0);

    $result = app(GenerateCrewPayroll::class)->handle(
        $fixtures['period']->fresh(),
        [],
        $fixtures['user'],
        false,
    );

    expect($result->generatedCount)->toBe(1);
});

test('manual and import timesheets follow the same future payable acknowledgment rules', function () {
    ['user' => $user, 'company' => $company, 'period' => $period] = makeOctoberCrewPayrollAckFixtures();
    $manual = createCrewEmployeeWithContract($company, 'ACK-MAN-1', 100, 50, 25);
    $import = createCrewEmployeeWithContract($company, 'ACK-IMP-1', 100, 50, 25);

    seedFutureOnsiteSegment(
        $period,
        (int) $company->id,
        (int) $manual->id,
        '2026-10-12',
        '2026-10-14',
        CrewTimesheetSource::Manual,
    );
    seedFutureOnsiteSegment(
        $period,
        (int) $company->id,
        (int) $import->id,
        '2026-10-16',
        '2026-10-18',
        CrewTimesheetSource::Import,
    );

    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle($period, (int) $company->id, [], $user);

    expect($preview->futurePayableEmployeeCount)->toBe(2)
        ->and($preview->futurePayableDaysCount)->toBe(6)
        ->and($preview->requiresFutureDaysAcknowledgment)->toBeTrue();

    expect(fn () => app(GenerateCrewPayroll::class)->handle($period, [], $user, false))
        ->toThrow(ValidationException::class);

    $result = app(GenerateCrewPayroll::class)->handle($period, [], $user, true);

    expect($result->generatedCount)->toBe(2);
});

test('direct http generate request cannot bypass future payable acknowledgment', function () {
    ['user' => $user, 'company' => $company, 'period' => $period, 'employee' => $employee] = makeOctoberCrewPayrollAckFixtures();

    seedFutureOnsiteSegment($period, (int) $company->id, (int) $employee->id, '2026-10-15', '2026-10-20');

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period), [
            'excluded_employee_ids' => [],
        ])
        ->assertSessionHasErrors('acknowledge_future_payable_days');

    expect(PayrollRecord::query()->where('period_id', $period->id)->count())->toBe(0);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period), [
            'excluded_employee_ids' => [],
            'acknowledge_future_payable_days' => true,
        ])
        ->assertRedirect(route('payroll.show', $period))
        ->assertSessionHas('success');

    expect(PayrollRecord::query()->where('period_id', $period->id)->count())->toBe(1);
});

test('cross-company generate remains blocked and restricted visibility cannot generate for hidden employees', function () {
    ['user' => $user, 'company' => $company, 'period' => $period, 'employee' => $employee] = makeOctoberCrewPayrollAckFixtures();
    seedFutureOnsiteSegment($period, (int) $company->id, (int) $employee->id, '2026-10-15', '2026-10-20');

    $other = makePayrollFixtures();
    $otherPeriod = PayrollPeriod::factory()->for($other['company'])->hybridTimesheets()->create([
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-30',
        'payment_date' => '2026-10-30',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $otherPeriod), [
            'acknowledge_future_payable_days' => true,
        ])
        ->assertNotFound();

    $restricted = User::factory()->create();
    grantCompanyPermissions($restricted, $company, [
        'payroll.periods.update',
        'payroll.periods.view',
    ], 'restricted-payroll-role');
    restrictTestRoleEmployeeVisibility(
        $restricted,
        $company,
        [],
        'restricted-payroll-role',
    );

    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle(
        $period,
        (int) $company->id,
        [],
        $restricted,
    );

    expect($preview->readyCount)->toBe(0)
        ->and($preview->requiresFutureDaysAcknowledgment)->toBeFalse();
});

test('changing timesheet data after preview still requires acknowledgment based on locked generation data', function () {
    ['user' => $user, 'company' => $company, 'period' => $period, 'employee' => $employee] = makeOctoberCrewPayrollAckFixtures();

    $timesheet = seedFutureOnsiteSegment(
        $period,
        (int) $company->id,
        (int) $employee->id,
        '2026-10-01',
        '2026-10-05',
    );

    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle($period, (int) $company->id, [], $user);
    expect($preview->requiresFutureDaysAcknowledgment)->toBeFalse();

    $timesheet->segments()->firstOrFail()->update([
        'from_date' => '2026-10-15',
        'to_date' => '2026-10-20',
        'days' => 6,
    ]);
    app(SyncCrewTimesheetParentFromSegments::class)->handle($timesheet->fresh());

    expect(fn () => app(GenerateCrewPayroll::class)->handle($period->fresh(), [], $user, false))
        ->toThrow(ValidationException::class);

    $result = app(GenerateCrewPayroll::class)->handle($period->fresh(), [], $user, true);
    expect($result->generatedCount)->toBe(1);
});

test('office payroll generation is unchanged and ignores future payable acknowledgment', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-09 12:00:00', 'Asia/Dubai'));

    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'status' => PayrollPeriodStatus::Draft,
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-30',
        'payment_date' => '2026-10-30',
    ]);
    createOfficeEmployeeWithContract($company, 'ACK-OFF-1', 5000, 1000, 500, 200);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period), [
            'excluded_employee_ids' => [],
        ])
        ->assertRedirect(route('payroll.show', $period))
        ->assertSessionHas('success');

    expect(PayrollRecord::query()->where('period_id', $period->id)->count())->toBe(1)
        ->and(
            Activity::query()
                ->where('subject_type', PayrollPeriod::class)
                ->where('subject_id', $period->id)
                ->where('description', 'Crew payroll generated')
                ->exists()
        )->toBeFalse();
});

test('http generation preview endpoint exposes future payable acknowledgment fields without employee id arrays', function () {
    ['user' => $user, 'company' => $company, 'period' => $period, 'employee' => $employee] = makeOctoberCrewPayrollAckFixtures();

    seedFutureOnsiteSegment($period, (int) $company->id, (int) $employee->id, '2026-10-15', '2026-10-20');

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->postJson(route('payroll.generation-preview', $period), [
            'excluded_employee_ids' => [],
        ])
        ->assertOk()
        ->assertJsonPath('requires_future_days_acknowledgment', true)
        ->assertJsonPath('future_payable_days_count', 6)
        ->assertJsonPath('future_payable_employee_count', 1)
        ->assertJsonPath('future_payable_from', '2026-10-15')
        ->assertJsonPath('future_payable_to', '2026-10-20');

    expect($response->json())->not->toHaveKey('ready_employee_ids');
});

function seedLegacyFlatOnsiteTimesheet(
    PayrollPeriod $period,
    int $companyId,
    int $employeeId,
    string $from,
    string $to,
    ?float $days = null,
    CrewTimesheetSource $source = CrewTimesheetSource::Manual,
): CrewTimesheet {
    $fromDate = CarbonImmutable::parse($from);
    $toDate = CarbonImmutable::parse($to);
    $days ??= (float) ($fromDate->diffInDays($toDate) + 1);

    return CrewTimesheet::factory()->create([
        'company_id' => $companyId,
        'employee_id' => $employeeId,
        'period_id' => $period->id,
        'source' => $source,
        'onsite_from' => $from,
        'onsite_to' => $to,
        'onsite_days' => $days,
        'sign_on_standby_from' => null,
        'sign_on_standby_to' => null,
        'sign_on_standby_days' => 0,
        'sign_off_standby_from' => null,
        'sign_off_standby_to' => null,
        'sign_off_standby_days' => 0,
    ]);
}

test('legacy flat-field timesheet with future onsite dates triggers acknowledgment', function () {
    ['user' => $user, 'company' => $company, 'period' => $period, 'employee' => $employee] = makeOctoberCrewPayrollAckFixtures();

    $timesheet = seedLegacyFlatOnsiteTimesheet($period, (int) $company->id, (int) $employee->id, '2026-10-15', '2026-10-20');

    expect($timesheet->segments()->count())->toBe(0);

    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle($period, (int) $company->id, [], $user);

    expect($preview->readyCount)->toBe(1)
        ->and($preview->requiresFutureDaysAcknowledgment)->toBeTrue()
        ->and($preview->futurePayableDaysCount)->toBe(6)
        ->and($preview->futurePayableEmployeeCount)->toBe(1)
        ->and($preview->futurePayableFrom)->toBe('2026-10-15')
        ->and($preview->futurePayableTo)->toBe('2026-10-20');
});

test('legacy flat-field generate without acknowledgment fails and with acknowledgment succeeds', function () {
    ['user' => $user, 'company' => $company, 'period' => $period, 'employee' => $employee] = makeOctoberCrewPayrollAckFixtures();

    seedLegacyFlatOnsiteTimesheet($period, (int) $company->id, (int) $employee->id, '2026-10-15', '2026-10-20');

    expect(fn () => app(GenerateCrewPayroll::class)->handle($period, [], $user, false))
        ->toThrow(ValidationException::class);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period), [
            'excluded_employee_ids' => [],
        ])
        ->assertSessionHasErrors('acknowledge_future_payable_days');

    expect(PayrollRecord::query()->where('period_id', $period->id)->count())->toBe(0);

    $result = app(GenerateCrewPayroll::class)->handle($period, [], $user, true);

    expect($result->generatedCount)->toBe(1)
        ->and(PayrollRecord::query()->where('period_id', $period->id)->where('employee_id', $employee->id)->exists())->toBeTrue();
});

test('direct http generate cannot bypass legacy flat-field future payable acknowledgment', function () {
    ['user' => $user, 'company' => $company, 'period' => $period, 'employee' => $employee] = makeOctoberCrewPayrollAckFixtures();

    seedLegacyFlatOnsiteTimesheet($period, (int) $company->id, (int) $employee->id, '2026-10-15', '2026-10-20');

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period), [
            'excluded_employee_ids' => [],
            'acknowledge_future_payable_days' => true,
        ])
        ->assertRedirect(route('payroll.show', $period))
        ->assertSessionHas('success');

    expect(PayrollRecord::query()->where('period_id', $period->id)->where('employee_id', $employee->id)->exists())->toBeTrue();
});

test('past-only legacy flat-field timesheets do not require acknowledgment', function () {
    ['user' => $user, 'company' => $company, 'period' => $period, 'employee' => $employee] = makeOctoberCrewPayrollAckFixtures();

    seedLegacyFlatOnsiteTimesheet($period, (int) $company->id, (int) $employee->id, '2026-10-01', '2026-10-08');

    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle($period, (int) $company->id, [], $user);

    expect($preview->requiresFutureDaysAcknowledgment)->toBeFalse()
        ->and($preview->futurePayableDaysCount)->toBe(0);

    $result = app(GenerateCrewPayroll::class)->handle($period, [], $user, false);

    expect($result->generatedCount)->toBe(1);
});

test('incomplete legacy flat-field date pairs do not create false future payable days', function () {
    ['user' => $user, 'company' => $company, 'period' => $period, 'employee' => $employee] = makeOctoberCrewPayrollAckFixtures();

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
        'onsite_from' => '2026-10-15',
        'onsite_to' => null,
        'onsite_days' => 6,
        'sign_on_standby_from' => null,
        'sign_on_standby_to' => '2026-10-20',
        'sign_on_standby_days' => 3,
        'sign_off_standby_from' => null,
        'sign_off_standby_to' => null,
        'sign_off_standby_days' => 0,
    ]);

    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle($period, (int) $company->id, [], $user);

    expect($preview->readyCount)->toBe(1)
        ->and($preview->requiresFutureDaysAcknowledgment)->toBeFalse()
        ->and($preview->futurePayableDaysCount)->toBe(0);

    $result = app(GenerateCrewPayroll::class)->handle($period, [], $user, false);

    expect($result->generatedCount)->toBe(1);
});

test('legacy flat-field multiple future categories count distinct employee dates once', function () {
    ['user' => $user, 'company' => $company, 'period' => $period, 'employee' => $employee] = makeOctoberCrewPayrollAckFixtures();

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
        'sign_on_standby_from' => '2026-10-11',
        'sign_on_standby_to' => '2026-10-14',
        'sign_on_standby_days' => 4,
        'onsite_from' => '2026-10-15',
        'onsite_to' => '2026-10-17',
        'onsite_days' => 3,
        'sign_off_standby_from' => '2026-10-18',
        'sign_off_standby_to' => '2026-10-19',
        'sign_off_standby_days' => 2,
    ]);

    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle($period, (int) $company->id, [], $user);

    expect($preview->requiresFutureDaysAcknowledgment)->toBeTrue()
        ->and($preview->futurePayableEmployeeCount)->toBe(1)
        ->and($preview->futurePayableDaysCount)->toBe(9)
        ->and($preview->futurePayableFrom)->toBe('2026-10-11')
        ->and($preview->futurePayableTo)->toBe('2026-10-19');
});

test('excluded legacy flat-field employees do not trigger acknowledgment', function () {
    ['user' => $user, 'company' => $company, 'period' => $period, 'employee' => $futureEmployee] = makeOctoberCrewPayrollAckFixtures();
    $pastEmployee = createCrewEmployeeWithContract($company, 'ACK-LEG-PAST', 100, 50, 25);

    seedLegacyFlatOnsiteTimesheet($period, (int) $company->id, (int) $futureEmployee->id, '2026-10-15', '2026-10-20');
    seedLegacyFlatOnsiteTimesheet($period, (int) $company->id, (int) $pastEmployee->id, '2026-10-01', '2026-10-05');

    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle(
        $period,
        (int) $company->id,
        [(int) $futureEmployee->id],
        $user,
    );

    expect($preview->readyCount)->toBe(1)
        ->and($preview->requiresFutureDaysAcknowledgment)->toBeFalse()
        ->and($preview->futurePayableDaysCount)->toBe(0);

    $result = app(GenerateCrewPayroll::class)->handle(
        $period,
        [(int) $futureEmployee->id],
        $user,
        false,
    );

    expect($result->generatedCount)->toBe(1)
        ->and(PayrollRecord::query()->where('employee_id', $pastEmployee->id)->exists())->toBeTrue()
        ->and(PayrollRecord::query()->where('employee_id', $futureEmployee->id)->exists())->toBeFalse();
});

test('legacy flat-field cross-company generate remains blocked', function () {
    ['user' => $user, 'company' => $company, 'period' => $period, 'employee' => $employee] = makeOctoberCrewPayrollAckFixtures();
    seedLegacyFlatOnsiteTimesheet($period, (int) $company->id, (int) $employee->id, '2026-10-15', '2026-10-20');

    $other = makePayrollFixtures();
    $otherPeriod = PayrollPeriod::factory()->for($other['company'])->hybridTimesheets()->create([
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-30',
        'payment_date' => '2026-10-30',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $otherPeriod), [
            'acknowledge_future_payable_days' => true,
        ])
        ->assertNotFound();
});

test('legacy flat movement entirely after payroll period blocks preview and generate even with acknowledgment', function () {
    ['user' => $user, 'company' => $company, 'period' => $period, 'employee' => $employee] = makeOctoberCrewPayrollAckFixtures();

    seedLegacyFlatOnsiteTimesheet(
        $period,
        (int) $company->id,
        (int) $employee->id,
        '2026-11-01',
        '2026-11-06',
        source: CrewTimesheetSource::CrewOperations,
    );

    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle($period, (int) $company->id, [], $user);

    expect($preview->canGenerate)->toBeFalse()
        ->and($preview->readyCount)->toBe(0)
        ->and($preview->blockingCount)->toBe(1)
        ->and($preview->blockingIssues[0]['code'])->toBe('legacy_movement_outside_payroll_period')
        ->and($preview->blockingIssues[0]['message'])->toContain("{$employee->name}'s Onsite dates (01 Nov – 06 Nov 2026)")
        ->and($preview->blockingIssues[0]['message'])->toContain('01 Oct – 30 Oct 2026')
        ->and($preview->requiresFutureDaysAcknowledgment)->toBeFalse();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->postJson(route('payroll.generation-preview', $period), [
            'excluded_employee_ids' => [],
        ])
        ->assertOk()
        ->assertJsonPath('can_generate', false)
        ->assertJsonPath('blocking_count', 1)
        ->assertJsonPath('blocking_issues.0.code', 'legacy_movement_outside_payroll_period');

    expect(fn () => app(GenerateCrewPayroll::class)->handle($period, [], $user, true))
        ->toThrow(ValidationException::class);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period), [
            'excluded_employee_ids' => [],
            'acknowledge_future_payable_days' => true,
        ])
        ->assertSessionHasErrors('period_id');

    expect(PayrollRecord::query()->where('period_id', $period->id)->count())->toBe(0)
        ->and(PayrollWorkAllocation::query()->where('payroll_period_id', $period->id)->count())->toBe(0);
});

test('legacy flat movement starting before payroll period remains permitted when period end is respected', function () {
    ['user' => $user, 'company' => $company, 'period' => $period, 'employee' => $employee] = makeOctoberCrewPayrollAckFixtures();

    seedLegacyFlatOnsiteTimesheet($period, (int) $company->id, (int) $employee->id, '2026-09-25', '2026-10-05');

    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle($period, (int) $company->id, [], $user);

    expect($preview->canGenerate)->toBeTrue()
        ->and($preview->readyCount)->toBe(1)
        ->and($preview->blockingCount)->toBe(0)
        ->and($preview->requiresFutureDaysAcknowledgment)->toBeFalse();

    $result = app(GenerateCrewPayroll::class)->handle($period, [], $user, false);

    expect($result->generatedCount)->toBe(1)
        ->and(PayrollRecord::query()->where('period_id', $period->id)->where('employee_id', $employee->id)->exists())->toBeTrue();
});

test('legacy flat movement partially extending past payroll period end is blocked', function () {
    ['user' => $user, 'company' => $company, 'period' => $period, 'employee' => $employee] = makeOctoberCrewPayrollAckFixtures();

    seedLegacyFlatOnsiteTimesheet($period, (int) $company->id, (int) $employee->id, '2026-10-25', '2026-11-05');

    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle($period, (int) $company->id, [], $user);

    expect($preview->canGenerate)->toBeFalse()
        ->and($preview->blockingIssues[0]['code'])->toBe('legacy_movement_outside_payroll_period')
        ->and($preview->blockingIssues[0]['message'])->toContain('25 Oct – 05 Nov 2026');

    expect(fn () => app(GenerateCrewPayroll::class)->handle($period, [], $user, true))
        ->toThrow(ValidationException::class);

    expect(PayrollRecord::query()->where('period_id', $period->id)->count())->toBe(0)
        ->and(PayrollWorkAllocation::query()->where('payroll_period_id', $period->id)->count())->toBe(0);
});

test('valid legacy flat movement inside payroll period still generates with future acknowledgment when needed', function () {
    ['user' => $user, 'company' => $company, 'period' => $period, 'employee' => $employee] = makeOctoberCrewPayrollAckFixtures();

    seedLegacyFlatOnsiteTimesheet(
        $period,
        (int) $company->id,
        (int) $employee->id,
        '2026-10-15',
        '2026-10-20',
        source: CrewTimesheetSource::Import,
    );

    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle($period, (int) $company->id, [], $user);

    expect($preview->canGenerate)->toBeTrue()
        ->and($preview->requiresFutureDaysAcknowledgment)->toBeTrue()
        ->and($preview->blockingCount)->toBe(0);

    expect(fn () => app(GenerateCrewPayroll::class)->handle($period, [], $user, false))
        ->toThrow(ValidationException::class);

    $result = app(GenerateCrewPayroll::class)->handle($period, [], $user, true);

    expect($result->generatedCount)->toBe(1);
});

test('segment-based prior-period arrears remain functional alongside legacy flat period boundary guard', function () {
    ['user' => $user, 'company' => $company, 'period' => $period, 'employee' => $employee] = makeOctoberCrewPayrollAckFixtures();

    seedFutureOnsiteSegment($period, (int) $company->id, (int) $employee->id, '2026-09-25', '2026-10-05');

    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle($period, (int) $company->id, [], $user);

    expect($preview->canGenerate)->toBeTrue()
        ->and($preview->blockingCount)->toBe(0)
        ->and($preview->requiresFutureDaysAcknowledgment)->toBeFalse();

    $result = app(GenerateCrewPayroll::class)->handle($period, [], $user, false);

    expect($result->generatedCount)->toBe(1)
        ->and(PayrollWorkAllocation::query()
            ->where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)
            ->count())->toBe(11);
});

test('restricted visibility still hides legacy flat-field employees from generation preview', function () {
    ['user' => $user, 'company' => $company, 'period' => $period, 'employee' => $employee] = makeOctoberCrewPayrollAckFixtures();
    seedLegacyFlatOnsiteTimesheet($period, (int) $company->id, (int) $employee->id, '2026-11-01', '2026-11-06');

    $restricted = User::factory()->create();
    grantCompanyPermissions($restricted, $company, [
        'payroll.periods.update',
        'payroll.periods.view',
    ], 'restricted-flat-boundary-role');
    restrictTestRoleEmployeeVisibility(
        $restricted,
        $company,
        [],
        'restricted-flat-boundary-role',
    );

    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle(
        $period,
        (int) $company->id,
        [],
        $restricted,
    );

    expect($preview->readyCount)->toBe(0)
        ->and($preview->blockingCount)->toBe(0)
        ->and($preview->canGenerate)->toBeFalse();
});
