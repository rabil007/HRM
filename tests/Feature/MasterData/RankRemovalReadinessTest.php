<?php

use App\Enums\SavedViewPage;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\Position;
use App\Models\SavedView;
use App\Models\User;
use App\Support\MasterData\RankRemovalReadiness;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

test('rank removal readiness reports ready when rank schema is already removed', function () {
    expect(Schema::hasTable('ranks'))->toBeFalse();

    $exit = Artisan::call('master-data:rank-removal-readiness');

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('Ready for Rank removal: YES');

    $report = (new RankRemovalReadiness)->report();
    expect($report['ready'])->toBeTrue()
        ->and($report['already_removed'])->toBeTrue();
});

test('rank removal readiness empty totals when schema removed even with position-only employees', function () {
    $country = Country::query()->create([
        'code' => 'RR'.fake()->unique()->numerify('##'),
        'name' => 'Readiness Land',
        'dial_code' => '+001',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'RR'.fake()->unique()->numerify('##'),
        'name' => 'Readiness Currency',
        'symbol' => 'R$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Readiness Co',
        'slug' => 'readiness-'.Str::lower(Str::random(6)),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
    $position = Position::query()->create([
        'company_id' => $company->id,
        'title' => 'Readiness Position',
        'status' => 'active',
        'is_crew_position' => true,
    ]);

    Employee::factory()->forCompany($company)->create([
        'position_id' => $position->id,
        'status' => 'active',
    ]);

    SavedView::query()->create([
        'company_id' => $company->id,
        'user_id' => User::factory()->create()->id,
        'page_key' => SavedViewPage::Employees,
        'name' => 'Legacy Rank View',
        'filters' => ['position_id' => 999999],
        'is_default' => false,
    ]);

    $report = (new RankRemovalReadiness)->report((int) $company->id);

    expect($report['ready'])->toBeTrue()
        ->and($report['already_removed'])->toBeTrue();
});
