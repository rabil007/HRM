<?php

use App\Enums\CrewTimesheetMode;
use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodCreationSource;
use App\Models\PayrollPeriod;
use App\Support\Payroll\Actions\EnsureFuturePayrollPeriods;
use App\Support\Payroll\RegularPayrollPeriodKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

test('duplicate regular crew period for the same month is prevented', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, ['payroll.periods.create']);

    PayrollPeriod::factory()->for($company)->regularMonth('2026-07')->hybridTimesheets()->create([
        'name' => 'July 2026 - Crew',
        'creation_source' => PayrollPeriodCreationSource::Automatic,
        'automatic_period_key' => 'company:'.$company->id.':crew:2026-07',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.periods.store'), [
            'name' => 'July 2026 Crew Duplicate',
            'payroll_category' => 'crew',
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-31',
        ])
        ->assertSessionHasErrors('start_date');

    expect(PayrollPeriod::query()
        ->where('company_id', $company->id)
        ->where('payroll_category', PayrollCategory::Crew->value)
        ->whereDate('start_date', '2026-07-01')
        ->count())->toBe(1);
});

test('duplicate regular office period for the same month is prevented', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, ['payroll.periods.create']);

    PayrollPeriod::factory()->for($company)->office()->regularMonth('2026-07')->create([
        'name' => 'July 2026 - Office',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.periods.store'), [
            'name' => 'July 2026 Office Duplicate',
            'payroll_category' => 'office',
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-31',
        ])
        ->assertSessionHasErrors('start_date');
});

test('off-cycle non-month periods are still allowed alongside a regular period', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, ['payroll.periods.create']);

    PayrollPeriod::factory()->for($company)->regularMonth('2026-07')->hybridTimesheets()->create([
        'name' => 'July 2026 - Crew',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.periods.store'), [
            'name' => 'July 2026 Correction',
            'payroll_category' => 'crew',
            'start_date' => '2026-07-15',
            'end_date' => '2026-07-31',
        ])
        ->assertRedirect(route('payroll.index'));

    $correction = PayrollPeriod::query()
        ->where('company_id', $company->id)
        ->where('name', 'July 2026 Correction')
        ->firstOrFail();

    expect($correction->regular_period_key)->toBeNull()
        ->and($correction->crew_timesheet_mode)->toBe(CrewTimesheetMode::Hybrid);
});

test('regular period key is concurrency safe at the database layer', function () {
    ['company' => $company] = makePayrollFixtures();
    $key = RegularPayrollPeriodKey::for((int) $company->id, PayrollCategory::Crew, CarbonImmutable::parse('2026-08-01'));

    PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'name' => 'August 2026 - Crew',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
        'regular_period_key' => $key,
    ]);

    expect(fn () => PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'name' => 'August 2026 Duplicate',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
        'regular_period_key' => $key,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('scheduler skips an existing manually created regular period', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 09:00:00', 'Asia/Dubai'));
    ['company' => $company] = makePayrollFixtures();
    $company->update(['timezone' => 'Asia/Dubai', 'status' => 'active']);

    PayrollPeriod::factory()->for($company)->regularMonth('2026-08')->hybridTimesheets()->create([
        'name' => 'August 2026 - Crew',
        'creation_source' => PayrollPeriodCreationSource::Manual,
    ]);

    $result = app(EnsureFuturePayrollPeriods::class)->handle($company);

    $augustCrew = PayrollPeriod::query()
        ->where('company_id', $company->id)
        ->where('payroll_category', PayrollCategory::Crew->value)
        ->whereDate('start_date', '2026-08-01')
        ->get();

    expect($augustCrew)->toHaveCount(1)
        ->and($augustCrew->first()->creation_source)->toBe(PayrollPeriodCreationSource::Manual)
        ->and($result->createdSummary)->not->toContain([
            'month' => '2026-08',
            'category' => 'crew',
        ]);

    CarbonImmutable::setTestNow();
});

test('regular period uniqueness is tenant scoped', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    ['company' => $otherCompany] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, ['payroll.periods.create']);

    PayrollPeriod::factory()->for($otherCompany)->regularMonth('2026-07')->hybridTimesheets()->create([
        'name' => 'July 2026 - Crew',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.periods.store'), [
            'name' => 'July 2026 Crew',
            'payroll_category' => 'crew',
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-31',
        ])
        ->assertRedirect(route('payroll.index'));

    expect(PayrollPeriod::query()
        ->where('company_id', $company->id)
        ->where('regular_period_key', 'company:'.$company->id.':crew:2026-07')
        ->exists())->toBeTrue();
});
