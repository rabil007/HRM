<?php

use App\Enums\LeaveApprovalApproverType;
use App\Enums\LeaveRequestApprovalStatus;
use App\Mail\LeaveRequestSubmittedMail;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\LeaveBalanceManager;
use Database\Seeders\EmailTemplatesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * @return array{user: User, company: Company, employee: Employee, leaveType: LeaveType}
 */
function makeUpdatedEmailFixtures(): array
{
    $user = User::factory()->create(['status' => 'active']);
    $country = Country::query()->create([
        'code' => 'UE'.fake()->unique()->numerify('##'),
        'name' => 'Updated Emailland',
        'dial_code' => '+982',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'UE'.fake()->unique()->numerify('##'),
        'name' => 'Updated Email Currency',
        'symbol' => 'U$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Updated Email Co',
        'slug' => 'ue-'.fake()->unique()->numerify('####'),
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
        'user_id' => $user->id,
        'work_email' => 'employee-updated@example.com',
    ]);
    $leaveType = LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => 30,
    ]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $employee->id, 2026);

    return [
        'user' => $user,
        'company' => $company,
        'employee' => $employee,
        'leaveType' => $leaveType,
        'managerUser' => $managed['managerUser'],
    ];
}

test('same pending approver receives update notification after edit', function () {
    EmailTemplatesSeeder::seedLeaveRequestUpdatedTemplate();

    ['user' => $user, 'company' => $company, 'employee' => $employee, 'leaveType' => $leaveType, 'managerUser' => $managerUser] = makeUpdatedEmailFixtures();

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.create',
        'attendance.leave-requests.update',
    ]);

    $this->actingAs($user);

    Mail::fake();

    $this->post('/attendance/leave-requests', [
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-02',
        'reason' => 'Original',
    ])->assertRedirect();

    $leaveRequest = LeaveRequest::query()->where('employee_id', $employee->id)->firstOrFail();

    expect($leaveRequest->approvals->firstWhere('status', LeaveRequestApprovalStatus::Pending)?->approver_user_id)
        ->toBe($managerUser->id);

    Mail::fake();

    $this->put("/attendance/leave-requests/{$leaveRequest->id}", [
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'reason' => 'Updated dates',
    ])->assertRedirect();

    Mail::assertQueued(LeaveRequestSubmittedMail::class, 1);
});

test('failed update transaction sends no notification', function () {
    EmailTemplatesSeeder::seedLeaveRequestSubmittedTemplate();

    ['user' => $user, 'company' => $company, 'employee' => $employee, 'leaveType' => $leaveType] = makeUpdatedEmailFixtures();

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.create',
        'attendance.leave-requests.update',
    ]);

    $this->actingAs($user);

    $this->post('/attendance/leave-requests', [
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-02',
        'reason' => 'Original',
    ])->assertRedirect();

    $leaveRequest = LeaveRequest::query()->where('employee_id', $employee->id)->firstOrFail();

    LeaveRequestApproval::query()
        ->where('leave_request_id', $leaveRequest->id)
        ->where('sequence', 1)
        ->update([
            'status' => LeaveRequestApprovalStatus::Approved->value,
            'acted_at' => now(),
        ]);

    Mail::fake();

    $this->from('/attendance/leave-requests')
        ->put("/attendance/leave-requests/{$leaveRequest->id}", [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-12',
            'reason' => 'Should fail',
        ])
        ->assertRedirect('/attendance/leave-requests')
        ->assertSessionHasErrors('leave_request');

    Mail::assertNothingQueued();
});

test('update notification falls back to submitted template when updated template is disabled', function () {
    EmailTemplatesSeeder::seedLeaveRequestSubmittedTemplate();
    EmailTemplatesSeeder::seedLeaveRequestUpdatedTemplate()->update(['enabled' => false]);

    ['user' => $user, 'company' => $company, 'employee' => $employee, 'leaveType' => $leaveType] = makeUpdatedEmailFixtures();

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.create',
        'attendance.leave-requests.update',
    ]);

    $this->actingAs($user);

    Mail::fake();

    $this->post('/attendance/leave-requests', [
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-02',
        'reason' => 'Original',
    ])->assertRedirect();

    $leaveRequest = LeaveRequest::query()->where('employee_id', $employee->id)->firstOrFail();

    Mail::fake();

    $this->put("/attendance/leave-requests/{$leaveRequest->id}", [
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'reason' => 'Updated dates',
    ])->assertRedirect();

    Mail::assertQueued(LeaveRequestSubmittedMail::class, 1);
});
