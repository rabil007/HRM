<?php

use App\Enums\LeaveApprovalApproverType;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\Actions\SubmitLeaveRequestWithApprovals;
use App\Support\Attendance\LeaveBalanceManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * @return array{user: User, company: Company, employee: Employee, leaveType: LeaveType}
 */
function makeIndexQueryCountFixtures(): array
{
    $user = User::factory()->create(['status' => 'active']);
    $country = Country::query()->create([
        'code' => 'IQ'.fake()->unique()->numerify('##'),
        'name' => 'Index Queryland',
        'dial_code' => '+986',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'IQ'.fake()->unique()->numerify('##'),
        'name' => 'Index Query Currency',
        'symbol' => 'I$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Index Query Co',
        'slug' => 'iq-'.fake()->unique()->numerify('####'),
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

    $managed = makeManagedDepartment($company);
    ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'department_id' => $managed['department']->id,
    ]);
    $leaveType = LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => 365,
    ]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $employee->id, 2026);

    return [
        'user' => $user,
        'company' => $company,
        'employee' => $employee,
        'leaveType' => $leaveType,
    ];
}

function seedLeaveRequestsWithApprovals(Company $company, Employee $employee, LeaveType $leaveType, int $count): void
{
    $existingCount = LeaveRequest::query()
        ->where('company_id', $company->id)
        ->where('employee_id', $employee->id)
        ->count();

    for ($index = 0; $index < $count; $index++) {
        $date = CarbonImmutable::parse('2026-01-01')
            ->addDays($existingCount + $index)
            ->toDateString();

        app(SubmitLeaveRequestWithApprovals::class)->handle(
            companyId: (int) $company->id,
            attributes: [
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveType->id,
                'start_date' => $date,
                'end_date' => $date,
                'total_days' => 1,
                'reason' => "Request {$existingCount}-{$index}",
            ],
            reserveBalance: false,
            notify: false,
        );
    }
}

function countApprovalRelatedQueries(): int
{
    return collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains(strtolower($query['query']), 'leave_request_approvals'))
        ->count();
}

test('leave request index keeps total and approval query counts bounded as page size grows', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'leaveType' => $leaveType] = makeIndexQueryCountFixtures();

    seedLeaveRequestsWithApprovals($company, $employee, $leaveType, 5);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.view_all',
    ]);

    $this->actingAs($user)->withSession(['current_company_id' => $company->id]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->get('/attendance/leave-requests?scope=all&per_page=50')
        ->assertOk();

    $smallTotalQueries = count(DB::getQueryLog());
    $smallPageApprovalQueries = countApprovalRelatedQueries();

    seedLeaveRequestsWithApprovals($company, $employee, $leaveType, 45);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->get('/attendance/leave-requests?scope=all&per_page=50')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('leave_requests', 50));

    $largeTotalQueries = count(DB::getQueryLog());
    $largePageApprovalQueries = countApprovalRelatedQueries();

    expect($smallPageApprovalQueries)->toBeGreaterThan(0)
        ->and($largePageApprovalQueries)->toBeLessThanOrEqual($smallPageApprovalQueries + 2)
        ->and($largeTotalQueries)->toBeLessThanOrEqual($smallTotalQueries + 5);
});
