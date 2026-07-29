<?php

use App\Enums\LeaveApprovalApproverType;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\LeaveApprovalPolicy;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * @return array{user: User, company: Company}
 */
function makePolicyStateInvariantFixtures(): array
{
    $user = User::factory()->create();
    $country = Country::query()->create([
        'code' => 'PI'.fake()->unique()->numerify('##'),
        'name' => 'Policy Invariantland',
        'dial_code' => '+979',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'PI'.fake()->unique()->numerify('##'),
        'name' => 'Policy Invariant Currency',
        'symbol' => 'P$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Policy Invariant Co',
        'slug' => 'pi-'.fake()->unique()->numerify('####'),
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

test('default leave approval policy cannot be deactivated via status endpoint', function () {
    ['user' => $user, 'company' => $company] = makePolicyStateInvariantFixtures();
    $policy = ensureDefaultLeaveApprovalPolicy($company);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-approval-policies.update',
    ]);

    $this->actingAs($user)
        ->put("/attendance/leave-approval-policies/{$policy->id}/status", [
            'status' => 'inactive',
        ])
        ->assertSessionHasErrors('status');

    expect($policy->fresh()->status)->toBe('active')
        ->and($policy->fresh()->is_default)->toBeTrue();
});

test('default leave approval policy cannot be deactivated via update', function () {
    ['user' => $user, 'company' => $company] = makePolicyStateInvariantFixtures();
    $policy = ensureDefaultLeaveApprovalPolicy($company);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-approval-policies.update',
    ]);

    $this->actingAs($user)
        ->put("/attendance/leave-approval-policies/{$policy->id}", [
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
        ])
        ->assertSessionHasErrors('status');

    expect($policy->fresh()->status)->toBe('active');
});

test('only default policy cannot be unset via update', function () {
    ['user' => $user, 'company' => $company] = makePolicyStateInvariantFixtures();
    $policy = ensureDefaultLeaveApprovalPolicy($company);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-approval-policies.update',
    ]);

    $this->actingAs($user)
        ->put("/attendance/leave-approval-policies/{$policy->id}", [
            'name' => $policy->name,
            'description' => $policy->description,
            'is_default' => false,
            'status' => 'active',
            'steps' => [
                [
                    'approver_type' => LeaveApprovalApproverType::DepartmentManager->value,
                    'is_required' => true,
                ],
            ],
        ])
        ->assertSessionHasErrors([
            'is_default' => 'Select another policy as the company default before removing this default.',
        ]);

    expect($policy->fresh()->is_default)->toBeTrue();
});

test('inactive policy becomes active when set as company default', function () {
    ['user' => $user, 'company' => $company] = makePolicyStateInvariantFixtures();
    $currentDefault = ensureDefaultLeaveApprovalPolicy($company);

    $inactive = LeaveApprovalPolicy::factory()
        ->forCompany($company)
        ->withDepartmentManagerStep()
        ->create([
            'is_default' => false,
            'status' => 'inactive',
            'name' => 'Inactive candidate',
        ]);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-approval-policies.update',
    ]);

    $this->actingAs($user)
        ->put("/attendance/leave-approval-policies/{$inactive->id}/default")
        ->assertRedirect(route('attendance.leave-approval-policies.index'));

    expect($inactive->fresh()->is_default)->toBeTrue()
        ->and($inactive->fresh()->status)->toBe('active')
        ->and($currentDefault->fresh()->is_default)->toBeFalse();
});

test('company never ends with two default leave approval policies', function () {
    ['user' => $user, 'company' => $company] = makePolicyStateInvariantFixtures();
    $first = ensureDefaultLeaveApprovalPolicy($company);

    $second = LeaveApprovalPolicy::factory()
        ->forCompany($company)
        ->withDepartmentManagerStep()
        ->create(['is_default' => false, 'name' => 'Second policy']);

    $third = LeaveApprovalPolicy::factory()
        ->forCompany($company)
        ->withDepartmentManagerStep()
        ->create(['is_default' => false, 'name' => 'Third policy']);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-approval-policies.update',
    ]);

    $this->actingAs($user)
        ->put("/attendance/leave-approval-policies/{$second->id}/default")
        ->assertRedirect();

    expect(LeaveApprovalPolicy::query()->where('company_id', $company->id)->where('is_default', true)->count())->toBe(1)
        ->and($second->fresh()->is_default)->toBeTrue()
        ->and($first->fresh()->is_default)->toBeFalse();

    $this->put("/attendance/leave-approval-policies/{$third->id}/default")
        ->assertRedirect();

    expect(LeaveApprovalPolicy::query()->where('company_id', $company->id)->where('is_default', true)->count())->toBe(1)
        ->and($third->fresh()->is_default)->toBeTrue()
        ->and($second->fresh()->is_default)->toBeFalse();
});
