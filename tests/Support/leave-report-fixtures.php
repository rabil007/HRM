<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * @return array{
 *     user: User,
 *     company: Company,
 *     employee: Employee,
 *     leaveType: LeaveType
 * }
 */
function authorizeLeaveReport(): array
{
    $user = User::factory()->create();
    $country = Country::query()->create([
        'code' => 'LR'.fake()->unique()->numerify('##'),
        'name' => 'Leave Reportland',
        'dial_code' => '+999',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'LR'.fake()->unique()->numerify('##'),
        'name' => 'Leave Report Currency',
        'symbol' => 'L$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Leave Report Co',
        'slug' => 'leave-report-'.fake()->unique()->numerify('####'),
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

    $user->update(['current_company_id' => $company->id]);
    grantCompanyPermissions($user, $company, [
        'reports.leave.view',
        'reports.leave.export',
        'employees.view',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Report Employee',
        'employee_no' => 'LR-001',
    ]);

    $leaveType = LeaveType::factory()->for($company)->create([
        'name' => 'Annual Leave',
        'status' => 'active',
    ]);

    return compact('user', 'company', 'employee', 'leaveType');
}
