<?php

use App\Enums\LeaveApprovalApproverType;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveApprovalPolicy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{user: User, company: Company}
 */
function makeLeaveApprovalPolicyFixtures(): array
{
    $user = User::factory()->create();
    $country = Country::query()->create([
        'code' => 'AP'.fake()->unique()->numerify('##'),
        'name' => 'Approval Policyland',
        'dial_code' => '+997',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'AP'.fake()->unique()->numerify('##'),
        'name' => 'Approval Currency',
        'symbol' => 'A$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Approval Policy Co',
        'slug' => 'ap-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    DB::table('company_user')->insert([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['user' => $user, 'company' => $company];
}

test('guests cannot access leave approval policies', function () {
    $this->get('/attendance/leave-approval-policies')->assertRedirect(route('login'));
});

test('authorized users can manage leave approval policies', function () {
    ['user' => $user, 'company' => $company] = makeLeaveApprovalPolicyFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-approval-policies.view',
        'attendance.leave-approval-policies.create',
        'attendance.leave-approval-policies.update',
        'attendance.leave-approval-policies.delete',
    ]);

    $this->get('/attendance/leave-approval-policies')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('attendance/leave-approval-policies'));

    $this->post('/attendance/leave-approval-policies', [
        'name' => 'Manager then HR',
        'description' => 'Standard chain',
        'is_default' => true,
        'status' => 'active',
        'steps' => [
            [
                'approver_type' => LeaveApprovalApproverType::DepartmentManager->value,
                'approver_employee_id' => null,
                'is_required' => true,
            ],
            [
                'approver_type' => LeaveApprovalApproverType::HrApprover->value,
                'approver_employee_id' => null,
                'is_required' => true,
            ],
        ],
    ])->assertRedirect(route('attendance.leave-approval-policies.index'));

    $policy = LeaveApprovalPolicy::query()->where('company_id', $company->id)->first();

    expect($policy)->not->toBeNull()
        ->and($policy->is_default)->toBeTrue()
        ->and($policy->steps)->toHaveCount(2);

    $this->put("/attendance/leave-approval-policies/{$policy->id}", [
        'name' => 'Manager only',
        'description' => 'Simplified',
        'is_default' => true,
        'status' => 'active',
        'steps' => [
            [
                'approver_type' => LeaveApprovalApproverType::DepartmentManager->value,
                'is_required' => true,
            ],
        ],
    ])->assertRedirect(route('attendance.leave-approval-policies.index'));

    expect($policy->fresh()->name)->toBe('Manager only')
        ->and($policy->fresh()->steps)->toHaveCount(1);
});

test('default policy cannot be deleted or deactivated', function () {
    ['user' => $user, 'company' => $company] = makeLeaveApprovalPolicyFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-approval-policies.view',
        'attendance.leave-approval-policies.update',
        'attendance.leave-approval-policies.delete',
    ]);

    $policy = ensureDefaultLeaveApprovalPolicy($company);

    $this->put("/attendance/leave-approval-policies/{$policy->id}/status", [
        'status' => 'inactive',
    ])->assertSessionHasErrors('status');

    $this->put("/attendance/leave-approval-policies/{$policy->id}", [
        'name' => $policy->name,
        'description' => $policy->description,
        'is_default' => true,
        'status' => 'inactive',
        'steps' => [
            [
                'approver_type' => LeaveApprovalApproverType::DepartmentManager->value,
                'is_required' => true,
            ],
        ],
    ])->assertSessionHasErrors('status');

    expect($policy->fresh()->status)->toBe('active')
        ->and($policy->fresh()->is_default)->toBeTrue();

    $this->delete("/attendance/leave-approval-policies/{$policy->id}")
        ->assertSessionHasErrors('policy');

    expect($policy->fresh())->not->toBeNull();
});

test('set default only affects the active company', function () {
    ['user' => $user, 'company' => $company] = makeLeaveApprovalPolicyFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-approval-policies.update',
    ]);

    $companyDefault = ensureDefaultLeaveApprovalPolicy($company);
    $companyOther = LeaveApprovalPolicy::factory()
        ->forCompany($company)
        ->withDepartmentManagerStep()
        ->create(['is_default' => false, 'name' => 'Company B policy candidate']);

    $otherCountry = Country::query()->create([
        'code' => 'AQ'.fake()->unique()->numerify('##'),
        'name' => 'Other Policyland',
        'dial_code' => '+998',
        'is_active' => true,
    ]);
    $otherCurrency = Currency::query()->create([
        'code' => 'AQ'.fake()->unique()->numerify('##'),
        'name' => 'Other Policy Currency',
        'symbol' => 'Q$',
        'is_active' => true,
    ]);
    $otherCompany = Company::query()->create([
        'name' => 'Other Policy Co',
        'slug' => 'aq-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $otherCountry->id,
        'currency_id' => $otherCurrency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
    $otherDefault = ensureDefaultLeaveApprovalPolicy($otherCompany);

    $this->put("/attendance/leave-approval-policies/{$companyOther->id}/default")
        ->assertRedirect(route('attendance.leave-approval-policies.index'));

    expect($companyDefault->fresh()->is_default)->toBeFalse()
        ->and($companyOther->fresh()->is_default)->toBeTrue()
        ->and($otherDefault->fresh()->is_default)->toBeTrue()
        ->and(LeaveApprovalPolicy::query()->where('company_id', $company->id)->where('is_default', true)->count())->toBe(1)
        ->and(LeaveApprovalPolicy::query()->where('company_id', $otherCompany->id)->where('is_default', true)->count())->toBe(1);
});

test('policy assigned to a department cannot be deleted', function () {
    ['user' => $user, 'company' => $company] = makeLeaveApprovalPolicyFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-approval-policies.delete',
    ]);

    $policy = LeaveApprovalPolicy::factory()
        ->forCompany($company)
        ->withDepartmentManagerStep()
        ->create(['is_default' => false]);

    Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Ops',
        'code' => 'OPS',
        'leave_approval_policy_id' => $policy->id,
        'status' => 'active',
    ]);

    $this->delete("/attendance/leave-approval-policies/{$policy->id}")
        ->assertSessionHasErrors('policy');

    expect($policy->fresh())->not->toBeNull();
});

test('set default switches company default policy', function () {
    ['user' => $user, 'company' => $company] = makeLeaveApprovalPolicyFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-approval-policies.update',
    ]);

    $first = ensureDefaultLeaveApprovalPolicy($company);
    $second = LeaveApprovalPolicy::factory()
        ->forCompany($company)
        ->withDepartmentManagerStep()
        ->create(['is_default' => false]);

    $this->put("/attendance/leave-approval-policies/{$second->id}/default")
        ->assertRedirect(route('attendance.leave-approval-policies.index'));

    expect($first->fresh()->is_default)->toBeFalse()
        ->and($second->fresh()->is_default)->toBeTrue()
        ->and($second->fresh()->status)->toBe('active');
});

test('immediate move step endpoint is removed and save persists step order', function () {
    ['user' => $user, 'company' => $company] = makeLeaveApprovalPolicyFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-approval-policies.update',
        'attendance.leave-approval-policies.view',
    ]);

    $policy = LeaveApprovalPolicy::factory()
        ->forCompany($company)
        ->withSteps([
            ['type' => LeaveApprovalApproverType::DepartmentManager],
            ['type' => LeaveApprovalApproverType::HrApprover],
        ])
        ->create(['is_default' => false]);

    $first = $policy->steps()->where('sequence', 1)->first();
    $second = $policy->steps()->where('sequence', 2)->first();

    $this->put("/attendance/leave-approval-policies/{$policy->id}/steps/{$second->id}/move", [
        'direction' => 'up',
    ])->assertNotFound();

    expect(Route::has('attendance.leave-approval-policies.steps.move'))->toBeFalse();

    $this->put("/attendance/leave-approval-policies/{$policy->id}", [
        'name' => $policy->name,
        'description' => $policy->description,
        'status' => $policy->status,
        'is_default' => false,
        'steps' => [
            [
                'id' => $second->id,
                'approver_type' => LeaveApprovalApproverType::HrApprover->value,
                'approver_employee_id' => null,
                'is_required' => true,
            ],
            [
                'id' => $first->id,
                'approver_type' => LeaveApprovalApproverType::DepartmentManager->value,
                'approver_employee_id' => null,
                'is_required' => true,
            ],
        ],
    ])->assertRedirect(route('attendance.leave-approval-policies.index'));

    $ordered = $policy->fresh()->steps()->orderBy('sequence')->get();
    expect($ordered)->toHaveCount(2)
        ->and((int) $ordered[0]->id)->toBe((int) $second->id)
        ->and($ordered[0]->approver_type->value)->toBe(LeaveApprovalApproverType::HrApprover->value)
        ->and((int) $ordered[1]->id)->toBe((int) $first->id)
        ->and($ordered[1]->approver_type->value)->toBe(LeaveApprovalApproverType::DepartmentManager->value);
});

test('leave approval settings can be updated with validation warnings', function () {
    ['user' => $user, 'company' => $company] = makeLeaveApprovalPolicyFixtures();
    $inactive = Employee::factory()->forCompany($company)->create(['status' => 'inactive', 'user_id' => null]);
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-approval-settings.view',
        'attendance.leave-approval-settings.update',
    ]);

    $this->get('/attendance/leave-approval-settings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('attendance/leave-approval-settings'));

    $this->put('/attendance/leave-approval-settings', [
        'default_hr_approver_employee_id' => $inactive->id,
        'fallback_approver_employee_id' => null,
    ])
        ->assertRedirect(route('attendance.leave-approval-settings.edit'))
        ->assertSessionHas('warning');
});

test('cross-company leave approval policy is not visible', function () {
    ['user' => $user, 'company' => $company] = makeLeaveApprovalPolicyFixtures();
    $other = Company::query()->create([
        'name' => 'Other Approval Co',
        'slug' => 'other-ap-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $company->country_id,
        'currency_id' => $company->currency_id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
    $foreign = LeaveApprovalPolicy::factory()->forCompany($other)->withDepartmentManagerStep()->create();

    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'attendance.leave-approval-policies.view',
        'attendance.leave-approval-policies.update',
    ]);

    $this->put("/attendance/leave-approval-policies/{$foreign->id}", [
        'name' => 'Hacked',
        'status' => 'active',
        'steps' => [
            ['approver_type' => LeaveApprovalApproverType::DepartmentManager->value, 'is_required' => true],
        ],
    ])->assertNotFound();
});
