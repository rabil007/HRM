<?php

use App\Enums\LeaveApprovalApproverType;
use App\Enums\LeaveRequestApprovalStatus;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\Actions\ApproveLeaveRequestStep;
use App\Support\Attendance\Actions\SubmitLeaveRequestWithApprovals;
use App\Support\Attendance\Actions\UpdateLeaveRequestWithApprovals;
use App\Support\Attendance\LeaveApproverEligibility;
use App\Support\Attendance\LeaveBalanceManager;
use App\Support\Attendance\LeaveRequestAttachments;
use App\Support\Attendance\PresentLeaveApproverOption;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * @return array{
 *     company: Company,
 *     employee: Employee,
 *     leaveType: LeaveType,
 *     managerUser: User,
 *     managerEmployee: Employee
 * }
 */
function makeConsolidationContext(): array
{
    $country = Country::query()->create([
        'code' => 'CP'.fake()->unique()->numerify('##'),
        'name' => 'Consolidationland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'CP'.fake()->unique()->numerify('##'),
        'name' => 'Consolidation Currency',
        'symbol' => 'C$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Consolidation Co',
        'slug' => 'cp-'.fake()->unique()->numerify('####'),
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
    ]);
    $leaveType = LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => 5,
    ]);

    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $employee->id, 2026);

    return [
        'company' => $company,
        'employee' => $employee,
        'leaveType' => $leaveType,
        'managerUser' => $managed['managerUser'],
        'managerEmployee' => $managed['manager'],
    ];
}

test('domain submission rejects insufficient balance even after a stale external precheck', function () {
    $context = makeConsolidationContext();
    $manager = app(LeaveBalanceManager::class);

    $manager->assertCanReserve(
        (int) $context['company']->id,
        (int) $context['employee']->id,
        (int) $context['leaveType']->id,
        '2026-06-01',
        '2026-06-05',
    );

    LeaveBalance::query()
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2026)
        ->update(['pending_days' => 5]);

    expect(fn () => app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-05',
            'total_days' => 5,
            'reason' => 'Stale precheck',
        ],
        notify: false,
    ))->toThrow(RuntimeException::class);

    expect(LeaveRequest::query()->where('employee_id', $context['employee']->id)->count())->toBe(0)
        ->and(LeaveRequestApproval::query()->where('company_id', $context['company']->id)->count())->toBe(0);
});

test('only one of two overlapping domain submissions commits', function () {
    $context = makeConsolidationContext();
    LeaveType::query()->whereKey($context['leaveType']->id)->update(['days_per_year' => 30]);
    app(LeaveBalanceManager::class)->synchronizeBalanceKey(
        (int) $context['company']->id,
        (int) $context['employee']->id,
        (int) $context['leaveType']->id,
        2026,
    );
    LeaveBalance::query()
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2026)
        ->update(['entitled_days' => 30, 'pending_days' => 0, 'used_days' => 0]);

    app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-03',
            'total_days' => 3,
            'reason' => 'First',
        ],
        notify: false,
    );

    expect(fn () => app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-07-02',
            'end_date' => '2026-07-04',
            'total_days' => 3,
            'reason' => 'Overlap',
        ],
        notify: false,
    ))->toThrow(ValidationException::class);

    expect(LeaveRequest::query()->where('employee_id', $context['employee']->id)->count())->toBe(1);
});

test('attachment storage failure rolls back request balance and approvals', function () {
    Mail::fake();

    $context = makeConsolidationContext();
    LeaveType::query()->whereKey($context['leaveType']->id)->update(['days_per_year' => 30]);
    LeaveBalance::query()
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2026)
        ->update(['entitled_days' => 30, 'pending_days' => 0, 'used_days' => 0]);

    $attachments = Mockery::mock(LeaveRequestAttachments::class);
    $attachments->shouldReceive('store')->once()->andThrow(new RuntimeException('disk full'));
    $attachments->shouldReceive('deleteFromStorage')->zeroOrMoreTimes();
    app()->instance(LeaveRequestAttachments::class, $attachments);

    expect(fn () => app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-02',
            'total_days' => 2,
            'reason' => 'Attachment fail',
        ],
        notify: true,
        attachment: UploadedFile::fake()->create('note.pdf', 10, 'application/pdf'),
    ))->toThrow(RuntimeException::class);

    expect(LeaveRequest::query()->where('employee_id', $context['employee']->id)->count())->toBe(0)
        ->and(LeaveRequestApproval::query()->where('company_id', $context['company']->id)->count())->toBe(0);

    $balance = LeaveBalance::query()
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2026)
        ->firstOrFail();

    expect((float) $balance->pending_days)->toBe(0.0);
    Mail::assertNothingQueued();
});

test('no-op edit changes nothing and does not rebuild chain', function () {
    $context = makeConsolidationContext();
    LeaveType::query()->whereKey($context['leaveType']->id)->update(['days_per_year' => 30]);
    LeaveBalance::query()
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2026)
        ->update(['entitled_days' => 30, 'pending_days' => 0, 'used_days' => 0]);

    $leaveRequest = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-02',
            'total_days' => 2,
            'reason' => 'Unchanged',
        ],
        notify: false,
    );

    $approvalIds = $leaveRequest->approvals()->pluck('id')->all();
    $updatedAt = $leaveRequest->updated_at?->toIso8601String();
    $activityBefore = DB::table('activity_log')->where('subject_id', $leaveRequest->id)->count();

    Mail::fake();

    $updated = app(UpdateLeaveRequestWithApprovals::class)->handle(
        $leaveRequest,
        (int) $context['company']->id,
        [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-02',
            'reason' => 'Unchanged',
        ],
    );

    expect($updated->approvals()->pluck('id')->all())->toBe($approvalIds)
        ->and($updated->updated_at?->toIso8601String())->toBe($updatedAt)
        ->and(DB::table('activity_log')->where('subject_id', $leaveRequest->id)->count())->toBe($activityBefore);

    Mail::assertNothingQueued();
});

test('changing leave type does not credit the old type balance during edit', function () {
    $context = makeConsolidationContext();
    $otherType = LeaveType::factory()->for($context['company'])->create([
        'status' => 'active',
        'days_per_year' => 2,
    ]);

    LeaveType::query()->whereKey($context['leaveType']->id)->update(['days_per_year' => 30]);
    LeaveBalance::query()
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2026)
        ->update(['entitled_days' => 30, 'pending_days' => 0, 'used_days' => 0]);

    app(LeaveBalanceManager::class)->ensureEmployeeYear(
        (int) $context['company']->id,
        (int) $context['employee']->id,
        2026,
    );

    $leaveRequest = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-03',
            'total_days' => 3,
            'reason' => 'Type change',
        ],
        notify: false,
    );

    expect(fn () => app(UpdateLeaveRequestWithApprovals::class)->handle(
        $leaveRequest,
        (int) $context['company']->id,
        [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $otherType->id,
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-03',
            'reason' => 'Type change',
        ],
    ))->toThrow(RuntimeException::class);

    expect((int) $leaveRequest->fresh()->leave_type_id)->toBe((int) $context['leaveType']->id);
});

test('approval fails safely when pending reservation is missing and does not increase used', function () {
    $context = makeConsolidationContext();
    LeaveType::query()->whereKey($context['leaveType']->id)->update(['days_per_year' => 30]);
    LeaveBalance::query()
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2026)
        ->update(['entitled_days' => 30, 'pending_days' => 0, 'used_days' => 0]);

    $leaveRequest = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-02',
            'total_days' => 2,
            'reason' => 'Missing pending',
        ],
        notify: false,
    );

    LeaveBalance::query()
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2026)
        ->update(['pending_days' => 0]);

    expect(fn () => app(ApproveLeaveRequestStep::class)->handle(
        $leaveRequest,
        $context['managerUser'],
        (int) $context['company']->id,
    ))->toThrow(RuntimeException::class);

    $balance = LeaveBalance::query()
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2026)
        ->firstOrFail();

    expect((float) $balance->used_days)->toBe(0.0)
        ->and($leaveRequest->fresh()->status)->toBe('pending');
});

test('multiple pending approval rows fail safely instead of approving arbitrarily', function () {
    $context = makeConsolidationContext();
    LeaveType::query()->whereKey($context['leaveType']->id)->update(['days_per_year' => 30]);
    LeaveBalance::query()
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2026)
        ->update(['entitled_days' => 30, 'pending_days' => 0, 'used_days' => 0]);

    $leaveRequest = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-12-01',
            'end_date' => '2026-12-02',
            'total_days' => 2,
            'reason' => 'Corrupt snapshot',
        ],
        notify: false,
    );

    LeaveRequestApproval::query()->create([
        'company_id' => $context['company']->id,
        'leave_request_id' => $leaveRequest->id,
        'sequence' => 99,
        'approver_type' => LeaveApprovalApproverType::HrApprover,
        'approver_employee_id' => $context['managerEmployee']->id,
        'approver_user_id' => $context['managerUser']->id,
        'status' => LeaveRequestApprovalStatus::Pending,
        'is_required' => true,
    ]);

    expect(fn () => app(ApproveLeaveRequestStep::class)->handle(
        $leaveRequest,
        $context['managerUser'],
        (int) $context['company']->id,
    ))->toThrow(ValidationException::class);

    expect($leaveRequest->fresh()->status)->toBe('pending');
});

test('approver eligibility requires both view and approve permissions', function () {
    $context = makeConsolidationContext();
    $eligibility = app(LeaveApproverEligibility::class);
    $presenter = app(PresentLeaveApproverOption::class);

    $approveOnly = makeActionableApprover($context['company']);

    app(PermissionRegistrar::class)->setPermissionsTeamId($context['company']->id);
    $approveOnly['user']->unsetRelation('roles')->unsetRelation('permissions');
    $role = $approveOnly['user']->roles->first();
    $role?->revokePermissionTo('attendance.leave-requests.view');
    $approveOnly['user']->unsetRelation('roles')->unsetRelation('permissions');

    $evaluation = $eligibility->evaluate($approveOnly['employee']->fresh(['user']), (int) $context['company']->id);
    $presented = $presenter->present($approveOnly['employee']->fresh(['user']), (int) $context['company']->id);

    expect($evaluation['actionable'])->toBeFalse()
        ->and($evaluation['has_view_permission'])->toBeFalse()
        ->and($evaluation['has_approve_permission'])->toBeTrue()
        ->and($presented['actionable'])->toBeFalse()
        ->and($presented['has_view_permission'])->toBeFalse();
});
