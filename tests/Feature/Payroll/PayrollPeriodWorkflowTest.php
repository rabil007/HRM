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
use Illuminate\Http\UploadedFile;
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
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period]))
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
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period]))
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

test('authorized users can mark approved pay period as paid with payment proof document', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.mark_paid', 'payroll.periods.view']);

    Storage::fake('local');

    [$period, $employee] = createApprovedPayrollPeriodWithRecord($company, $user);

    $file = UploadedFile::fake()->create('payment_receipt.pdf', 500, 'application/pdf');

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.mark-paid', $period), [
            'payment_proof' => $file,
        ])
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period]))
        ->assertSessionHas('success');

    $period->refresh();
    expect($period->status)->toBe(PayrollPeriodStatus::Paid)
        ->and($period->payment_proof_path)->not->toBeNull();

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.payment-proof', $period))
        ->assertOk();
});

test('authorized users can mark approved pay period as paid with multiple payment proof documents', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.mark_paid', 'payroll.periods.view']);

    Storage::fake('local');

    [$period, $employee] = createApprovedPayrollPeriodWithRecord($company, $user);

    $file1 = UploadedFile::fake()->create('receipt1.pdf', 500, 'application/pdf');
    $file2 = UploadedFile::fake()->create('receipt2.png', 500, 'image/png');

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.mark-paid', $period), [
            'payment_proofs' => [$file1, $file2],
        ])
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period]))
        ->assertSessionHas('success');

    $period->refresh();
    expect($period->status)->toBe(PayrollPeriodStatus::Paid)
        ->and($period->payment_proof_paths)->toHaveCount(2);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.payment-proof', ['payrollPeriod' => $period, 'index' => 1]))
        ->assertOk();
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

test('users without permission cannot revert pay period to approved', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.view']);

    $period = PayrollPeriod::factory()->for($company)->paid()->create();

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.revert-to-approved', $period))
        ->assertForbidden();
});

test('authorized users can revert paid pay period to approved', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.revert_to_approved']);

    [$period, $employee] = createProcessingPayrollPeriodWithRecord($company);

    $period->update([
        'status' => PayrollPeriodStatus::Paid,
        'payment_proof_path' => 'test-path.pdf',
    ]);

    $record = PayrollRecord::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $employee->id)
        ->first();

    $record->update([
        'status' => 'paid',
        'paid_at' => now(),
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.revert-to-approved', $period))
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period]))
        ->assertSessionHas('success');

    $period->refresh();
    $record->refresh();

    expect($period->status)->toBe(PayrollPeriodStatus::Approved)
        ->and($record->status)->toBe('approved')
        ->and($record->paid_at)->toBeNull();
});

test('revert to approved fails for non-paid pay period', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.revert_to_approved']);

    $period = PayrollPeriod::factory()->for($company)->approved()->create();

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.revert-to-approved', $period))
        ->assertSessionHasErrors('period_id');

    $period->refresh();
    expect($period->status)->toBe(PayrollPeriodStatus::Approved);
});

test('users without permission cannot revert pay period to processing', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.view']);

    [$period] = createApprovedPayrollPeriodWithRecord($company, $user);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.revert-to-processing', $period))
        ->assertForbidden();
});

test('authorized users can revert approved pay period to processing', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.revert_to_processing']);

    Storage::fake('local');

    [$period, $employee] = createApprovedPayrollPeriodWithRecord($company, $user);

    $payslipPath = "payslips/{$company->id}/{$period->id}/{$employee->employee_no}.pdf";
    Storage::disk('local')->put($payslipPath, 'payslip-content');

    $record = PayrollRecord::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $employee->id)
        ->first();

    $record->update([
        'payslip_path' => $payslipPath,
        'wps_reference' => 'WPS-123',
        'wps_agent_ref' => 'AGENT-1',
        'wps_status' => 'submitted',
        'wps_submitted_at' => now(),
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.revert-to-processing', $period))
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period]))
        ->assertSessionHas('success');

    $period->refresh();
    $record->refresh();

    expect($period->status)->toBe(PayrollPeriodStatus::Processing)
        ->and($period->approved_by)->toBeNull()
        ->and($period->approved_at)->toBeNull()
        ->and($record->status)->toBe('draft')
        ->and($record->payslip_path)->toBeNull()
        ->and($record->wps_reference)->toBeNull()
        ->and($record->wps_agent_ref)->toBeNull()
        ->and($record->wps_status)->toBeNull()
        ->and($record->wps_submitted_at)->toBeNull()
        ->and(Storage::disk('local')->exists($payslipPath))->toBeFalse();
});

test('revert to processing fails for non-approved pay period', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.revert_to_processing']);

    [$period] = createProcessingPayrollPeriodWithRecord($company);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.revert-to-processing', $period))
        ->assertSessionHasErrors('period_id');

    $period->refresh();
    expect($period->status)->toBe(PayrollPeriodStatus::Processing);
});

test('approved and paid pay periods load without tab query params', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.crew_timesheets.view']);

    [$approvedPeriod] = createApprovedPayrollPeriodWithRecord($company, $user);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', $approvedPeriod))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->where('period.status', 'approved'));

    $paidPeriod = PayrollPeriod::factory()->for($company)->create([
        'status' => PayrollPeriodStatus::Paid,
        'payroll_category' => PayrollCategory::Office,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', $paidPeriod))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->where('period.status', 'paid'));
});

test('approved pay period show includes wps delivery props', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.view',
        'payroll.wps.export',
    ]);

    $company->update([
        'wps_mol_uid' => 'MOL-TEST-001',
        'wps_agent_code' => 'AGENT-TEST-001',
    ]);

    [$approvedPeriod] = createApprovedPayrollPeriodWithRecord($company, $user);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', ['payrollPeriod' => $approvedPeriod]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->where('payslip_summary.total', 1)
            ->where('payslip_summary.generated', 0)
            ->where('payslip_summary.pending', 1)
            ->where('permissions.wps_export', true)
            ->has('wps_preview')
            ->where('wps_preview.period.id', $approvedPeriod->id)
            ->has('all_payroll_record_ids', 1)
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
        'sign_on_standby_days' => 2,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.approve', $period))
        ->assertRedirect();

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.store', $period), [
            'period_id' => $period->id,
            'employee_id' => $employee->id,
            'sign_on_standby_days' => 4,
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
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period]))
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
