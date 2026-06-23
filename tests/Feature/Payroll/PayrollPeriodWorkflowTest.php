<?php

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\AttendanceRecord;
use App\Models\CrewTimesheet;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Support\Payroll\Actions\SyncContractSalaryComponentsFromContract;

test('users without permission cannot approve pay period', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.view']);

    $period = PayrollPeriod::factory()->for($company)->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.approve', $period))
        ->assertForbidden();
});

test('users without permission cannot mark pay period as paid', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.view']);

    $period = PayrollPeriod::factory()->for($company)->approved()->create();

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.mark-paid', $period))
        ->assertForbidden();
});

test('users without permission cannot cancel pay period', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.view']);

    $period = PayrollPeriod::factory()->for($company)->create();

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.cancel', $period))
        ->assertForbidden();
});

test('authorized users can approve processing pay period with payroll records', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.approve']);

    [$period, $employee] = createProcessingPayrollPeriodWithRecord($company);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.approve', $period))
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period, 'tab' => 'payroll']))
        ->assertSessionHas('success');

    $period->refresh();
    expect($period->status)->toBe(PayrollPeriodStatus::Approved)
        ->and($period->approved_by)->toBe($user->id)
        ->and($period->approved_at)->not->toBeNull();

    $this->assertDatabaseHas('payroll_records', [
        'period_id' => $period->id,
        'employee_id' => $employee->id,
        'status' => 'approved',
    ]);
});

test('approve fails for draft pay period', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.approve']);

    $period = PayrollPeriod::factory()->for($company)->create([
        'status' => PayrollPeriodStatus::Draft,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.approve', $period))
        ->assertSessionHasErrors('period_id');
});

test('approve fails when no payroll records exist', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.approve']);

    $period = PayrollPeriod::factory()->for($company)->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.approve', $period))
        ->assertSessionHasErrors('period_id');
});

test('authorized users can mark approved pay period as paid', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.mark_paid']);

    [$period, $employee] = createApprovedPayrollPeriodWithRecord($company, $user);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.mark-paid', $period))
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period, 'tab' => 'payroll']))
        ->assertSessionHas('success');

    $period->refresh();
    expect($period->status)->toBe(PayrollPeriodStatus::Paid);

    $record = PayrollRecord::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $employee->id)
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->status)->toBe('paid')
        ->and($record->paid_at)->not->toBeNull();
});

test('mark paid fails for processing pay period', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.mark_paid']);

    [$period] = createProcessingPayrollPeriodWithRecord($company);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.mark-paid', $period))
        ->assertSessionHasErrors('period_id');
});

test('authorized users can cancel draft processing and approved pay periods', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.cancel']);

    $draftPeriod = PayrollPeriod::factory()->for($company)->create([
        'status' => PayrollPeriodStatus::Draft,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.cancel', $draftPeriod))
        ->assertRedirect(route('payroll.show', $draftPeriod));

    expect($draftPeriod->fresh()->status)->toBe(PayrollPeriodStatus::Cancelled);

    [$processingPeriod, $employee] = createProcessingPayrollPeriodWithRecord($company);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.cancel', $processingPeriod))
        ->assertRedirect(route('payroll.show', $processingPeriod));

    expect($processingPeriod->fresh()->status)->toBe(PayrollPeriodStatus::Cancelled);
    expect(PayrollRecord::query()->where('period_id', $processingPeriod->id)->count())->toBe(0);

    [$approvedPeriod] = createApprovedPayrollPeriodWithRecord($company, $user);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.cancel', $approvedPeriod))
        ->assertRedirect(route('payroll.show', $approvedPeriod));

    $approvedPeriod->refresh();
    expect($approvedPeriod->status)->toBe(PayrollPeriodStatus::Cancelled)
        ->and($approvedPeriod->approved_by)->toBeNull()
        ->and($approvedPeriod->approved_at)->toBeNull();
});

test('cancel fails for paid pay period', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.cancel']);

    $period = PayrollPeriod::factory()->for($company)->create([
        'status' => PayrollPeriodStatus::Paid,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.cancel', $period))
        ->assertSessionHasErrors('period_id');
});

test('revert to draft fails for cancelled pay period', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.revert_to_draft']);

    $period = PayrollPeriod::factory()->for($company)->create([
        'status' => PayrollPeriodStatus::Cancelled,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.revert-to-draft', $period))
        ->assertSessionHasErrors('period_id');
});

test('timesheets remain locked after pay period is approved', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.periods.approve',
        'payroll.crew_timesheets.update',
    ]);

    [$period, $employee] = createProcessingPayrollPeriodWithRecord($company);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'standby_days' => 2,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.approve', $period))
        ->assertRedirect();

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.store', $period), [
            'period_id' => $period->id,
            'employee_id' => $employee->id,
            'standby_days' => 4,
        ])
        ->assertSessionHasErrors('period_id');
});

test('authorized users can approve office pay period after payroll generation', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.periods.update',
        'payroll.periods.approve',
    ]);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-05',
    ]);

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $contract = EmployeeContract::factory()->create([
        'employee_id' => $employee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Office,
        'status' => 'active',
        'basic_salary' => 10000,
    ]);

    (new SyncContractSalaryComponentsFromContract)->handle($contract);

    AttendanceRecord::factory()->forEmployee($employee)->create([
        'date' => '2026-06-01',
        'status' => AttendanceRecord::STATUS_PRESENT,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertRedirect();

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.approve', $period))
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period, 'tab' => 'payroll']))
        ->assertSessionHas('success');

    $period->refresh();
    expect($period->status)->toBe(PayrollPeriodStatus::Approved);

    $this->assertDatabaseHas('payroll_records', [
        'period_id' => $period->id,
        'employee_id' => $employee->id,
        'status' => 'approved',
        'payroll_category' => PayrollCategory::Office->value,
    ]);
});

/**
 * @return array{0: PayrollPeriod, 1: Employee}
 */
function createProcessingPayrollPeriodWithRecord($company): array
{
    $period = PayrollPeriod::factory()->for($company)->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    EmployeeContract::factory()->create([
        'employee_id' => $employee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'status' => 'active',
    ]);

    PayrollRecord::factory()->for($company)->create([
        'period_id' => $period->id,
        'employee_id' => $employee->id,
    ]);

    return [$period, $employee];
}

/**
 * @return array{0: PayrollPeriod, 1: Employee}
 */
function createApprovedPayrollPeriodWithRecord($company, $approver): array
{
    [$period, $employee] = createProcessingPayrollPeriodWithRecord($company);

    $period->update([
        'status' => PayrollPeriodStatus::Approved,
        'approved_by' => $approver->id,
        'approved_at' => now(),
    ]);

    PayrollRecord::query()
        ->where('period_id', $period->id)
        ->update(['status' => 'approved']);

    return [$period->fresh(), $employee];
}
