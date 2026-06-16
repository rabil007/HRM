<?php

/**
 * End-to-end mock drill simulating production leave balance operations.
 * Run: php artisan test --compact tests/Feature/Attendance/LeaveBalanceProductionDrillTest.php
 */

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\LeaveBalanceManager;
use App\Support\Attendance\LeaveTypeYearBalance;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{user: User, company: Company}
 */
function makeDrillFixtures(): array
{
    $user = User::factory()->create();
    $country = Country::query()->create([
        'code' => 'DR'.fake()->unique()->numerify('##'),
        'name' => 'Drill Country',
        'dial_code' => '+999',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'DR'.fake()->unique()->numerify('##'),
        'name' => 'Drill Currency',
        'symbol' => 'D$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Drill Co',
        'slug' => 'drill-'.fake()->unique()->numerify('####'),
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

    return ['user' => $user, 'company' => $company];
}

test('production mock drill: full leave balance lifecycle with carry forward', function () {
    ['user' => $hrUser, 'company' => $company] = makeDrillFixtures();

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Drill Employee',
        'user_id' => $hrUser->id,
    ]);

    grantCompanyPermissions($hrUser, $company, [
        'attendance.types.create',
        'attendance.leave-requests.view',
        'attendance.leave-requests.create',
        'attendance.leave-requests.update',
        'attendance.leave-requests.approve',
        'attendance.leave-requests.delete',
    ]);

    $this->actingAs($hrUser);

    // Step 1: HR creates Annual Leave type once (not every year)
    $this->post(route('attendance.types.store'), [
        'name' => 'Annual Leave Drill',
        'code' => 'ALD',
        'days_per_year' => 30,
        'carry_forward' => true,
        'max_carry_days' => 10,
        'color' => '#3b82f6',
        'status' => 'active',
    ])->assertRedirect();

    $annualLeave = LeaveType::query()
        ->where('company_id', $company->id)
        ->where('code', 'ALD')
        ->first();

    expect($annualLeave)->not->toBeNull();

    $initialBalance = LeaveBalance::query()
        ->where('employee_id', $employee->id)
        ->where('leave_type_id', $annualLeave->id)
        ->where('year', 2026)
        ->first();

    expect($initialBalance)->not->toBeNull()
        ->and((float) $initialBalance->entitled_days)->toBe(30.0)
        ->and((float) $initialBalance->carried_days)->toBe(0.0)
        ->and((float) $initialBalance->remaining_days)->toBe(30.0);

    // Step 2: Employee submits 5-day leave request
    $this->post('/attendance/leave-requests', [
        'employee_id' => $employee->id,
        'leave_type_id' => $annualLeave->id,
        'start_date' => '2026-03-01',
        'end_date' => '2026-03-05',
        'reason' => 'Family trip',
    ])->assertRedirect(route('attendance.leave-requests.index'));

    $pendingRequest = LeaveRequest::query()
        ->where('employee_id', $employee->id)
        ->where('status', 'pending')
        ->first();

    expect($pendingRequest)->not->toBeNull();

    $afterPending = $initialBalance->fresh();
    expect((float) $afterPending->pending_days)->toBe(5.0)
        ->and((float) $afterPending->used_days)->toBe(0.0)
        ->and((float) $afterPending->remaining_days)->toBe(25.0);

    // Step 3: Over-limit request is blocked
    $this->from('/attendance/leave-requests')
        ->post('/attendance/leave-requests', [
            'employee_id' => $employee->id,
            'leave_type_id' => $annualLeave->id,
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-30',
            'reason' => 'Too many days',
        ])->assertSessionHasErrors('leave_type_id');

    // Step 4: HR approves the 5-day request
    $this->put("/attendance/leave-requests/{$pendingRequest->id}/approve")
        ->assertRedirect(route('attendance.leave-requests.index'));

    $afterApproval = $initialBalance->fresh();
    expect($pendingRequest->fresh()->status)->toBe('approved')
        ->and((float) $afterApproval->pending_days)->toBe(0.0)
        ->and((float) $afterApproval->used_days)->toBe(5.0)
        ->and((float) $afterApproval->remaining_days)->toBe(25.0);

    // Step 5: Calendar shows correct balance for linked employee
    $this->get(route('attendance.calendar.index', ['year' => 2026]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('linked_employee_id', $employee->id)
            ->where('leave_types.0.entitled_days', 30)
            ->where('leave_types.0.used_days', 5)
            ->where('leave_types.0.pending_days', 0)
            ->where('leave_types.0.remaining_days', 25));

    // Step 6: Simulate end of 2026 — employee used 22 days total in 2026
    LeaveBalance::query()
        ->whereKey($afterApproval->id)
        ->update([
            'used_days' => 22,
            'pending_days' => 0,
        ]);

    $yearEndBalance = $initialBalance->fresh();
    expect((float) $yearEndBalance->remaining_days)->toBe(8.0);

    // Step 7: Year rollover opens 2027 with carry forward (max 10)
    app(LeaveBalanceManager::class)->rolloverCompany($company->id, 2027);

    $year2027Balance = LeaveBalance::query()
        ->where('employee_id', $employee->id)
        ->where('leave_type_id', $annualLeave->id)
        ->where('year', 2027)
        ->first();

    expect($year2027Balance)->not->toBeNull()
        ->and((float) $year2027Balance->entitled_days)->toBe(30.0)
        ->and((float) $year2027Balance->carried_days)->toBe(8.0)
        ->and((float) $year2027Balance->remaining_days)->toBe(38.0);

    // Step 8: Sync command rebuilds balances correctly from requests
    $this->artisan('leave-balances:sync', ['year' => 2026])->assertSuccessful();

    $resynced2026 = $initialBalance->fresh();
    expect((float) $resynced2026->used_days)->toBe(5.0)
        ->and((float) $resynced2026->pending_days)->toBe(0.0)
        ->and((float) $resynced2026->remaining_days)->toBe(25.0);

    // Step 9: LeaveTypeYearBalance service matches stored ledger
    $legend = app(LeaveTypeYearBalance::class)->forEmployee($company->id, $employee->id, 2026);

    expect($legend)->toHaveCount(1)
        ->and($legend[0]['entitled_days'])->toBe(30.0)
        ->and($legend[0]['used_days'])->toBe(5.0)
        ->and($legend[0]['pending_days'])->toBe(0.0)
        ->and($legend[0]['remaining_days'])->toBe(25.0);

    // Step 10: Reject flow releases pending days
    $rejectable = LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $annualLeave->id,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-02',
        'total_days' => 2,
        'status' => 'pending',
    ]);

    app(LeaveBalanceManager::class)->reserveLeaveRequest($rejectable);

    expect((float) $initialBalance->fresh()->pending_days)->toBe(2.0);

    grantCompanyPermissions($hrUser, $company, ['attendance.leave-requests.approve']);

    $this->put("/attendance/leave-requests/{$rejectable->id}/reject", [
        'rejection_reason' => 'Coverage needed',
    ])->assertRedirect(route('attendance.leave-requests.index'));

    expect($rejectable->fresh()->status)->toBe('rejected')
        ->and((float) $initialBalance->fresh()->pending_days)->toBe(0.0)
        ->and((float) $initialBalance->fresh()->remaining_days)->toBe(25.0);
});
