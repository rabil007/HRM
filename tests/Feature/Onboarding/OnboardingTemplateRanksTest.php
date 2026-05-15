<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\OnboardingTemplate;
use App\Models\Rank;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

test('onboarding template store syncs selected ranks', function () {
    [$user, $company] = setupCompanyForOnboardingRanks();

    $captain = Rank::query()->create(['name' => 'Captain', 'is_active' => true]);
    $mate = Rank::query()->create(['name' => 'Chief Mate', 'is_active' => true]);

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
            'rank_ids' => [$captain->id, $mate->id],
        ])
        ->assertRedirect(route('onboarding.templates.index'));

    $template = OnboardingTemplate::query()->where('company_id', $company->id)->first();

    expect($template)->not->toBeNull();
    expect($template->ranks()->pluck('ranks.id')->all())->toEqual([$captain->id, $mate->id]);
});

test('employee create filters templates by rank', function () {
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

    $general = OnboardingTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'General',
        'is_default' => true,
        'tasks' => $tasks,
    ]);

    $captainTemplate = OnboardingTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Captain flow',
        'is_default' => false,
        'tasks' => $tasks,
    ]);
    $captainTemplate->ranks()->sync([$captainRank->id]);

    $mateTemplate = OnboardingTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Mate flow',
        'is_default' => false,
        'tasks' => $tasks,
    ]);
    $mateTemplate->ranks()->sync([$mateRank->id]);

    $this->actingAs($user)
        ->get("/organization/employees/create?rank_id={$captainRank->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employee-create')
            ->where('template.name', 'Captain flow')
            ->where('selectedRankId', $captainRank->id)
            ->has('allTemplates', 1)
        );

    $this->actingAs($user)
        ->get("/organization/employees/create?rank_id={$mateRank->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('template.name', 'Mate flow')
        );

    expect($general->appliesToRank($captainRank->id))->toBeTrue();
    expect($captainTemplate->appliesToRank($captainRank->id))->toBeTrue();
    expect($mateTemplate->appliesToRank($captainRank->id))->toBeFalse();
});
