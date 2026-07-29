<?php

use App\Enums\LeaveApprovalApproverType;
use App\Enums\LeaveRequestApprovalStatus;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\LeaveType;
use App\Support\Attendance\LeaveBalanceManager;

/**
 * @return array{company: Company}
 */
function makeBackfillCompany(): array
{
    $country = Country::query()->create([
        'code' => 'BF'.fake()->unique()->numerify('##'),
        'name' => 'Backfillland',
        'dial_code' => '+995',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'BF'.fake()->unique()->numerify('##'),
        'name' => 'Backfill Currency',
        'symbol' => 'B$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Backfill Co',
        'slug' => 'bf-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    return ['company' => $company];
}

test('backfill command creates approval snapshots for pending requests', function () {
    ['company' => $company] = makeBackfillCompany();
    $managed = makeManagedDepartment($company);
    ensureDefaultLeaveApprovalPolicy($company);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'department_id' => $managed['department']->id,
    ]);
    $leaveType = LeaveType::factory()->for($company)->create(['status' => 'active', 'days_per_year' => 30]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $employee->id, 2026);

    $leaveRequest = LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'total_days' => 3,
        'status' => 'pending',
    ]);

    $this->artisan('leave-approvals:backfill', [
        '--company' => $company->id,
        '--request' => $leaveRequest->id,
    ])->assertSuccessful();

    expect($leaveRequest->fresh()->approvals)->toHaveCount(1)
        ->and($leaveRequest->fresh()->approvals->first()->approver_user_id)->toBe($managed['managerUser']->id)
        ->and($leaveRequest->fresh()->approvals->first()->status)->toBe(LeaveRequestApprovalStatus::Pending);
});

test('backfill dry-run does not persist approvals', function () {
    ['company' => $company] = makeBackfillCompany();
    $managed = makeManagedDepartment($company);
    ensureDefaultLeaveApprovalPolicy($company);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'department_id' => $managed['department']->id,
    ]);
    $leaveType = LeaveType::factory()->for($company)->create(['status' => 'active', 'days_per_year' => 30]);

    $leaveRequest = LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'total_days' => 3,
        'status' => 'pending',
    ]);

    $this->artisan('leave-approvals:backfill', [
        '--company' => $company->id,
        '--request' => $leaveRequest->id,
        '--dry-run' => true,
    ])->assertSuccessful();

    expect($leaveRequest->fresh()->approvals)->toHaveCount(0);
});

test('backfill skips requests that already have approvals unless forced', function () {
    ['company' => $company] = makeBackfillCompany();
    $managed = makeManagedDepartment($company);
    ensureDefaultLeaveApprovalPolicy($company);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'department_id' => $managed['department']->id,
    ]);
    $leaveType = LeaveType::factory()->for($company)->create(['status' => 'active', 'days_per_year' => 30]);

    $leaveRequest = LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'total_days' => 3,
        'status' => 'pending',
    ]);

    LeaveRequestApproval::factory()->create([
        'company_id' => $company->id,
        'leave_request_id' => $leaveRequest->id,
        'sequence' => 1,
        'approver_type' => LeaveApprovalApproverType::SpecificEmployee,
        'approver_employee_id' => $managed['manager']->id,
        'approver_user_id' => $managed['managerUser']->id,
        'status' => LeaveRequestApprovalStatus::Pending,
    ]);

    $this->artisan('leave-approvals:backfill', [
        '--company' => $company->id,
        '--request' => $leaveRequest->id,
    ])->assertSuccessful();

    expect($leaveRequest->fresh()->approvals)->toHaveCount(1)
        ->and($leaveRequest->fresh()->approvals->first()->approver_type)->toBe(LeaveApprovalApproverType::SpecificEmployee);

    $this->artisan('leave-approvals:backfill', [
        '--company' => $company->id,
        '--request' => $leaveRequest->id,
        '--force' => true,
    ])->assertSuccessful();

    expect($leaveRequest->fresh()->approvals)->toHaveCount(1)
        ->and($leaveRequest->fresh()->approvals->first()->approver_type)->toBe(LeaveApprovalApproverType::DepartmentManager);
});
