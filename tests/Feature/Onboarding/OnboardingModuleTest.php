<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\OnboardingTemplate;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;

test('guests cannot access onboarding templates and records', function () {
    $this->get('/onboarding/templates')->assertRedirect(route('login'));
});

test('authorized users can view onboarding templates page', function () {
    $this->seed(PermissionsSeeder::class);

    $user = User::factory()->create();
    $this->actingAs($user);

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
        'onboarding.templates.view',
    ]);

    $this->get('/onboarding/templates')->assertOk();
});

test('authorized users can update an onboarding template with tasks_json', function () {
    $this->seed(PermissionsSeeder::class);

    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'UP1',
        'name' => 'Updateland',
        'dial_code' => '+888',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'UP1',
        'name' => 'Update Currency',
        'symbol' => 'U$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Update Co',
        'slug' => 'update-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, [
        'onboarding.templates.update',
    ]);

    $template = OnboardingTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Original',
        'description' => null,
        'tasks' => [
            'version' => 2,
            'stages' => [
                [
                    'key' => 'draft',
                    'label' => 'Draft',
                    'employee_fields' => [],
                    'bank_account_fields' => [],
                    'contract_fields' => [],
                    'sea_service_fields' => [],
                    'vaccination_fields' => [],
                    'documents' => [],
                ],
            ],
        ],
        'is_default' => false,
    ]);

    $tasks = json_encode([
        'version' => 2,
        'stages' => [
            [
                'key' => 'profile',
                'label' => 'Profile',
                'employee_fields' => [['key' => 'name', 'required' => true]],
                'bank_account_fields' => [],
                'contract_fields' => [],
                'sea_service_fields' => [],
                'vaccination_fields' => [],
                'documents' => [],
            ],
        ],
    ]);

    $this->put(route('onboarding.templates.update', $template), [
        'name' => 'Renamed',
        'description' => 'New description',
        'tasks_json' => $tasks,
        'is_default' => false,
    ])->assertRedirect(route('onboarding.templates.index'));

    $template->refresh();

    expect($template->name)->toBe('Renamed')
        ->and($template->description)->toBe('New description')
        ->and($template->tasks['stages'][0]['key'])->toBe('profile');
});
