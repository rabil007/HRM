<?php

use App\Enums\LeaveApprovalApproverType;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\LeaveBalanceManager;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/**
 * @return array{user: User, company: Company, employee: Employee, leaveType: LeaveType}
 */
function makeChainRebuildAuditFixtures(): array
{
    $user = User::factory()->create(['status' => 'active']);
    $country = Country::query()->create([
        'code' => 'CB'.fake()->unique()->numerify('##'),
        'name' => 'Chain Rebuildland',
        'dial_code' => '+984',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'CB'.fake()->unique()->numerify('##'),
        'name' => 'Chain Rebuild Currency',
        'symbol' => 'C$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Chain Rebuild Co',
        'slug' => 'cb-'.fake()->unique()->numerify('####'),
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
        'employee' => $employee,
        'leaveType' => $leaveType,
    ];
}

test('editing a pending request rebuilds the chain and logs leave_approval_chain_rebuilt activity', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'leaveType' => $leaveType] = makeChainRebuildAuditFixtures();

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.create',
        'attendance.leave-requests.update',
        'audit.view',
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
    $previousApprovalCount = $leaveRequest->approvals->count();

    $newManaged = makeManagedDepartment($company);
    $employee->update(['department_id' => $newManaged['department']->id]);

    $this->put("/attendance/leave-requests/{$leaveRequest->id}", [
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-03',
        'reason' => 'Rebuilt',
    ])->assertRedirect();

    $activity = Activity::query()
        ->where('company_id', $company->id)
        ->where('subject_type', LeaveRequest::class)
        ->where('subject_id', $leaveRequest->id)
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties->get('event'))->toBe('leave_approval_chain_rebuilt')
        ->and((int) $activity->properties->get('company_id'))->toBe((int) $company->id)
        ->and($activity->properties->get('previous_approvals'))->toBeArray()
        ->and($activity->properties->get('new_approvals'))->toBeArray()
        ->and(count($activity->properties->get('previous_approvals')))->toBe($previousApprovalCount)
        ->and(count($activity->properties->get('new_approvals')))->toBeGreaterThan(0);
});

test('user without audit.view does not receive recent_activity on leave request show', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'leaveType' => $leaveType] = makeChainRebuildAuditFixtures();

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.create',
        'attendance.leave-requests.update',
        'attendance.leave-requests.view',
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

    $this->put("/attendance/leave-requests/{$leaveRequest->id}", [
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'reason' => 'Updated',
    ])->assertRedirect();

    $this->get(route('attendance.leave-requests.show', $leaveRequest))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can_view_audit', false)
            ->where('recent_activity', []));
});

test('cross-company user cannot see leave request activity', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'leaveType' => $leaveType] = makeChainRebuildAuditFixtures();

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.create',
        'attendance.leave-requests.update',
        'attendance.leave-requests.view',
        'audit.view',
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

    $this->put("/attendance/leave-requests/{$leaveRequest->id}", [
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'reason' => 'Updated',
    ])->assertRedirect();

    expect(Activity::query()
        ->where('company_id', $company->id)
        ->where('subject_id', $leaveRequest->id)
        ->where('properties->event', 'leave_approval_chain_rebuilt')
        ->exists())->toBeTrue();

    $otherUser = User::factory()->create(['status' => 'active']);
    $otherCountry = Country::query()->create([
        'code' => 'OC'.fake()->unique()->numerify('##'),
        'name' => 'Other Chainland',
        'dial_code' => '+983',
        'is_active' => true,
    ]);
    $otherCurrency = Currency::query()->create([
        'code' => 'OC'.fake()->unique()->numerify('##'),
        'name' => 'Other Chain Currency',
        'symbol' => 'O$',
        'is_active' => true,
    ]);
    $otherCompany = Company::query()->create([
        'name' => 'Other Chain Co',
        'slug' => 'oc-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $otherCountry->id,
        'currency_id' => $otherCurrency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    DB::table('company_user')->insert([
        'company_id' => $otherCompany->id,
        'user_id' => $otherUser->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    grantCompanyPermissions($otherUser, $otherCompany, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.view_all',
        'audit.view',
    ]);

    $this->actingAs($otherUser)
        ->withSession(['current_company_id' => $otherCompany->id])
        ->get(route('attendance.leave-requests.show', $leaveRequest))
        ->assertNotFound();

    expect(Activity::query()
        ->where('company_id', $otherCompany->id)
        ->where('subject_id', $leaveRequest->id)
        ->exists())->toBeFalse();
});
