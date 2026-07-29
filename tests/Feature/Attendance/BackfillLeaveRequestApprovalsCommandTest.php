<?php

use App\Enums\LeaveApprovalApproverType;
use App\Enums\LeaveRequestApprovalStatus;
use App\Mail\LeaveRequestSubmittedMail;
use App\Models\Company;
use App\Models\CompanyLeaveApprovalSetting;
use App\Models\Country;
use App\Models\Currency;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\LeaveType;
use App\Support\Attendance\LeaveBalanceManager;
use Illuminate\Support\Facades\Mail;

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

    $leaveRequest = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'total_days' => 3,
        'status' => 'pending',
    ]);

    Mail::fake();

    $this->artisan('leave-approvals:backfill', [
        '--company' => $company->id,
        '--request' => $leaveRequest->id,
    ])->assertSuccessful();

    expect($leaveRequest->fresh()->approvals)->toHaveCount(1)
        ->and($leaveRequest->fresh()->approvals->first()->approver_user_id)->toBe($managed['managerUser']->id)
        ->and($leaveRequest->fresh()->approvals->first()->status)->toBe(LeaveRequestApprovalStatus::Pending);

    Mail::assertNothingQueued();
});

test('backfill dry-run performs no database writes', function () {
    ['company' => $company] = makeBackfillCompany();
    $managed = makeManagedDepartment($company);
    ensureDefaultLeaveApprovalPolicy($company);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'department_id' => $managed['department']->id,
    ]);
    $leaveType = LeaveType::factory()->for($company)->create(['status' => 'active', 'days_per_year' => 30]);

    $leaveRequest = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'total_days' => 3,
        'status' => 'pending',
    ]);

    $before = [
        'settings' => CompanyLeaveApprovalSetting::query()->count(),
        'approvals' => LeaveRequestApproval::query()->count(),
        'requests' => LeaveRequest::query()->count(),
        'balances' => LeaveBalance::query()->count(),
    ];

    Mail::fake();

    $this->artisan('leave-approvals:backfill', [
        '--company' => $company->id,
        '--request' => $leaveRequest->id,
        '--dry-run' => true,
        '--notify' => true,
    ])->assertSuccessful();

    expect(CompanyLeaveApprovalSetting::query()->count())->toBe($before['settings'])
        ->and(LeaveRequestApproval::query()->count())->toBe($before['approvals'])
        ->and(LeaveRequest::query()->count())->toBe($before['requests'])
        ->and(LeaveBalance::query()->count())->toBe($before['balances'])
        ->and($leaveRequest->fresh()->approvals)->toHaveCount(0);

    Mail::assertNothingQueued();
});

test('backfill never deletes existing approvals even with force', function () {
    ['company' => $company] = makeBackfillCompany();
    $managed = makeManagedDepartment($company);
    ensureDefaultLeaveApprovalPolicy($company);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'department_id' => $managed['department']->id,
    ]);
    $leaveType = LeaveType::factory()->for($company)->create(['status' => 'active', 'days_per_year' => 30]);

    $leaveRequest = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'total_days' => 3,
        'status' => 'pending',
    ]);

    $existing = LeaveRequestApproval::factory()->create([
        'company_id' => $company->id,
        'leave_request_id' => $leaveRequest->id,
        'sequence' => 1,
        'approver_type' => LeaveApprovalApproverType::SpecificEmployee,
        'approver_employee_id' => $managed['manager']->id,
        'approver_user_id' => $managed['managerUser']->id,
        'status' => LeaveRequestApprovalStatus::Approved,
        'acted_at' => now(),
        'comments' => 'Historical approval',
    ]);

    $this->artisan('leave-approvals:backfill', [
        '--company' => $company->id,
        '--request' => $leaveRequest->id,
        '--force' => true,
    ])->assertSuccessful();

    $existing->refresh();

    expect($leaveRequest->fresh()->approvals)->toHaveCount(1)
        ->and($existing->approver_type)->toBe(LeaveApprovalApproverType::SpecificEmployee)
        ->and($existing->status)->toBe(LeaveRequestApprovalStatus::Approved)
        ->and($existing->comments)->toBe('Historical approval')
        ->and(LeaveRequestApproval::query()->whereKey($existing->id)->exists())->toBeTrue();
});

test('failed chain resolution leaves the database unchanged', function () {
    ['company' => $company] = makeBackfillCompany();

    // No default policy / department manager — resolution must fail.
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $leaveType = LeaveType::factory()->for($company)->create(['status' => 'active', 'days_per_year' => 30]);

    $leaveRequest = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'total_days' => 3,
        'status' => 'pending',
    ]);

    $beforeApprovals = LeaveRequestApproval::query()->count();
    $beforeSettings = CompanyLeaveApprovalSetting::query()->count();

    $this->artisan('leave-approvals:backfill', [
        '--company' => $company->id,
        '--request' => $leaveRequest->id,
    ])->assertFailed();

    expect(LeaveRequestApproval::query()->count())->toBe($beforeApprovals)
        ->and(CompanyLeaveApprovalSetting::query()->count())->toBe($beforeSettings)
        ->and($leaveRequest->fresh()->approvals)->toHaveCount(0);
});

test('backfill is idempotent and notify is explicit', function () {
    ['company' => $company] = makeBackfillCompany();
    $managed = makeManagedDepartment($company);
    ensureDefaultLeaveApprovalPolicy($company);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'department_id' => $managed['department']->id,
    ]);
    $leaveType = LeaveType::factory()->for($company)->create(['status' => 'active', 'days_per_year' => 30]);

    $leaveRequest = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'total_days' => 3,
        'status' => 'pending',
    ]);

    EmailTemplate::query()->updateOrCreate(
        ['slug' => 'leave_request_submitted'],
        [
            'name' => 'Leave request submitted',
            'subject' => 'Leave request',
            'body_html' => 'Hello',
            'enabled' => true,
            'to_preset' => null,
            'cc_preset' => null,
            'include_company_footer' => false,
        ],
    );

    Mail::fake();

    $this->artisan('leave-approvals:backfill', [
        '--company' => $company->id,
        '--request' => $leaveRequest->id,
        '--notify' => true,
    ])->assertSuccessful();

    Mail::assertQueued(LeaveRequestSubmittedMail::class, 1);

    Mail::fake();

    $this->artisan('leave-approvals:backfill', [
        '--company' => $company->id,
        '--request' => $leaveRequest->id,
        '--notify' => true,
    ])->assertSuccessful();

    expect($leaveRequest->fresh()->approvals)->toHaveCount(1);
    Mail::assertNothingQueued();
});
