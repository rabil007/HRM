<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\OnboardingTemplate;
use App\Models\Rank;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function setupCompanyForOnboardingRanks(): array
{
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'TST',
        'name' => 'Testland',
        'dial_code' => '+999',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TST',
        'name' => 'Test Currency',
        'symbol' => 'T$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, [
        'onboarding.templates.create',
        'onboarding.templates.update',
        'employees.create',
    ]);

    return [$user, $company];
}

test('onboarding_template_rank pivot is removed after migrations', function () {
    expect(Schema::hasTable('onboarding_template_rank'))->toBeFalse();
});

test('onboarding template can be stored without rank pivot', function () {
    [$user, $company] = setupCompanyForOnboardingRanks();

    Rank::query()->create(['name' => 'Captain', 'is_active' => true]);

    $tasks = json_encode([
        'version' => 2,
        'stages' => [
            [
                'key' => 'profile',
                'label' => 'Profile',
                'employee_fields' => [],
                'bank_account_fields' => [],
                'contract_fields' => [],
                'documents' => [],
            ],
        ],
    ]);

    $this->actingAs($user)
        ->post('/onboarding/templates', [
            'name' => 'Captain onboarding',
            'description' => 'For captains',
            'tasks_json' => $tasks,
            'is_default' => false,
        ])
        ->assertRedirect(route('onboarding.templates.index'));

    expect(OnboardingTemplate::query()->where('company_id', $company->id)->count())->toBe(1);
});

test('employee create lists all templates regardless of rank', function () {
    [$user, $company] = setupCompanyForOnboardingRanks();

    $captainRank = Rank::query()->create(['name' => 'Captain', 'is_active' => true]);
    $mateRank = Rank::query()->create(['name' => 'Chief Mate', 'is_active' => true]);

    $tasks = [
        'version' => 2,
        'stages' => [
            [
                'key' => 'profile',
                'label' => 'Profile',
                'employee_fields' => [['key' => 'name', 'required' => true]],
                'bank_account_fields' => [],
                'contract_fields' => [],
                'documents' => [],
            ],
        ],
    ];

    OnboardingTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'General',
        'is_default' => true,
        'tasks' => $tasks,
    ]);

    OnboardingTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Captain flow',
        'is_default' => false,
        'tasks' => $tasks,
    ]);

    OnboardingTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Mate flow',
        'is_default' => false,
        'tasks' => $tasks,
    ]);

    $this->actingAs($user)
        ->get("/organization/employees/create?rank_id={$captainRank->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employee-create')
            ->where('template.name', 'General')
            ->where('selectedRankId', $captainRank->id)
            ->has('allTemplates', 3));

    $mateTemplate = OnboardingTemplate::query()->where('name', 'Mate flow')->firstOrFail();

    $this->actingAs($user)
        ->get("/organization/employees/create?rank_id={$mateRank->id}&template_id={$mateTemplate->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('template.name', 'Mate flow')
            ->where('selectedRankId', $mateRank->id));
});
