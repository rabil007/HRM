<?php

use App\Enums\LeaveApprovalApproverType;
use App\Enums\LeaveRequestApprovalStatus;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\LeaveApprovalPolicy;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\Actions\AdministrativelyDeleteLeaveRequest;
use App\Support\Attendance\Actions\ApproveLeaveRequestStep;
use App\Support\Attendance\Actions\CancelLeaveRequestWorkflow;
use App\Support\Attendance\Actions\RejectLeaveRequestStep;
use App\Support\Attendance\Actions\SubmitLeaveRequestWithApprovals;
use App\Support\Attendance\LeaveBalanceManager;
use App\Support\Attendance\LeaveRequestAuthorization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * @return array{
 *     company: Company,
 *     employee: Employee,
 *     leaveType: LeaveType,
 *     managerUser: User,
 *     managerEmployee: Employee,
 *     admin: User
 * }
 */
function makeAdministrativeDeletionContext(int $daysPerYear = 40): array
{
    $country = Country::query()->create([
        'code' => 'AD'.fake()->unique()->numerify('##'),
        'name' => 'Admin Deletionland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'AD'.fake()->unique()->numerify('##'),
        'name' => 'Admin Deletion Currency',
        'symbol' => 'A$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Admin Deletion Co',
        'slug' => 'ad-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $managed = makeManagedDepartment($company);
    ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'department_id' => $managed['department']->id,
        'work_email' => 'employee-ad@example.com',
    ]);
    $leaveType = LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => $daysPerYear,
    ]);

    $admin = User::factory()->create(['status' => 'active']);
    DB::table('company_user')->insert([
        'company_id' => $company->id,
        'user_id' => $admin->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $employee->id, 2026);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $employee->id, 2027);

    return [
        'company' => $company,
        'employee' => $employee,
        'leaveType' => $leaveType,
        'managerUser' => $managed['managerUser'],
        'managerEmployee' => $managed['manager'],
        'admin' => $admin,
    ];
}

function grantAdministrativeDeletePermissions(User $user, Company $company): void
{
    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.view_all',
        'attendance.leave-requests.delete_any',
    ]);
}

function submitLeaveForAdminDeletion(array $context, string $start, string $end): LeaveRequest
{
    return app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => $start,
            'end_date' => $end,
            'reason' => 'Admin deletion fixture',
        ],
        notify: false,
    );
}

function balancePending(array $context, int $year = 2026): float
{
    return (float) LeaveBalance::query()
        ->where('company_id', $context['company']->id)
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', $year)
        ->value('pending_days');
}

function balanceUsed(array $context, int $year = 2026): float
{
    return (float) LeaveBalance::query()
        ->where('company_id', $context['company']->id)
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', $year)
        ->value('used_days');
}

test('user without delete_any receives 403 for administrative deletion', function () {
    $context = makeAdministrativeDeletionContext();
    $leaveRequest = submitLeaveForAdminDeletion($context, '2026-08-01', '2026-08-01');

    grantCompanyPermissions($context['admin'], $context['company'], [
        'attendance.leave-requests.view',
        'attendance.leave-requests.view_all',
        'attendance.leave-requests.delete',
    ]);

    $this->actingAs($context['admin'])
        ->delete(route('attendance.leave-requests.administrative-destroy', $leaveRequest), [
            'administrative_deletion_reason' => 'Should be forbidden',
        ])
        ->assertForbidden();

    expect(LeaveRequest::query()->whereKey($leaveRequest->id)->exists())->toBeTrue();
});

test('user with delete_any but without view_all receives 403', function () {
    $context = makeAdministrativeDeletionContext();
    $leaveRequest = submitLeaveForAdminDeletion($context, '2026-08-02', '2026-08-02');

    grantCompanyPermissions($context['admin'], $context['company'], [
        'attendance.leave-requests.view',
        'attendance.leave-requests.delete_any',
    ]);

    $this->actingAs($context['admin'])
        ->delete(route('attendance.leave-requests.administrative-destroy', $leaveRequest), [
            'administrative_deletion_reason' => 'Missing view_all',
        ])
        ->assertForbidden();
});

test('cross-company administrative deletion returns 404', function () {
    $context = makeAdministrativeDeletionContext();
    $leaveRequest = submitLeaveForAdminDeletion($context, '2026-08-03', '2026-08-03');
    grantAdministrativeDeletePermissions($context['admin'], $context['company']);

    $other = makeAdministrativeDeletionContext();
    grantAdministrativeDeletePermissions($other['admin'], $other['company']);

    $this->actingAs($other['admin'])
        ->delete(route('attendance.leave-requests.administrative-destroy', $leaveRequest), [
            'administrative_deletion_reason' => 'Cross company',
        ])
        ->assertNotFound();
});

test('administrative deletion requires a reason', function () {
    $context = makeAdministrativeDeletionContext();
    $leaveRequest = submitLeaveForAdminDeletion($context, '2026-08-04', '2026-08-04');
    grantAdministrativeDeletePermissions($context['admin'], $context['company']);

    $this->actingAs($context['admin'])
        ->from(route('attendance.leave-requests.index'))
        ->delete(route('attendance.leave-requests.administrative-destroy', $leaveRequest), [
            'administrative_deletion_reason' => '',
        ])
        ->assertSessionHasErrors('administrative_deletion_reason');

    expect(LeaveRequest::query()->whereKey($leaveRequest->id)->exists())->toBeTrue();
});

test('pending request releases pending balance exactly once and preserves cancelled open steps', function () {
    $context = makeAdministrativeDeletionContext();
    $leaveRequest = submitLeaveForAdminDeletion($context, '2026-08-05', '2026-08-06');
    grantAdministrativeDeletePermissions($context['admin'], $context['company']);

    expect(balancePending($context))->toBe(2.0);

    $this->actingAs($context['admin'])
        ->delete(route('attendance.leave-requests.administrative-destroy', $leaveRequest), [
            'administrative_deletion_reason' => 'Void pending allocation',
        ])
        ->assertRedirect(route('attendance.leave-requests.index'))
        ->assertSessionHas('success');

    expect(LeaveRequest::query()->whereKey($leaveRequest->id)->exists())->toBeFalse()
        ->and(LeaveRequest::withTrashed()->whereKey($leaveRequest->id)->exists())->toBeTrue()
        ->and(balancePending($context))->toBe(0.0);

    $approvals = LeaveRequestApproval::query()->where('leave_request_id', $leaveRequest->id)->get();
    expect($approvals)->not->toBeEmpty()
        ->and($approvals->every(fn ($step) => $step->status === LeaveRequestApprovalStatus::Cancelled))->toBeTrue();
});

test('approved request subtracts used balance and restores remaining', function () {
    $context = makeAdministrativeDeletionContext();
    $leaveRequest = submitLeaveForAdminDeletion($context, '2026-08-07', '2026-08-08');
    grantAdministrativeDeletePermissions($context['admin'], $context['company']);

    app(ApproveLeaveRequestStep::class)->handle(
        $leaveRequest->fresh(),
        $context['managerUser'],
        (int) $context['company']->id,
    );

    expect(balancePending($context))->toBe(0.0)
        ->and(balanceUsed($context))->toBe(2.0);

    $this->actingAs($context['admin'])
        ->delete(route('attendance.leave-requests.administrative-destroy', $leaveRequest), [
            'administrative_deletion_reason' => 'Void approved leave',
        ])
        ->assertRedirect(route('attendance.leave-requests.index'));

    expect(balanceUsed($context))->toBe(0.0)
        ->and(balancePending($context))->toBe(0.0)
        ->and(LeaveRequestApproval::query()->where('leave_request_id', $leaveRequest->id)->count())->toBeGreaterThan(0);
});

test('rejected and cancelled requests do not change balance', function () {
    $context = makeAdministrativeDeletionContext();
    grantAdministrativeDeletePermissions($context['admin'], $context['company']);

    $rejected = submitLeaveForAdminDeletion($context, '2026-08-10', '2026-08-10');
    app(RejectLeaveRequestStep::class)->handle(
        $rejected->fresh(),
        $context['managerUser'],
        (int) $context['company']->id,
        'Not allowed',
    );
    expect(balancePending($context))->toBe(0.0)->and(balanceUsed($context))->toBe(0.0);

    $this->actingAs($context['admin'])
        ->delete(route('attendance.leave-requests.administrative-destroy', $rejected), [
            'administrative_deletion_reason' => 'Clean rejected',
        ])
        ->assertRedirect();

    expect(balancePending($context))->toBe(0.0)->and(balanceUsed($context))->toBe(0.0);

    $cancelled = submitLeaveForAdminDeletion($context, '2026-08-11', '2026-08-11');
    app(CancelLeaveRequestWorkflow::class)->handle(
        $cancelled->fresh(),
        $context['admin'],
        (int) $context['company']->id,
        'Employee changed plans',
    );
    expect(balancePending($context))->toBe(0.0);

    $this->actingAs($context['admin'])
        ->delete(route('attendance.leave-requests.administrative-destroy', $cancelled), [
            'administrative_deletion_reason' => 'Clean cancelled',
        ])
        ->assertRedirect();

    expect(balancePending($context))->toBe(0.0)->and(balanceUsed($context))->toBe(0.0);
});

test('partially approved pending request releases pending and preserves approved steps', function () {
    $context = makeAdministrativeDeletionContext();
    $hr = makeActionableApprover($context['company']);
    configureCompanyLeaveApprovalSettings($context['company'], $hr['employee']);

    LeaveApprovalPolicy::query()->where('company_id', $context['company']->id)->delete();
    ensureDefaultLeaveApprovalPolicy($context['company'], [
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
        ['type' => LeaveApprovalApproverType::HrApprover, 'required' => true],
    ]);

    $leaveRequest = submitLeaveForAdminDeletion($context, '2026-08-12', '2026-08-13');
    grantAdministrativeDeletePermissions($context['admin'], $context['company']);

    app(ApproveLeaveRequestStep::class)->handle(
        $leaveRequest->fresh(),
        $context['managerUser'],
        (int) $context['company']->id,
    );

    expect($leaveRequest->fresh()->status)->toBe('pending')
        ->and(balancePending($context))->toBe(2.0);

    $this->actingAs($context['admin'])
        ->delete(route('attendance.leave-requests.administrative-destroy', $leaveRequest), [
            'administrative_deletion_reason' => 'Void mid-approval',
        ])
        ->assertRedirect();

    $steps = LeaveRequestApproval::query()
        ->where('leave_request_id', $leaveRequest->id)
        ->orderBy('sequence')
        ->get();

    expect(balancePending($context))->toBe(0.0)
        ->and($steps)->toHaveCount(2)
        ->and($steps[0]->status)->toBe(LeaveRequestApprovalStatus::Approved)
        ->and($steps[0]->acted_at)->not->toBeNull()
        ->and($steps[1]->status)->toBe(LeaveRequestApprovalStatus::Cancelled);
});

test('administrative deletion soft deletes, preserves attachments on disk, and writes audit', function () {
    Storage::fake('local');
    $context = makeAdministrativeDeletionContext();
    grantAdministrativeDeletePermissions($context['admin'], $context['company']);

    $file = UploadedFile::fake()->create('note.pdf', 120, 'application/pdf');
    $leaveRequest = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-08-14',
            'end_date' => '2026-08-14',
            'reason' => 'With attachment',
        ],
        attachment: $file,
        notify: false,
    );

    $path = $leaveRequest->attachments[0]['path'] ?? null;
    expect($path)->not->toBeNull()
        ->and(Storage::disk('local')->exists($path))->toBeTrue();

    $this->actingAs($context['admin'])
        ->delete(route('attendance.leave-requests.administrative-destroy', $leaveRequest), [
            'administrative_deletion_reason' => 'Void with attachment',
        ])
        ->assertRedirect();

    $trashed = LeaveRequest::withTrashed()->whereKey($leaveRequest->id)->first();
    expect($trashed)->not->toBeNull()
        ->and($trashed->trashed())->toBeTrue()
        ->and($trashed->administrative_deletion_reason)->toBe('Void with attachment')
        ->and((int) $trashed->administratively_deleted_by)->toBe((int) $context['admin']->id)
        ->and($trashed->status_before_administrative_deletion)->toBe('pending')
        ->and($trashed->attachments)->not->toBeNull()
        ->and(Storage::disk('local')->exists($path))->toBeTrue()
        ->and(LeaveRequest::query()->whereKey($leaveRequest->id)->exists())->toBeFalse();

    $activity = Activity::query()
        ->where('subject_type', LeaveRequest::class)
        ->where('subject_id', $leaveRequest->id)
        ->where('description', 'Leave request administratively voided and removed')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and((int) $activity->causer_id)->toBe((int) $context['admin']->id)
        ->and((int) $activity->company_id)->toBe((int) $context['company']->id)
        ->and($activity->properties['deletion_reason'])->toBe('Void with attachment')
        ->and($activity->properties['previous_status'])->toBe('pending')
        ->and($activity->properties['balances_before'])->not->toBeEmpty()
        ->and($activity->properties['balances_after'])->not->toBeEmpty();
});

test('concurrent administrative deletion cannot reverse balance twice', function () {
    $context = makeAdministrativeDeletionContext();
    $leaveRequest = submitLeaveForAdminDeletion($context, '2026-08-15', '2026-08-16');
    grantAdministrativeDeletePermissions($context['admin'], $context['company']);

    expect(balancePending($context))->toBe(2.0);

    app(AdministrativelyDeleteLeaveRequest::class)->handle(
        $leaveRequest,
        (int) $context['company']->id,
        $context['admin'],
        'First void',
    );

    expect(balancePending($context))->toBe(0.0);

    expect(fn () => app(AdministrativelyDeleteLeaveRequest::class)->handle(
        $leaveRequest,
        (int) $context['company']->id,
        $context['admin'],
        'Second void',
    ))->toThrow(ValidationException::class);

    expect(balancePending($context))->toBe(0.0);
});

test('cross-year approved request reverses each year correctly', function () {
    $context = makeAdministrativeDeletionContext();
    $leaveRequest = submitLeaveForAdminDeletion($context, '2026-12-30', '2027-01-02');
    grantAdministrativeDeletePermissions($context['admin'], $context['company']);

    app(ApproveLeaveRequestStep::class)->handle(
        $leaveRequest->fresh(),
        $context['managerUser'],
        (int) $context['company']->id,
    );

    $used2026 = balanceUsed($context, 2026);
    $used2027 = balanceUsed($context, 2027);
    expect($used2026)->toBeGreaterThan(0.0)
        ->and($used2027)->toBeGreaterThan(0.0);

    $this->actingAs($context['admin'])
        ->delete(route('attendance.leave-requests.administrative-destroy', $leaveRequest), [
            'administrative_deletion_reason' => 'Void cross-year',
        ])
        ->assertRedirect();

    expect(balanceUsed($context, 2026))->toBe(0.0)
        ->and(balanceUsed($context, 2027))->toBe(0.0);
});

test('corrupt insufficient used balance aborts without deleting the request', function () {
    $context = makeAdministrativeDeletionContext();
    $leaveRequest = submitLeaveForAdminDeletion($context, '2026-08-17', '2026-08-18');
    grantAdministrativeDeletePermissions($context['admin'], $context['company']);

    app(ApproveLeaveRequestStep::class)->handle(
        $leaveRequest->fresh(),
        $context['managerUser'],
        (int) $context['company']->id,
    );

    LeaveBalance::query()
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2026)
        ->update(['used_days' => 0]);

    expect(fn () => app(AdministrativelyDeleteLeaveRequest::class)->handle(
        $leaveRequest->fresh(),
        (int) $context['company']->id,
        $context['admin'],
        'Should fail',
    ))->toThrow(RuntimeException::class);

    expect(LeaveRequest::query()->whereKey($leaveRequest->id)->exists())->toBeTrue()
        ->and(LeaveRequestApproval::query()->where('leave_request_id', $leaveRequest->id)->count())->toBeGreaterThan(0);
});

test('frontend capability is false without the complete permission combination', function () {
    $context = makeAdministrativeDeletionContext();
    $leaveRequest = submitLeaveForAdminDeletion($context, '2026-08-19', '2026-08-19');
    $authorization = app(LeaveRequestAuthorization::class);

    grantCompanyPermissions($context['admin'], $context['company'], [
        'attendance.leave-requests.view',
        'attendance.leave-requests.view_all',
        'attendance.leave-requests.delete',
    ]);

    expect($authorization->canAdministrativelyDelete(
        $leaveRequest,
        $context['admin'],
        (int) $context['company']->id,
    ))->toBeFalse();

    grantAdministrativeDeletePermissions($context['admin'], $context['company']);

    expect($authorization->canAdministrativelyDelete(
        $leaveRequest,
        $context['admin'],
        (int) $context['company']->id,
    ))->toBeTrue()
        ->and($authorization->capabilities(
            $leaveRequest,
            $context['admin'],
            (int) $context['company']->id,
        )['can_administratively_delete'])->toBeTrue();

    $this->actingAs($context['admin'])
        ->get(route('attendance.leave-requests.show', $leaveRequest))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('attendance/leave-request')
            ->where('leave_request.can_administratively_delete', true));
});
