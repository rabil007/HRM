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
use Illuminate\Support\Facades\DB;

/**
 * @return array{user: User, company: Company, otherCompany: Company, employee: Employee, leaveType: LeaveType}
 */
function makeMassAssignmentFixtures(): array
{
    $user = User::factory()->create(['status' => 'active']);
    $country = Country::query()->create([
        'code' => 'MA'.fake()->unique()->numerify('##'),
        'name' => 'Mass Assignmentland',
        'dial_code' => '+985',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'MA'.fake()->unique()->numerify('##'),
        'name' => 'Mass Assignment Currency',
        'symbol' => 'M$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Mass Assignment Co',
        'slug' => 'ma-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
    $otherCompany = Company::query()->create([
        'name' => 'Other Mass Co',
        'slug' => 'oma-'.fake()->unique()->numerify('####'),
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
    ]);
    $leaveType = LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => 30,
    ]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $employee->id, 2026);

    return [
        'user' => $user,
        'company' => $company,
        'otherCompany' => $otherCompany,
        'employee' => $employee,
        'leaveType' => $leaveType,
    ];
}

test('LeaveRequest fillable does not accept company_id', function () {
    expect((new LeaveRequest)->getFillable())->not->toContain('company_id');
});

test('fill cannot set company_id on LeaveRequest', function () {
    ['company' => $company, 'employee' => $employee, 'leaveType' => $leaveType] = makeMassAssignmentFixtures();

    $leaveRequest = new LeaveRequest;
    $leaveRequest->fill([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'total_days' => 3,
        'reason' => 'Attempted mass assignment',
    ]);

    expect($leaveRequest->company_id)->toBeNull();
});

test('HTTP create ignores client-supplied company_id and scopes to active company', function () {
    ['user' => $user, 'company' => $company, 'otherCompany' => $otherCompany, 'employee' => $employee, 'leaveType' => $leaveType] = makeMassAssignmentFixtures();

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.create',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post('/attendance/leave-requests', [
            'company_id' => $otherCompany->id,
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-12',
            'reason' => 'Scoped create',
        ])
        ->assertRedirect(route('attendance.leave-requests.index'));

    $leaveRequest = LeaveRequest::query()->where('employee_id', $employee->id)->firstOrFail();

    expect((int) $leaveRequest->company_id)->toBe((int) $company->id)
        ->and((int) $leaveRequest->company_id)->not->toBe((int) $otherCompany->id);
});

test('SubmitLeaveRequestWithApprovals forceFills trusted company_id', function () {
    ['company' => $company, 'otherCompany' => $otherCompany, 'employee' => $employee, 'leaveType' => $leaveType] = makeMassAssignmentFixtures();

    $leaveRequest = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $company->id,
        attributes: [
            'company_id' => $otherCompany->id,
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-12',
            'total_days' => 3,
            'reason' => 'Trusted company id',
        ],
        reserveBalance: false,
        notify: false,
    );

    expect((int) $leaveRequest->company_id)->toBe((int) $company->id)
        ->and((int) $leaveRequest->company_id)->not->toBe((int) $otherCompany->id);
});
