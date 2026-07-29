<?php

use App\Enums\LeaveApprovalApproverType;
use App\Enums\LeaveRequestApprovalStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetCurrentCompany;
use App\Mail\LeaveRequestDecidedMail;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\LeaveApprovalPolicy;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\Actions\ApproveLeaveRequestStep;
use App\Support\Attendance\Actions\CancelLeaveRequestWorkflow;
use App\Support\Attendance\Actions\DeleteLeaveRequest;
use App\Support\Attendance\Actions\RejectLeaveRequestStep;
use App\Support\Attendance\Actions\SendLeaveRequestDecidedEmail;
use App\Support\Attendance\Actions\SubmitLeaveRequestWithApprovals;
use App\Support\Attendance\Actions\UpdateLeaveRequestWithApprovals;
use App\Support\Attendance\AssertLeaveApprovalWorkflowInvariant;
use App\Support\Attendance\AssertLeaveRequestOverlap;
use App\Support\Attendance\LeaveApproverEligibility;
use App\Support\Attendance\LeaveBalanceManager;
use App\Support\Attendance\LeaveRequestAttachments;
use App\Support\Attendance\PresentLeaveApproverOption;
use App\Support\Companies\ResolveCompanyAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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
function makeFinalCorrectionContext(int $daysPerYear = 30): array
{
    $country = Country::query()->create([
        'code' => 'FC'.fake()->unique()->numerify('##'),
        'name' => 'Final Correctionland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'FC'.fake()->unique()->numerify('##'),
        'name' => 'Final Correction Currency',
        'symbol' => 'F$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Final Correction Co',
        'slug' => 'fc-'.fake()->unique()->numerify('####'),
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
        'work_email' => 'employee-fc@example.com',
    ]);
    $leaveType = LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => $daysPerYear,
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

function submitPendingLeave(array $context, string $start, string $end, string $reason = 'Leave'): LeaveRequest
{
    return app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => $start,
            'end_date' => $end,
            'reason' => $reason,
        ],
        notify: false,
    );
}

function clearPendingReservation(LeaveRequest $leaveRequest): void
{
    LeaveBalance::query()
        ->where('company_id', $leaveRequest->company_id)
        ->where('employee_id', $leaveRequest->employee_id)
        ->where('leave_type_id', $leaveRequest->leave_type_id)
        ->update(['pending_days' => 0]);
}

test('inertia does not restore inaccessible home company after SetCurrentCompany rejects it', function () {
    $country = Country::query()->create([
        'code' => 'IH'.fake()->unique()->numerify('##'),
        'name' => 'Inertia Homeland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'IH'.fake()->unique()->numerify('##'),
        'name' => 'Inertia Home Currency',
        'symbol' => 'I$',
        'is_active' => true,
    ]);
    $home = Company::query()->create([
        'name' => 'Inactive Home Co',
        'slug' => 'ih-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'inactive',
    ]);
    $fallback = Company::query()->create([
        'name' => 'Fallback Active Co',
        'slug' => 'fa-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $user = User::factory()->create([
        'status' => 'active',
        'company_id' => $home->id,
    ]);
    DB::table('company_user')->insert([
        'company_id' => $fallback->id,
        'user_id' => $user->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    grantCompanyPermissions($user, $fallback, ['attendance.leave-requests.view']);

    $request = Request::create('/dashboard', 'GET');
    $request->setUserResolver(fn () => $user);
    $request->setLaravelSession(app('session.store'));
    $request->session()->put('current_company_id', $home->id);

    (new SetCurrentCompany(app(ResolveCompanyAccess::class)))->handle($request, function ($req) {
        $shared = (new HandleInertiaRequests)->share($req);

        expect((int) $req->attributes->get('current_company_id'))->toBe((int) $req->session()->get('current_company_id'))
            ->and((int) $shared['current_company_id'])->toBe((int) $req->attributes->get('current_company_id'))
            ->and((int) $shared['current_company_id'])->not->toBe((int) $req->user()->company_id)
            ->and(collect($shared['company_switcher_companies'])->pluck('id')->all())->not->toContain((int) $req->user()->company_id);

        return response('ok');
    });

    expect((int) $request->attributes->get('current_company_id'))->toBe((int) $fallback->id)
        ->and((int) app(PermissionRegistrar::class)->getPermissionsTeamId())->toBe((int) $fallback->id);
});

test('company switcher cache drops inaccessible companies after membership change', function () {
    $country = Country::query()->create([
        'code' => 'CS'.fake()->unique()->numerify('##'),
        'name' => 'Cache Switcherland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'CS'.fake()->unique()->numerify('##'),
        'name' => 'Cache Switcher Currency',
        'symbol' => 'C$',
        'is_active' => true,
    ]);
    $active = Company::query()->create([
        'name' => 'Switcher Active',
        'slug' => 'sa-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
    $other = Company::query()->create([
        'name' => 'Switcher Other',
        'slug' => 'so-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $user = User::factory()->create(['status' => 'active', 'company_id' => $active->id]);
    DB::table('company_user')->insert([
        [
            'company_id' => $active->id,
            'user_id' => $user->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'company_id' => $other->id,
            'user_id' => $user->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $request = Request::create('/dashboard', 'GET');
    $request->setUserResolver(fn () => $user);
    $request->setLaravelSession(app('session.store'));
    $request->attributes->set('current_company_id', $active->id);
    $request->session()->put('current_company_id', $active->id);

    $shared = (new HandleInertiaRequests)->share($request);
    expect(collect($shared['company_switcher_companies'])->pluck('id')->sort()->values()->all())
        ->toEqual(collect([(int) $active->id, (int) $other->id])->sort()->values()->all());

    DB::table('company_user')
        ->where('company_id', $other->id)
        ->where('user_id', $user->id)
        ->update(['status' => 'inactive']);

    $sharedAgain = (new HandleInertiaRequests)->share($request);
    expect(collect($sharedAgain['company_switcher_companies'])->pluck('id')->all())
        ->toBe([(int) $active->id]);
});

test('legacy no-pivot home user is actionable while inactive pivot is not', function () {
    $country = Country::query()->create([
        'code' => 'LG'.fake()->unique()->numerify('##'),
        'name' => 'Legacy Eligibilityland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'LG'.fake()->unique()->numerify('##'),
        'name' => 'Legacy Eligibility Currency',
        'symbol' => 'L$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Legacy Eligibility Co',
        'slug' => 'le-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $legacyUser = User::factory()->create(['status' => 'active', 'company_id' => $company->id]);
    grantCompanyPermissions($legacyUser, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.approve',
    ]);
    $legacyEmployee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'user_id' => $legacyUser->id,
    ]);

    $inactiveUser = User::factory()->create(['status' => 'active', 'company_id' => $company->id]);
    grantCompanyPermissions($inactiveUser, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.approve',
    ]);
    DB::table('company_user')
        ->where('company_id', $company->id)
        ->where('user_id', $inactiveUser->id)
        ->update(['status' => 'inactive']);
    $inactiveEmployee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'user_id' => $inactiveUser->id,
    ]);

    $access = app(ResolveCompanyAccess::class);
    $eligibility = app(LeaveApproverEligibility::class);

    expect($access->canAccess($legacyUser, (int) $company->id))->toBeTrue()
        ->and($eligibility->evaluate($legacyEmployee, (int) $company->id)['actionable'])->toBeTrue()
        ->and($access->canAccess($inactiveUser, (int) $company->id))->toBeFalse()
        ->and($eligibility->evaluate($inactiveEmployee, (int) $company->id)['actionable'])->toBeFalse()
        ->and($eligibility->evaluate($legacyEmployee, (int) $company->id)['has_active_company_membership'])
        ->toBe($access->hasAccessibleMembership($legacyUser, (int) $company->id));
});

test('missing pending reservation blocks rejection cancellation deletion and approval conversion', function () {
    $context = makeFinalCorrectionContext();
    $leaveRequest = submitPendingLeave($context, '2026-06-01', '2026-06-02');
    clearPendingReservation($leaveRequest);

    $pendingBefore = LeaveBalance::query()
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2026)
        ->value('pending_days');

    expect(fn () => app(RejectLeaveRequestStep::class)->handle(
        $leaveRequest,
        $context['managerUser'],
        (int) $context['company']->id,
        'No',
    ))->toThrow(RuntimeException::class);

    expect($leaveRequest->fresh()->status)->toBe('pending')
        ->and((float) LeaveBalance::query()
            ->where('employee_id', $context['employee']->id)
            ->where('leave_type_id', $context['leaveType']->id)
            ->where('year', 2026)
            ->value('pending_days'))->toBe((float) $pendingBefore);

    expect(fn () => app(CancelLeaveRequestWorkflow::class)->handle(
        $leaveRequest->fresh(),
        $context['managerUser'],
        (int) $context['company']->id,
        'Cancel',
    ))->toThrow(RuntimeException::class);

    expect($leaveRequest->fresh()->status)->toBe('pending');

    expect(fn () => app(DeleteLeaveRequest::class)->handle(
        $leaveRequest->fresh(),
        (int) $context['company']->id,
    ))->toThrow(RuntimeException::class);

    expect(LeaveRequest::query()->whereKey($leaveRequest->id)->exists())->toBeTrue()
        ->and($leaveRequest->fresh()->status)->toBe('pending');

    expect(fn () => app(LeaveBalanceManager::class)->convertPendingToUsed($leaveRequest->fresh()))
        ->toThrow(RuntimeException::class);

    expect((float) LeaveBalance::query()
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2026)
        ->value('used_days'))->toBe(0.0);
});

test('missing dates and wrong-company leave type fail release safely', function () {
    $context = makeFinalCorrectionContext();
    $leaveRequest = submitPendingLeave($context, '2026-06-08', '2026-06-09');

    $corrupted = $leaveRequest->fresh();
    $corrupted->start_date = null;
    $corrupted->end_date = null;

    expect(fn () => app(LeaveBalanceManager::class)->releasePendingReservation($corrupted))
        ->toThrow(RuntimeException::class);

    $otherCompany = Company::query()->create([
        'name' => 'Other Leave Type Co',
        'slug' => 'olt-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $context['company']->country_id,
        'currency_id' => $context['company']->currency_id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
    $foreignType = LeaveType::factory()->for($otherCompany)->create(['status' => 'active', 'days_per_year' => 10]);

    $wrongType = $leaveRequest->fresh();
    $wrongType->leave_type_id = $foreignType->id;

    expect(fn () => app(LeaveBalanceManager::class)->releasePendingReservation($wrongType))
        ->toThrow(RuntimeException::class);
});

test('multi-year release is all-or-nothing when one year pending is insufficient', function () {
    $context = makeFinalCorrectionContext(40);
    LeaveBalance::query()
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2026)
        ->update(['entitled_days' => 40, 'pending_days' => 0, 'used_days' => 0]);

    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $context['company']->id, (int) $context['employee']->id, 2027);
    LeaveBalance::query()
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2027)
        ->update(['entitled_days' => 40, 'pending_days' => 0, 'used_days' => 0]);

    $leaveRequest = submitPendingLeave($context, '2026-12-30', '2027-01-02', 'Cross year');

    $year2026 = LeaveBalance::query()
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2026)
        ->firstOrFail();
    $year2027 = LeaveBalance::query()
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2027)
        ->firstOrFail();

    $pending2026 = (float) $year2026->pending_days;
    $pending2027 = (float) $year2027->pending_days;

    expect($pending2026)->toBeGreaterThan(0)
        ->and($pending2027)->toBeGreaterThan(0);

    $year2027->forceFill(['pending_days' => 0])->save();

    expect(fn () => app(LeaveBalanceManager::class)->releasePendingReservation($leaveRequest->fresh()))
        ->toThrow(RuntimeException::class);

    expect((float) $year2026->fresh()->pending_days)->toBe($pending2026)
        ->and((float) $year2027->fresh()->pending_days)->toBe(0.0);
});

test('approval workflow invariant rejects corrupted step sequences', function () {
    $context = makeFinalCorrectionContext();
    $leaveRequest = submitPendingLeave($context, '2026-07-01', '2026-07-01');

    $approvals = LeaveRequestApproval::query()
        ->where('leave_request_id', $leaveRequest->id)
        ->orderBy('sequence')
        ->get();

    $first = $approvals->first();
    LeaveRequestApproval::query()->create([
        'company_id' => $context['company']->id,
        'leave_request_id' => $leaveRequest->id,
        'sequence' => 2,
        'approver_type' => LeaveApprovalApproverType::HrApprover->value,
        'approver_employee_id' => $context['managerEmployee']->id,
        'approver_user_id' => $context['managerUser']->id,
        'is_required' => true,
        'status' => LeaveRequestApprovalStatus::Pending,
        'policy_id' => $first->policy_id,
    ]);

    $corrupted = LeaveRequestApproval::query()
        ->where('leave_request_id', $leaveRequest->id)
        ->orderBy('sequence')
        ->get();

    expect(fn () => app(AssertLeaveApprovalWorkflowInvariant::class)->forPendingRequest($leaveRequest, $corrupted, $context['managerUser']))
        ->toThrow(ValidationException::class);

    $optionalPending = $leaveRequest->fresh();
    LeaveRequestApproval::query()->where('leave_request_id', $leaveRequest->id)->delete();
    LeaveRequestApproval::query()->create([
        'company_id' => $context['company']->id,
        'leave_request_id' => $leaveRequest->id,
        'sequence' => 1,
        'approver_type' => LeaveApprovalApproverType::DepartmentManager->value,
        'approver_employee_id' => $context['managerEmployee']->id,
        'approver_user_id' => $context['managerUser']->id,
        'is_required' => false,
        'status' => LeaveRequestApprovalStatus::Pending,
        'policy_id' => $first->policy_id,
    ]);

    expect(fn () => app(AssertLeaveApprovalWorkflowInvariant::class)->forPendingRequest(
        $optionalPending,
        LeaveRequestApproval::query()->where('leave_request_id', $leaveRequest->id)->get(),
        $context['managerUser'],
    ))->toThrow(ValidationException::class);

    LeaveRequestApproval::query()->where('leave_request_id', $leaveRequest->id)->delete();
    LeaveRequestApproval::query()->create([
        'company_id' => $context['company']->id,
        'leave_request_id' => $leaveRequest->id,
        'sequence' => 1,
        'approver_type' => LeaveApprovalApproverType::DepartmentManager->value,
        'approver_employee_id' => $context['employee']->id,
        'approver_user_id' => $context['managerUser']->id,
        'is_required' => true,
        'status' => LeaveRequestApprovalStatus::Pending,
        'policy_id' => $first->policy_id,
    ]);

    expect(fn () => app(AssertLeaveApprovalWorkflowInvariant::class)->forPendingRequest(
        $leaveRequest->fresh(),
        LeaveRequestApproval::query()->where('leave_request_id', $leaveRequest->id)->get(),
        $context['managerUser'],
    ))->toThrow(ValidationException::class);

    expect($leaveRequest->fresh()->status)->toBe('pending');
});

test('out of order required steps fail invariant and leave balance unchanged', function () {
    $context = makeFinalCorrectionContext();
    $hr = makeActionableApprover($context['company']);
    configureCompanyLeaveApprovalSettings($context['company'], $hr['employee']);

    LeaveApprovalPolicy::query()->where('company_id', $context['company']->id)->delete();
    ensureDefaultLeaveApprovalPolicy($context['company'], [
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
        ['type' => LeaveApprovalApproverType::HrApprover, 'required' => true],
    ]);

    $leaveRequest = submitPendingLeave($context, '2026-07-06', '2026-07-07');
    $steps = LeaveRequestApproval::query()
        ->where('leave_request_id', $leaveRequest->id)
        ->orderBy('sequence')
        ->get();

    expect($steps)->toHaveCount(2);

    $steps[0]->forceFill(['status' => LeaveRequestApprovalStatus::Waiting])->save();
    $steps[1]->forceFill(['status' => LeaveRequestApprovalStatus::Pending])->save();

    $pendingBefore = (float) LeaveBalance::query()
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2026)
        ->value('pending_days');

    expect(fn () => app(ApproveLeaveRequestStep::class)->handle(
        $leaveRequest->fresh(),
        $hr['user'],
        (int) $context['company']->id,
    ))->toThrow(ValidationException::class);

    expect($leaveRequest->fresh()->status)->toBe('pending')
        ->and((float) LeaveBalance::query()
            ->where('employee_id', $context['employee']->id)
            ->where('leave_type_id', $context['leaveType']->id)
            ->where('year', 2026)
            ->value('pending_days'))->toBe($pendingBefore);
});

test('valid manager-only chain passes invariant and approval', function () {
    $context = makeFinalCorrectionContext();
    $leaveRequest = submitPendingLeave($context, '2026-07-13', '2026-07-13');

    $approved = app(ApproveLeaveRequestStep::class)->handle(
        $leaveRequest,
        $context['managerUser'],
        (int) $context['company']->id,
    );

    expect($approved->status)->toBe('approved')
        ->and((float) LeaveBalance::query()
            ->where('employee_id', $context['employee']->id)
            ->where('leave_type_id', $context['leaveType']->id)
            ->where('year', 2026)
            ->value('pending_days'))->toBe(0.0)
        ->and((float) LeaveBalance::query()
            ->where('employee_id', $context['employee']->id)
            ->where('leave_type_id', $context['leaveType']->id)
            ->where('year', 2026)
            ->value('used_days'))->toBeGreaterThan(0.0);
});

test('frontend attachment payload omits storage path while database retains it', function () {
    $context = makeFinalCorrectionContext();
    $leaveRequest = submitPendingLeave($context, '2026-07-20', '2026-07-20');
    $leaveRequest->forceFill([
        'attachments' => [[
            'path' => 'leave-requests/1/1/secret.pdf',
            'name' => 'secret.pdf',
            'size' => 12,
            'mime' => 'application/pdf',
        ]],
    ])->save();

    $payload = app(LeaveRequestAttachments::class)->serializeForFrontend(
        $leaveRequest->fresh()->attachments,
        (int) $leaveRequest->id,
    );

    expect($payload)->toHaveCount(1)
        ->and($payload[0])->not->toHaveKey('path')
        ->and($payload[0])->toHaveKeys(['name', 'size', 'mime', 'url'])
        ->and($leaveRequest->fresh()->attachments[0]['path'])->toBe('leave-requests/1/1/secret.pdf');
});

test('attachment download is company and authorization scoped', function () {
    Storage::fake('local');

    $context = makeFinalCorrectionContext();
    $owner = User::factory()->create(['status' => 'active', 'company_id' => $context['company']->id]);
    DB::table('company_user')->insert([
        'company_id' => $context['company']->id,
        'user_id' => $owner->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $context['employee']->forceFill(['user_id' => $owner->id])->save();

    $leaveRequest = submitPendingLeave($context, '2026-07-21', '2026-07-21');
    $path = 'leave-requests/'.$context['company']->id.'/'.$leaveRequest->id.'/note.pdf';
    $leaveRequest->forceFill([
        'attachments' => [[
            'path' => $path,
            'name' => 'note.pdf',
            'size' => 10,
            'mime' => 'application/pdf',
        ]],
    ])->save();

    Storage::disk('local')->put($path, 'pdf-bytes');

    grantCompanyPermissions($owner, $context['company'], [
        'attendance.leave-requests.view',
    ]);

    $this->actingAs($owner)
        ->withSession(['current_company_id' => $context['company']->id])
        ->get(route('attendance.leave-requests.attachment', $leaveRequest))
        ->assertOk();

    $outsider = User::factory()->create(['status' => 'active']);
    $otherCompany = Company::query()->create([
        'name' => 'Outsider Co',
        'slug' => 'out-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $context['company']->country_id,
        'currency_id' => $context['company']->currency_id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
    DB::table('company_user')->insert([
        'company_id' => $otherCompany->id,
        'user_id' => $outsider->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    grantCompanyPermissions($outsider, $otherCompany, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.view_all',
    ]);

    $this->actingAs($outsider)
        ->withSession(['current_company_id' => $otherCompany->id])
        ->get(route('attendance.leave-requests.attachment', $leaveRequest))
        ->assertNotFound();
});

test('decided email uses snapshot approver email not live manager', function () {
    Mail::fake();
    $context = makeFinalCorrectionContext();
    $context['managerEmployee']->forceFill([
        'work_email' => 'snapshot-approver@example.com',
        'personal_email' => null,
    ])->save();

    EmailTemplate::query()->updateOrCreate(
        ['slug' => 'leave_request_approved'],
        [
            'label' => 'Leave approved',
            'subject' => 'Approved',
            'body_html' => 'Approved body',
            'enabled' => true,
            'to_preset' => 'hr-preset@example.com',
            'cc_preset' => 'fyi-preset@example.com, employee-fc@example.com',
            'include_company_footer' => false,
            'category' => 'hr',
        ],
    );

    $leaveRequest = submitPendingLeave($context, '2026-07-22', '2026-07-22');
    $approved = app(ApproveLeaveRequestStep::class)->handle(
        $leaveRequest,
        $context['managerUser'],
        (int) $context['company']->id,
    );

    $replacementManager = Employee::factory()->forCompany($context['company'])->create([
        'status' => 'active',
        'work_email' => 'changed-live-manager@example.com',
    ]);
    Department::query()
        ->whereKey($context['employee']->department_id)
        ->update(['manager_id' => $replacementManager->id]);

    app(SendLeaveRequestDecidedEmail::class)->handle($approved->fresh());

    Mail::assertQueued(LeaveRequestDecidedMail::class, function (LeaveRequestDecidedMail $mail) {
        expect($mail->hasTo('employee-fc@example.com'))->toBeTrue()
            ->and($mail->hasCc('snapshot-approver@example.com'))->toBeTrue()
            ->and($mail->hasCc('hr-preset@example.com'))->toBeTrue()
            ->and($mail->hasCc('fyi-preset@example.com'))->toBeTrue()
            ->and($mail->hasCc('changed-live-manager@example.com'))->toBeFalse()
            ->and($mail->hasCc('employee-fc@example.com'))->toBeFalse();

        return true;
    });
});

test('submit ignores caller total_days and update rejects invalid dates', function () {
    $context = makeFinalCorrectionContext();

    $leaveRequest = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-08-03',
            'end_date' => '2026-08-04',
            'total_days' => 99,
            'reason' => 'Mismatched total',
        ],
        notify: false,
    );

    expect((float) $leaveRequest->total_days)->toBeLessThan(10)
        ->and((float) $leaveRequest->total_days)->not->toBe(99.0);

    expect(fn () => app(UpdateLeaveRequestWithApprovals::class)->handle(
        $leaveRequest,
        (int) $context['company']->id,
        [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-08-10',
            'end_date' => '2026-08-01',
            'reason' => 'Reversed',
        ],
    ))->toThrow(ValidationException::class);

    expect(fn () => app(UpdateLeaveRequestWithApprovals::class)->handle(
        $leaveRequest,
        (int) $context['company']->id,
        [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => 'not-a-date',
            'end_date' => '2026-08-01',
            'reason' => 'Bad start',
        ],
    ))->toThrow(ValidationException::class);

    expect($leaveRequest->fresh()->start_date?->toDateString())->toBe('2026-08-03');
});

test('overlap uses direct date comparisons with correct boundaries', function () {
    $context = makeFinalCorrectionContext();
    $existing = submitPendingLeave($context, '2026-09-01', '2026-09-05');

    expect($existing->start_date?->toDateString())->toBe('2026-09-01')
        ->and($existing->end_date?->toDateString())->toBe('2026-09-05');

    expect(fn () => app(AssertLeaveRequestOverlap::class)->handle(
        (int) $context['company']->id,
        (int) $context['employee']->id,
        '2026-09-05',
        '2026-09-06',
    ))->toThrow(ValidationException::class);

    expect(fn () => app(AssertLeaveRequestOverlap::class)->handle(
        (int) $context['company']->id,
        (int) $context['employee']->id,
        '2026-09-01',
        '2026-09-01',
    ))->toThrow(ValidationException::class);

    expect(fn () => app(AssertLeaveRequestOverlap::class)->handle(
        (int) $context['company']->id,
        (int) $context['employee']->id,
        '2026-08-31',
        '2026-09-01',
    ))->toThrow(ValidationException::class);

    app(AssertLeaveRequestOverlap::class)->handle(
        (int) $context['company']->id,
        (int) $context['employee']->id,
        '2026-09-06',
        '2026-09-07',
    );

    $otherEmployee = Employee::factory()->forCompany($context['company'])->create([
        'status' => 'active',
        'department_id' => $context['employee']->department_id,
    ]);

    app(AssertLeaveRequestOverlap::class)->handle(
        (int) $context['company']->id,
        (int) $otherEmployee->id,
        '2026-09-01',
        '2026-09-05',
    );
});

test('policy index exposes row can_delete and move step route is removed', function () {
    $context = makeFinalCorrectionContext();
    $admin = User::factory()->create(['status' => 'active', 'company_id' => $context['company']->id]);
    DB::table('company_user')->insert([
        'company_id' => $context['company']->id,
        'user_id' => $admin->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    grantCompanyPermissions($admin, $context['company'], [
        'attendance.leave-approval-policies.view',
        'attendance.leave-approval-policies.update',
        'attendance.leave-approval-policies.delete',
    ]);

    $assigned = LeaveApprovalPolicy::factory()->forCompany($context['company'])->withSteps([
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
    ])->create(['is_default' => false, 'name' => 'Assigned Policy']);

    Department::query()
        ->where('company_id', $context['company']->id)
        ->update(['leave_approval_policy_id' => $assigned->id]);

    $this->actingAs($admin)
        ->withSession(['current_company_id' => $context['company']->id])
        ->get(route('attendance.leave-approval-policies.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('policies')
            ->where('policies', function ($policies) use ($assigned) {
                $row = collect($policies)->firstWhere('id', $assigned->id);

                return $row !== null
                    && $row['can_delete'] === false
                    && filled($row['delete_blocked_reason']);
            }));

    expect(Route::has('attendance.leave-approval-policies.steps.move'))->toBeFalse();
});

test('policy listing and approver eligibility query counts stay bounded', function () {
    $context = makeFinalCorrectionContext();
    $admin = User::factory()->create(['status' => 'active', 'company_id' => $context['company']->id]);
    DB::table('company_user')->insert([
        'company_id' => $context['company']->id,
        'user_id' => $admin->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    grantCompanyPermissions($admin, $context['company'], [
        'attendance.leave-approval-policies.view',
        'attendance.leave-approval-policies.update',
        'attendance.leave-approval-policies.delete',
        'attendance.leave-approval-settings.view',
    ]);

    for ($i = 0; $i < 5; $i++) {
        LeaveApprovalPolicy::factory()->forCompany($context['company'])->withSteps([
            ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
        ])->create(['is_default' => false, 'name' => 'Policy Small '.$i]);
    }

    $this->actingAs($admin)->withSession(['current_company_id' => $context['company']->id]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get(route('attendance.leave-approval-policies.index'))->assertOk();
    $smallPolicyQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    for ($i = 0; $i < 45; $i++) {
        LeaveApprovalPolicy::factory()->forCompany($context['company'])->withSteps([
            ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
        ])->create(['is_default' => false, 'name' => 'Policy Large '.$i]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get(route('attendance.leave-approval-policies.index').'?per_page=100')->assertOk();
    $largePolicyQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($largePolicyQueries)->toBeLessThan($smallPolicyQueries + 20);

    $employees = Employee::factory()->forCompany($context['company'])->count(100)->create(['status' => 'active']);
    foreach ($employees as $employee) {
        $user = User::factory()->create(['status' => 'active']);
        DB::table('company_user')->insert([
            'company_id' => $context['company']->id,
            'user_id' => $user->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $employee->forceFill(['user_id' => $user->id])->save();
        grantCompanyPermissions($user, $context['company'], [
            'attendance.leave-requests.view',
            'attendance.leave-requests.approve',
        ]);
    }

    $ten = $employees->take(10)->values();
    $many = $employees->take(100)->values();

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(LeaveApproverEligibility::class)->evaluateMany($ten, (int) $context['company']->id);
    $smallEligibility = count(DB::getQueryLog());
    DB::disableQueryLog();

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(LeaveApproverEligibility::class)->evaluateMany($many, (int) $context['company']->id);
    $largeEligibility = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($largeEligibility)->toBeLessThan(25)
        ->and($largeEligibility)->toBeLessThanOrEqual($smallEligibility + 10);

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(PresentLeaveApproverOption::class)->forCompany((int) $context['company']->id, activeOnly: true);
    $presenterQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($presenterQueries)->toBeLessThan(40);
});

test('leave_request_approvals keeps a single company approver status index and policy index', function () {
    $indexes = collect(Schema::getIndexes('leave_request_approvals'));
    $companyApproverStatus = $indexes
        ->filter(fn (array $index): bool => array_map('strtolower', $index['columns'] ?? []) === ['company_id', 'approver_user_id', 'status'])
        ->values();

    expect($companyApproverStatus)->toHaveCount(1);

    $policyIndex = $indexes->first(
        fn (array $index): bool => array_map('strtolower', $index['columns'] ?? []) === ['company_id', 'policy_id'],
    );

    expect($policyIndex)->not->toBeNull();
});

test('terminal request with open steps fails invariant', function () {
    $context = makeFinalCorrectionContext();
    $leaveRequest = submitPendingLeave($context, '2026-10-01', '2026-10-01');
    $leaveRequest->forceFill(['status' => 'approved'])->save();

    expect(fn () => app(AssertLeaveApprovalWorkflowInvariant::class)->forTerminalRequest(
        $leaveRequest->fresh(),
        LeaveRequestApproval::query()->where('leave_request_id', $leaveRequest->id)->get(),
    ))->toThrow(ValidationException::class);
});
