<?php

use App\Enums\LeaveApprovalApproverType;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\Actions\ApproveLeaveRequestStep;
use App\Support\Attendance\Actions\CancelLeaveRequestWorkflow;
use App\Support\Attendance\Actions\RejectLeaveRequestStep;
use App\Support\Attendance\Actions\SubmitLeaveRequestWithApprovals;
use App\Support\Attendance\Actions\UpdateLeaveRequestWithApprovals;
use App\Support\Attendance\LeaveBalanceManager;
use Illuminate\Support\Facades\DB;

/**
 * @return array{company: Company, employee: Employee, leaveType: LeaveType, managerUser: User}
 */
function makeBalanceConcurrencyContext(): array
{
    $country = Country::query()->create([
        'code' => 'BC'.fake()->unique()->numerify('##'),
        'name' => 'Balance Concurrencyland',
        'dial_code' => '+987',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'BC'.fake()->unique()->numerify('##'),
        'name' => 'Balance Currency',
        'symbol' => 'B$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Balance Concurrency Co',
        'slug' => 'bc-'.fake()->unique()->numerify('####'),
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
        'days_per_year' => 30,
    ]);

    $manager = app(LeaveBalanceManager::class);
    $manager->ensureEmployeeYear((int) $company->id, (int) $employee->id, 2025);
    $manager->ensureEmployeeYear((int) $company->id, (int) $employee->id, 2026);

    return [
        'company' => $company,
        'employee' => $employee,
        'leaveType' => $leaveType,
        'managerUser' => $managed['managerUser'],
    ];
}

function submitPendingLeaveRequest(
    Company $company,
    Employee $employee,
    LeaveType $leaveType,
    string $startDate,
    string $endDate,
    float $totalDays,
): LeaveRequest {
    return app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $company->id,
        attributes: [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'total_days' => $totalDays,
            'reason' => 'Balance test',
        ],
        reserveBalance: false,
        notify: false,
    );
}

test('two sequential reservations accumulate pending_days without lost updates', function () {
    $context = makeBalanceConcurrencyContext();
    $manager = app(LeaveBalanceManager::class);

    $first = submitPendingLeaveRequest(
        $context['company'],
        $context['employee'],
        $context['leaveType'],
        '2026-06-10',
        '2026-06-11',
        2,
    );
    $second = submitPendingLeaveRequest(
        $context['company'],
        $context['employee'],
        $context['leaveType'],
        '2026-07-10',
        '2026-07-12',
        3,
    );

    DB::transaction(function () use ($manager, $first, $second): void {
        $manager->reserveLeaveRequest($first);
        $manager->reserveLeaveRequest($second);
    });

    $pending = LeaveBalance::query()
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2026)
        ->value('pending_days');

    expect((float) $pending)->toBe(5.0);
});

test('approval converts pending to used exactly once', function () {
    $context = makeBalanceConcurrencyContext();
    $manager = app(LeaveBalanceManager::class);

    $leaveRequest = submitPendingLeaveRequest(
        $context['company'],
        $context['employee'],
        $context['leaveType'],
        '2026-06-10',
        '2026-06-12',
        3,
    );

    DB::transaction(fn () => $manager->reserveLeaveRequest($leaveRequest));

    app(ApproveLeaveRequestStep::class)->handle(
        $leaveRequest,
        $context['managerUser'],
        (int) $context['company']->id,
    );

    $balance = LeaveBalance::query()
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2026)
        ->firstOrFail();

    expect((float) $balance->pending_days)->toBe(0.0)
        ->and((float) $balance->used_days)->toBe(3.0);
});

test('rejection releases pending exactly once', function () {
    $context = makeBalanceConcurrencyContext();
    $manager = app(LeaveBalanceManager::class);

    $leaveRequest = submitPendingLeaveRequest(
        $context['company'],
        $context['employee'],
        $context['leaveType'],
        '2026-06-10',
        '2026-06-12',
        3,
    );

    DB::transaction(fn () => $manager->reserveLeaveRequest($leaveRequest));

    app(RejectLeaveRequestStep::class)->handle(
        $leaveRequest,
        $context['managerUser'],
        (int) $context['company']->id,
        'Insufficient coverage',
    );

    $balance = LeaveBalance::query()
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2026)
        ->firstOrFail();

    expect((float) $balance->pending_days)->toBe(0.0)
        ->and((float) $balance->used_days)->toBe(0.0);
});

test('cancellation releases pending exactly once', function () {
    $context = makeBalanceConcurrencyContext();
    $manager = app(LeaveBalanceManager::class);
    $owner = User::factory()->create(['status' => 'active']);

    $leaveRequest = submitPendingLeaveRequest(
        $context['company'],
        $context['employee'],
        $context['leaveType'],
        '2026-06-10',
        '2026-06-12',
        3,
    );

    DB::transaction(fn () => $manager->reserveLeaveRequest($leaveRequest));

    app(CancelLeaveRequestWorkflow::class)->handle(
        $leaveRequest,
        $owner,
        (int) $context['company']->id,
        'Changed plans',
    );

    $balance = LeaveBalance::query()
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2026)
        ->firstOrFail();

    expect((float) $balance->pending_days)->toBe(0.0)
        ->and((float) $balance->used_days)->toBe(0.0);
});

test('edit replacement keeps correct balance via UpdateLeaveRequestWithApprovals', function () {
    $context = makeBalanceConcurrencyContext();
    $manager = app(LeaveBalanceManager::class);

    $leaveRequest = submitPendingLeaveRequest(
        $context['company'],
        $context['employee'],
        $context['leaveType'],
        '2026-06-01',
        '2026-06-02',
        2,
    );

    DB::transaction(fn () => $manager->reserveLeaveRequest($leaveRequest));

    app(UpdateLeaveRequestWithApprovals::class)->handle(
        $leaveRequest,
        (int) $context['company']->id,
        [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-14',
            'reason' => 'Extended',
        ],
    );

    $balance = LeaveBalance::query()
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2026)
        ->firstOrFail();

    expect((float) $balance->pending_days)->toBe(5.0)
        ->and((float) $balance->used_days)->toBe(0.0);
});

test('multi-year request updates each year balance row', function () {
    $context = makeBalanceConcurrencyContext();
    $manager = app(LeaveBalanceManager::class);
    $manager->ensureEmployeeYear(
        (int) $context['company']->id,
        (int) $context['employee']->id,
        2025,
    );

    $leaveRequest = submitPendingLeaveRequest(
        $context['company'],
        $context['employee'],
        $context['leaveType'],
        '2025-12-30',
        '2026-01-02',
        4,
    );

    DB::transaction(fn () => $manager->reserveLeaveRequest($leaveRequest));

    $year2025 = LeaveBalance::query()
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2025)
        ->firstOrFail();

    $year2026 = LeaveBalance::query()
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2026)
        ->firstOrFail();

    expect((float) $year2025->pending_days)->toBeGreaterThan(0)
        ->and((float) $year2026->pending_days)->toBeGreaterThan(0)
        ->and((float) $year2025->pending_days + (float) $year2026->pending_days)
        ->toBe((float) $leaveRequest->total_days);
});

test('repeated release on a still-pending request fails when reservation is already gone', function () {
    $context = makeBalanceConcurrencyContext();
    $manager = app(LeaveBalanceManager::class);

    $leaveRequest = submitPendingLeaveRequest(
        $context['company'],
        $context['employee'],
        $context['leaveType'],
        '2026-06-10',
        '2026-06-12',
        3,
    );

    DB::transaction(fn () => $manager->reserveLeaveRequest($leaveRequest));
    DB::transaction(fn () => $manager->releaseLeaveRequest($leaveRequest));

    expect(fn () => DB::transaction(
        fn () => $manager->releaseLeaveRequest($leaveRequest->fresh() ?? $leaveRequest),
    ))->toThrow(RuntimeException::class);

    $pending = LeaveBalance::query()
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2026)
        ->value('pending_days');

    expect((float) $pending)->toBe(0.0);
});
