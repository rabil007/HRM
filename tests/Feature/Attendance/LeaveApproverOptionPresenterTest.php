<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\User;
use App\Support\Attendance\PresentLeaveApproverOption;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{user: User, company: Company}
 */
function makeApproverOptionFixtures(): array
{
    $user = User::factory()->create();
    $country = Country::query()->create([
        'code' => 'AO'.fake()->unique()->numerify('##'),
        'name' => 'Approver Optionland',
        'dial_code' => '+994',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'AO'.fake()->unique()->numerify('##'),
        'name' => 'Approver Currency',
        'symbol' => 'A$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Approver Option Co',
        'slug' => 'ao-'.fake()->unique()->numerify('####'),
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

test('presenter marks employees without approve permission as not actionable', function () {
    ['company' => $company] = makeApproverOptionFixtures();

    $linkedUser = User::factory()->create(['status' => 'active']);
    DB::table('company_user')->insert([
        'company_id' => $company->id,
        'user_id' => $linkedUser->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'user_id' => $linkedUser->id,
        'name' => 'No Approve',
    ]);

    $presented = app(PresentLeaveApproverOption::class)->present($employee, (int) $company->id);

    expect($presented['actionable'])->toBeFalse()
        ->and($presented['has_linked_user'])->toBeTrue()
        ->and($presented['linked_user_active'])->toBeTrue()
        ->and($presented['has_leave_request_approve_permission'])->toBeFalse()
        ->and($presented['warnings'])->not->toBeEmpty();
});

test('policy index employee options include actionable warnings and stay company scoped', function () {
    ['user' => $user, 'company' => $company] = makeApproverOptionFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-approval-policies.view',
    ]);

    ['employee' => $actionable] = makeActionableApprover($company, ['name' => 'Actionable One']);

    $otherCountry = Country::query()->create([
        'code' => 'AZ'.fake()->unique()->numerify('##'),
        'name' => 'Other Optionland',
        'dial_code' => '+995',
        'is_active' => true,
    ]);
    $otherCurrency = Currency::query()->create([
        'code' => 'AZ'.fake()->unique()->numerify('##'),
        'name' => 'Other Option Currency',
        'symbol' => 'Z$',
        'is_active' => true,
    ]);
    $otherCompany = Company::query()->create([
        'name' => 'Other Option Co',
        'slug' => 'az-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $otherCountry->id,
        'currency_id' => $otherCurrency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
    Employee::factory()->forCompany($otherCompany)->create([
        'status' => 'active',
        'name' => 'Other Company Employee',
    ]);

    $this->get('/attendance/leave-approval-policies')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('attendance/leave-approval-policies')
            ->has('employees')
            ->where('employees', function ($employees) use ($actionable) {
                $rows = collect($employees);
                expect($rows->pluck('name')->all())->toContain('Actionable One')
                    ->and($rows->pluck('name')->all())->not->toContain('Other Company Employee');

                $match = $rows->firstWhere('id', $actionable->id);

                return is_array($match)
                    && ($match['actionable'] ?? false) === true
                    && ($match['has_active_company_membership'] ?? false) === true
                    && array_key_exists('warnings', $match)
                    && array_key_exists('has_leave_request_approve_permission', $match);
            }));
});

test('inactive company membership makes approver non-actionable', function () {
    ['company' => $company] = makeApproverOptionFixtures();
    ['employee' => $approver, 'user' => $approverUser] = makeActionableApprover($company, [
        'name' => 'Inactive Membership',
    ]);

    DB::table('company_user')
        ->where('company_id', $company->id)
        ->where('user_id', $approverUser->id)
        ->update(['status' => 'inactive']);

    $presented = app(PresentLeaveApproverOption::class)->present($approver, (int) $company->id);

    expect($presented['has_active_company_membership'])->toBeFalse()
        ->and($presented['actionable'])->toBeFalse()
        ->and($presented['warnings'])->not->toBeEmpty();
});

test('selected inactive employee remains visible via forCompany includeEmployeeIds', function () {
    ['company' => $company] = makeApproverOptionFixtures();

    $inactive = Employee::factory()->forCompany($company)->create([
        'status' => 'inactive',
        'name' => 'Inactive Selected',
        'user_id' => null,
    ]);

    $options = app(PresentLeaveApproverOption::class)->forCompany(
        (int) $company->id,
        activeOnly: true,
        includeEmployeeIds: [$inactive->id],
    );

    $match = collect($options)->firstWhere('id', $inactive->id);

    expect($match)->not->toBeNull()
        ->and($match['name'])->toBe('Inactive Selected')
        ->and($match['employee_status'])->toBe('inactive');
});

test('foreign company employees never appear in forCompany approver options', function () {
    ['company' => $company] = makeApproverOptionFixtures();

    $otherCountry = Country::query()->create([
        'code' => 'FO'.fake()->unique()->numerify('##'),
        'name' => 'Foreign Optionland',
        'dial_code' => '+993',
        'is_active' => true,
    ]);
    $otherCurrency = Currency::query()->create([
        'code' => 'FO'.fake()->unique()->numerify('##'),
        'name' => 'Foreign Option Currency',
        'symbol' => 'F$',
        'is_active' => true,
    ]);
    $otherCompany = Company::query()->create([
        'name' => 'Foreign Option Co',
        'slug' => 'fo-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $otherCountry->id,
        'currency_id' => $otherCurrency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    Employee::factory()->forCompany($otherCompany)->create([
        'status' => 'active',
        'name' => 'Foreign Only Employee',
    ]);

    ['employee' => $local] = makeActionableApprover($company, ['name' => 'Local Approver']);

    $options = app(PresentLeaveApproverOption::class)->forCompany((int) $company->id);

    expect(collect($options)->pluck('name')->all())
        ->toContain('Local Approver')
        ->not->toContain('Foreign Only Employee');
});
