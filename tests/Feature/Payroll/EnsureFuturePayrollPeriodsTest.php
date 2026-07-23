<?php

use App\Enums\CrewTimesheetMode;
use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodCreationSource;
use App\Enums\PayrollPeriodStatus;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\PayrollPeriod;
use App\Support\Payroll\Actions\EnsureFuturePayrollPeriods;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function makeAutomationCompany(array $overrides = []): Company
{
    $country = Country::query()->create([
        'code' => 'AC'.fake()->unique()->numerify('##'),
        'name' => 'Automation Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'AC'.fake()->unique()->numerify('##'),
        'name' => 'Automation Currency',
        'symbol' => 'A$',
        'is_active' => true,
    ]);

    return Company::query()->create(array_merge([
        'name' => 'Automation Co',
        'slug' => 'automation-'.fake()->unique()->numerify('#####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ], $overrides));
}

test('action creates automatic crew and office periods for the rolling window', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 09:00:00', 'Asia/Dubai'));
    $company = makeAutomationCompany();

    $result = app(EnsureFuturePayrollPeriods::class)->handle($company);

    expect($result->createdCount)->toBe(6);
    expect($result->skippedCount)->toBe(0);
    expect($result->createdPeriodIds)->toHaveCount(6);

    $periods = PayrollPeriod::query()->where('company_id', $company->id)->get();
    expect($periods)->toHaveCount(6);

    foreach ($periods as $period) {
        expect($period->status)->toBe(PayrollPeriodStatus::Draft);
        expect($period->creation_source)->toBe(PayrollPeriodCreationSource::Automatic);
        expect($period->payment_date)->toBeNull();
        expect($period->generated_at)->toBeNull();
        expect($period->created_by)->toBeNull();
        expect($period->notes)->toBe('Automatically created');
        expect($period->automatic_period_key)->not->toBeNull();
    }

    $crew = $periods->firstWhere('name', 'July 2026 - Crew');
    expect($crew)->not->toBeNull();
    expect($crew->payroll_category)->toBe(PayrollCategory::Crew);
    expect($crew->crew_timesheet_mode)->toBe(CrewTimesheetMode::Hybrid);
    expect($crew->start_date->toDateString())->toBe('2026-07-01');
    expect($crew->end_date->toDateString())->toBe('2026-07-31');
    expect($crew->regular_period_key)->toBe('company:'.$company->id.':crew:2026-07');

    $office = $periods->firstWhere('name', 'July 2026 - Office');
    expect($office)->not->toBeNull();
    expect($office->payroll_category)->toBe(PayrollCategory::Office);
    expect($office->crew_timesheet_mode)->toBeNull();

    $months = $periods->pluck('name')->sort()->values()->all();
    expect($months)->toContain('August 2026 - Crew');
    expect($months)->toContain('September 2026 - Office');

    expect(DB::table('crew_timesheets')->count())->toBe(0);
    expect(DB::table('payroll_records')->count())->toBe(0);
});

test('action is idempotent across repeated runs', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 09:00:00', 'Asia/Dubai'));
    $company = makeAutomationCompany();
    $action = app(EnsureFuturePayrollPeriods::class);

    $action->handle($company);
    $second = $action->handle($company);

    expect($second->createdCount)->toBe(0);
    expect($second->skippedCount)->toBe(6);
    expect(PayrollPeriod::query()->where('company_id', $company->id)->count())->toBe(6);
});

test('months option controls the rolling window size', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 09:00:00', 'Asia/Dubai'));
    $company = makeAutomationCompany();

    $result = app(EnsureFuturePayrollPeriods::class)->handle($company, 1);

    expect($result->createdCount)->toBe(2);
    expect(PayrollPeriod::query()->where('company_id', $company->id)->count())->toBe(2);
});

test('automatic period key enforces database uniqueness', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 09:00:00', 'Asia/Dubai'));
    $company = makeAutomationCompany();

    PayrollPeriod::factory()->for($company)->automatic()->create([
        'automatic_period_key' => 'dup-key',
    ]);

    expect(fn () => PayrollPeriod::factory()->for($company)->automatic()->create([
        'automatic_period_key' => 'dup-key',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('existing user-created regular period blocks the automatic period', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 09:00:00', 'Asia/Dubai'));
    $company = makeAutomationCompany();

    PayrollPeriod::factory()->for($company)->regularMonth('2026-07')->create([
        'name' => 'July 2026 - Crew',
        'payroll_category' => PayrollCategory::Crew,
        'crew_timesheet_mode' => CrewTimesheetMode::Hybrid,
        'creation_source' => PayrollPeriodCreationSource::Manual,
    ]);

    app(EnsureFuturePayrollPeriods::class)->handle($company);

    $julyCrew = PayrollPeriod::query()
        ->where('company_id', $company->id)
        ->where('payroll_category', PayrollCategory::Crew->value)
        ->whereDate('start_date', '2026-07-01')
        ->get();

    expect($julyCrew)->toHaveCount(1);
    expect($julyCrew->first()->creation_source)->toBe(PayrollPeriodCreationSource::Manual);
    expect($julyCrew->first()->automatic_period_key)->toBeNull();
});

test('company local month is used at the UTC month boundary', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-31 23:00:00', 'UTC'));

    $ahead = makeAutomationCompany(['timezone' => 'Asia/Dubai']);
    $behind = makeAutomationCompany(['timezone' => 'America/Los_Angeles']);

    $action = app(EnsureFuturePayrollPeriods::class);
    $action->handle($ahead);
    $action->handle($behind);

    $aheadFirst = PayrollPeriod::query()
        ->where('company_id', $ahead->id)
        ->orderBy('start_date')
        ->first();
    expect($aheadFirst->start_date->toDateString())->toBe('2026-08-01');

    $behindFirst = PayrollPeriod::query()
        ->where('company_id', $behind->id)
        ->orderBy('start_date')
        ->first();
    expect($behindFirst->start_date->toDateString())->toBe('2026-07-01');
});

test('leap year february end date is correct', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2028-02-10 09:00:00', 'Asia/Dubai'));
    $company = makeAutomationCompany();

    app(EnsureFuturePayrollPeriods::class)->handle($company);

    $feb = PayrollPeriod::query()
        ->where('company_id', $company->id)
        ->where('name', 'February 2028 - Office')
        ->first();

    expect($feb)->not->toBeNull();
    expect($feb->end_date->toDateString())->toBe('2028-02-29');
});

test('year transition rolls into the next january', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-15 09:00:00', 'Asia/Dubai'));
    $company = makeAutomationCompany();

    app(EnsureFuturePayrollPeriods::class)->handle($company);

    $names = PayrollPeriod::query()
        ->where('company_id', $company->id)
        ->pluck('name')
        ->all();

    expect($names)->toContain('December 2026 - Office');
    expect($names)->toContain('January 2027 - Office');
    expect($names)->toContain('February 2027 - Office');
});

test('running for one company does not create periods for another', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 09:00:00', 'Asia/Dubai'));
    $companyA = makeAutomationCompany();
    $companyB = makeAutomationCompany();

    app(EnsureFuturePayrollPeriods::class)->handle($companyA);

    expect(PayrollPeriod::query()->where('company_id', $companyA->id)->count())->toBe(6);
    expect(PayrollPeriod::query()->where('company_id', $companyB->id)->count())->toBe(0);
});

test('command processes only active companies', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 09:00:00', 'Asia/Dubai'));

    $active = makeAutomationCompany(['status' => 'active']);
    $suspended = makeAutomationCompany(['status' => 'suspended']);
    $inactive = makeAutomationCompany(['status' => 'inactive']);
    $deleted = makeAutomationCompany(['status' => 'active']);
    $deleted->delete();

    $this->artisan('payroll:ensure-future-periods')->assertSuccessful();

    expect(PayrollPeriod::query()->where('company_id', $active->id)->count())->toBe(6);
    expect(PayrollPeriod::query()->where('company_id', $suspended->id)->count())->toBe(0);
    expect(PayrollPeriod::query()->where('company_id', $inactive->id)->count())->toBe(0);
    expect(PayrollPeriod::query()->where('company_id', $deleted->id)->count())->toBe(0);
});

test('command can target a single company', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 09:00:00', 'Asia/Dubai'));
    $companyA = makeAutomationCompany();
    $companyB = makeAutomationCompany();

    $this->artisan('payroll:ensure-future-periods', ['--company' => $companyA->id])
        ->assertSuccessful();

    expect(PayrollPeriod::query()->where('company_id', $companyA->id)->count())->toBe(6);
    expect(PayrollPeriod::query()->where('company_id', $companyB->id)->count())->toBe(0);
});

test('command accepts a custom months window', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 09:00:00', 'Asia/Dubai'));
    $company = makeAutomationCompany();

    $this->artisan('payroll:ensure-future-periods', ['--months' => 2])
        ->assertSuccessful();

    expect(PayrollPeriod::query()->where('company_id', $company->id)->count())->toBe(4);
});

test('command fails for an unknown company', function () {
    $this->artisan('payroll:ensure-future-periods', ['--company' => 999999])
        ->assertFailed();
});

test('command is idempotent when run repeatedly', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 09:00:00', 'Asia/Dubai'));
    $company = makeAutomationCompany();

    $this->artisan('payroll:ensure-future-periods')->assertSuccessful();
    $this->artisan('payroll:ensure-future-periods')->assertSuccessful();

    expect(PayrollPeriod::query()->where('company_id', $company->id)->count())->toBe(6);
});

test('newly activated company receives missing periods on the next run', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-15 09:00:00', 'Asia/Dubai'));
    $existing = makeAutomationCompany();

    $this->artisan('payroll:ensure-future-periods')->assertSuccessful();
    expect(PayrollPeriod::query()->where('company_id', $existing->id)->count())->toBe(6);

    $new = makeAutomationCompany();
    $this->artisan('payroll:ensure-future-periods')->assertSuccessful();

    expect(PayrollPeriod::query()->where('company_id', $new->id)->count())->toBe(6);
    expect(PayrollPeriod::query()->where('company_id', $existing->id)->count())->toBe(6);
});

test('payroll hub resource exposes creation source', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.view']);

    PayrollPeriod::factory()->for($company)->automatic()->create([
        'name' => 'Automatic Run',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('payroll/index')
            ->has('periods', 1)
            ->where('periods.0.creation_source', 'automatic')
            ->where('periods.0.creation_source_label', 'Created by system')
            ->where('periods.0.is_automatic', true));
});
