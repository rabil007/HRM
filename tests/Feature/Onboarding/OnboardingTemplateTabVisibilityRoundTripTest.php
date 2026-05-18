<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\OnboardingTemplate;
use App\Models\User;
use App\Support\OnboardingTemplateTabVisibility;
use Inertia\Testing\AssertableInertia as Assert;

test('saving template with empty contract fields hides contract tab on employee profile', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'OB1',
        'name' => 'Onboardland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'OB1',
        'name' => 'Onboard Currency',
        'symbol' => 'O$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Onboard Co',
        'slug' => 'onboard-co',
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
        'employees.view',
    ]);

    $tasks = json_encode([
        'version' => 2,
        'stages' => [
            [
                'key' => 'draft',
                'label' => 'Draft',
                'employee_fields' => [['key' => 'name', 'required' => true]],
                'bank_account_fields' => [],
                'contract_fields' => [],
                'sea_service_fields' => [],
                'vaccination_fields' => [],
                'documents' => [],
            ],
        ],
    ]);

    $this->post('/onboarding/templates', [
        'name' => 'Office',
        'description' => null,
        'tasks_json' => $tasks,
        'is_default' => false,
    ])->assertRedirect(route('onboarding.templates.index'));

    $template = OnboardingTemplate::query()->where('company_id', $company->id)->first();
    expect($template)->not->toBeNull();

    $tabs = OnboardingTemplateTabVisibility::fromTasks($template->tasks);
    expect($tabs['contract'])->toBeFalse()
        ->and($tabs['sea_service'])->toBeFalse();

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'EMP9001',
            'name' => 'Tab Tester',
            'nationality_id' => $country->id,
            'status' => 'active',
            'onboarding_template_id' => $template->id,
        ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'contract_type' => 'unlimited',
        'start_date' => '2026-01-01',
        'end_date' => null,
        'labor_contract_id' => null,
        'status' => 'active',
    ]);

    $this->get(route('organization.employees.show', $employee))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employee')
            ->where('employee_tabs.contract', false)
            ->where('employee_tabs.sea_service', false));
});
