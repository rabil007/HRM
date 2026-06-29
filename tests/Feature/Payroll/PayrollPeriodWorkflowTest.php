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
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

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

    Storage::fake('local');

    [$period, $employee] = createProcessingPayrollPeriodWithRecord($company);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.approve', $period))
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period, 'tab' => 'payroll']))
        ->assertSessionHas('success');

    $period->refresh();
    expect($period->status)->toBe(PayrollPeriodStatus::Approved)
        ->and($period->approved_by)->toBe($user->id)
        ->and($period->approved_at)->not->toBeNull();

    $record = PayrollRecord::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $employee->id)
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->status)->toBe('approved')
        ->and($record->payslip_path)->not->toBeNull()
        ->and(Storage::disk('local')->exists((string) $record->payslip_path))->toBeTrue();
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

test('approved and paid pay periods open on payroll tab by default', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.crew_timesheets.view']);

    [$approvedPeriod] = createApprovedPayrollPeriodWithRecord($company, $user);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', $approvedPeriod))
        ->assertRedirect(route('payroll.show', [
            'payrollPeriod' => $approvedPeriod,
            'tab' => 'payroll',
        ]));

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', ['payrollPeriod' => $approvedPeriod, 'tab' => 'payroll']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->where('tab', 'payroll'));

    $paidPeriod = PayrollPeriod::factory()->for($company)->create([
        'status' => PayrollPeriodStatus::Paid,
        'payroll_category' => PayrollCategory::Office,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', $paidPeriod))
        ->assertRedirect(route('payroll.show', [
            'payrollPeriod' => $paidPeriod,
            'tab' => 'payroll',
        ]));
});

test('approved pay period show includes payslip and wps delivery props', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.view',
        'payroll.payslips.view',
        'payroll.payslips.generate',
        'payroll.payslips.email',
        'payroll.wps.view',
        'payroll.wps.export',
    ]);

    $company->update([
        'wps_mol_uid' => 'MOL-TEST-001',
        'wps_agent_code' => 'AGENT-TEST-001',
    ]);

    [$approvedPeriod] = createApprovedPayrollPeriodWithRecord($company, $user);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', ['payrollPeriod' => $approvedPeriod, 'tab' => 'payroll']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->where('payslip_summary.total', 1)
            ->where('payslip_summary.generated', 0)
            ->where('payslip_summary.pending', 1)
            ->where('permissions.payslips_view', true)
            ->where('permissions.payslips_generate', true)
            ->where('permissions.wps_view', true)
            ->where('permissions.wps_export', true)
            ->has('wps_preview')
            ->where('wps_preview.period.id', $approvedPeriod->id)
            ->where('payroll_records.0.has_payslip', false));
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

    Storage::fake('local');

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

    $record = PayrollRecord::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $employee->id)
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->status)->toBe('approved')
        ->and($record->payroll_category)->toBe(PayrollCategory::Office)
        ->and($record->payslip_path)->not->toBeNull()
        ->and(Storage::disk('local')->exists((string) $record->payslip_path))->toBeTrue();
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
