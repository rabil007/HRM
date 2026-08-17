<?php

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Enums\WpsStatus;
use App\Models\Bank;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\SalaryInput;
use App\Models\User;
use App\Support\Payroll\Actions\GeneratePayslip;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

test('crew timesheet import template requires import or create permission', function (array $permissions, bool $allowed) {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, $permissions);

    $period = PayrollPeriod::factory()->for($company)->create([
        'name' => 'June 2026 Crew Auth',
    ]);

    $response = $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.timesheets.import.template', $period));

    if ($allowed) {
        $response->assertOk()->assertDownload();

        return;
    }

    $response->assertForbidden();
})->with([
    'import permission' => [['payroll.crew_timesheets.import'], true],
    'create permission' => [['payroll.crew_timesheets.create'], true],
    'view timesheets only' => [['payroll.crew_timesheets.view'], false],
    'periods view only' => [['payroll.periods.view'], false],
    'employees view only' => [['employees.view'], false],
]);

test('crew timesheet import template returns 404 for a foreign payroll period', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    ['company' => $companyB] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.crew_timesheets.import']);

    $foreignPeriod = PayrollPeriod::factory()->for($companyB)->create([
        'name' => 'Foreign Crew Template',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.timesheets.import.template', $foreignPeriod))
        ->assertNotFound();
});

test('platform access does not grant timesheet import template access', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantPlatformAccess($user, 'manage');
    $this->actingAs($user);

    $period = PayrollPeriod::factory()->for($company)->create();

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.timesheets.import.template', $period))
        ->assertForbidden();
});

test('authorized users can show and download an active-company payslip', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.records.view']);
    Storage::fake('local');

    $record = makeApprovedPayrollRecord($company, 'PAY-AUTH-001');
    app(GeneratePayslip::class)->handle($record);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.payslips.show', $record))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.payslips.download', $record))
        ->assertOk()
        ->assertHeader('content-disposition');
});

test('users without payroll read permission cannot show or download payslips', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $record = makeApprovedPayrollRecord($company, 'PAY-AUTH-403');

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.payslips.show', $record))
        ->assertForbidden();

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.payslips.download', $record))
        ->assertForbidden();
});

test('company A cannot show or download a company B payslip', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    ['company' => $companyB] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.records.view', 'payroll.periods.view']);

    $foreignRecord = makeApprovedPayrollRecord($companyB, 'PAY-B-404');

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.payslips.show', $foreignRecord))
        ->assertNotFound();

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.payslips.download', $foreignRecord))
        ->assertNotFound();
});

test('platform access does not grant payslip access', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantPlatformAccess($user, 'manage');
    $this->actingAs($user);

    $record = makeApprovedPayrollRecord($company, 'PAY-PLAT-001');

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.payslips.show', $record))
        ->assertForbidden();
});

test('users without generate permission cannot generate payslips', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.records.view']);

    $record = makeApprovedPayrollRecord($company, 'PAY-GEN-403');

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.payslips.generate'), [
            'record_ids' => [$record->id],
        ])
        ->assertForbidden();

    expect($record->fresh()->payslip_path)->toBeNull();
});

test('users without email permission cannot email payslips', function () {
    Mail::fake();

    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.payslips.generate']);

    $record = makeApprovedPayrollRecord($company, 'PAY-MAIL-403');

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.payslips.email'), [
            'record_ids' => [$record->id],
        ])
        ->assertForbidden();

    Mail::assertNothingQueued();
});

test('bulk payslip generate rejects foreign and mixed record ids without processing', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    ['company' => $companyB] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.payslips.generate']);
    Storage::fake('local');

    $recordA = makeApprovedPayrollRecord($company, 'PAY-MIX-A');
    $recordB = makeApprovedPayrollRecord($companyB, 'PAY-MIX-B');

    $this->withSession(['current_company_id' => $company->id])
        ->from(route('payroll.index'))
        ->post(route('payroll.payslips.generate'), [
            'record_ids' => [$recordB->id],
        ])
        ->assertRedirect(route('payroll.index'))
        ->assertSessionHasErrors('record_ids.0');

    $this->withSession(['current_company_id' => $company->id])
        ->from(route('payroll.index'))
        ->post(route('payroll.payslips.generate'), [
            'record_ids' => [$recordA->id, $recordB->id],
        ])
        ->assertRedirect(route('payroll.index'))
        ->assertSessionHasErrors('record_ids.1');

    expect($recordA->fresh()->payslip_path)->toBeNull()
        ->and($recordB->fresh()->payslip_path)->toBeNull();
});

test('forged company_id does not change payslip tenant context', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    ['company' => $companyB] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.payslips.generate']);
    Storage::fake('local');

    $recordA = makeApprovedPayrollRecord($company, 'PAY-FORGE-A');
    $recordB = makeApprovedPayrollRecord($companyB, 'PAY-FORGE-B');

    $this->withSession(['current_company_id' => $company->id])
        ->from(route('payroll.index'))
        ->post(route('payroll.payslips.generate'), [
            'company_id' => $companyB->id,
            'record_ids' => [$recordB->id],
        ])
        ->assertRedirect(route('payroll.index'))
        ->assertSessionHasErrors('record_ids.0');

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.payslips.generate'), [
            'company_id' => $companyB->id,
            'record_ids' => [$recordA->id],
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($recordA->fresh()->payslip_path)->not->toBeNull()
        ->and($recordB->fresh()->payslip_path)->toBeNull();
});

test('invalid payslip payload with foreign record ids does not disclose the other company', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    ['company' => $companyB] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.payslips.generate']);

    $foreignEmployee = Employee::factory()->forCompany($companyB)->create([
        'name' => 'Secret Foreign Payslip Employee',
        'employee_no' => 'PAY-SECRET-B',
    ]);
    $foreignPeriod = PayrollPeriod::factory()->for($companyB)->office()->approved()->create();
    $foreignRecord = PayrollRecord::factory()->for($companyB)->create([
        'employee_id' => $foreignEmployee->id,
        'period_id' => $foreignPeriod->id,
        'payroll_category' => PayrollCategory::Office,
        'status' => 'approved',
    ]);

    $response = $this->withSession(['current_company_id' => $company->id])
        ->from(route('payroll.index'))
        ->post(route('payroll.payslips.generate'), [
            'record_ids' => [$foreignRecord->id, 'not-an-id'],
            'period_id' => $foreignPeriod->id,
        ]);

    $response->assertRedirect(route('payroll.index'))
        ->assertSessionHasErrors();

    $content = $response->getContent();
    $sessionErrors = session('errors')?->all() ?? [];

    expect($content)->not->toContain('Secret Foreign Payslip Employee')
        ->and($content)->not->toContain($companyB->name)
        ->and(implode(' ', $sessionErrors))->not->toContain('Secret Foreign Payslip Employee')
        ->and(implode(' ', $sessionErrors))->not->toContain($companyB->name);
});

test('authorized users can export wps for the active company', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.wps.export']);

    ['period' => $period, 'record' => $record] = makeEligibleWpsExport($company, 'WPS-OK-001');

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.wps.export'), [
            'period_id' => $period->id,
            'format' => 'sif',
            'record_ids' => [$record->id],
        ])
        ->assertOk()
        ->assertHeader('content-disposition');

    expect($record->fresh()->wps_status)->toBe(WpsStatus::Submitted);
});

test('users without wps export permission cannot export', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.records.view', 'payroll.periods.view']);

    ['period' => $period, 'record' => $record] = makeEligibleWpsExport($company, 'WPS-403-001');

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.wps.export'), [
            'period_id' => $period->id,
            'format' => 'sif',
        ])
        ->assertForbidden();

    expect($record->fresh()->wps_status)->toBeNull();
});

test('wps export rejects foreign periods and mixed company record ids', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    ['company' => $companyB] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.wps.export']);

    ['period' => $periodA, 'record' => $recordA] = makeEligibleWpsExport($company, 'WPS-A-001');
    ['period' => $periodB, 'record' => $recordB] = makeEligibleWpsExport($companyB, 'WPS-B-001');

    $this->withSession(['current_company_id' => $company->id])
        ->from(route('payroll.index'))
        ->post(route('payroll.wps.export'), [
            'period_id' => $periodB->id,
            'format' => 'sif',
        ])
        ->assertRedirect(route('payroll.index'))
        ->assertSessionHasErrors('period_id');

    $this->withSession(['current_company_id' => $company->id])
        ->from(route('payroll.index'))
        ->post(route('payroll.wps.export'), [
            'period_id' => $periodA->id,
            'format' => 'sif',
            'record_ids' => [$recordB->id],
        ])
        ->assertRedirect(route('payroll.index'))
        ->assertSessionHasErrors('record_ids.0');

    $this->withSession(['current_company_id' => $company->id])
        ->from(route('payroll.index'))
        ->post(route('payroll.wps.export'), [
            'period_id' => $periodA->id,
            'format' => 'sif',
            'record_ids' => [$recordA->id, $recordB->id],
        ])
        ->assertRedirect(route('payroll.index'))
        ->assertSessionHasErrors('record_ids.1');

    expect($recordA->fresh()->wps_status)->toBeNull()
        ->and($recordB->fresh()->wps_status)->toBeNull();
});

test('platform access does not grant wps export', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantPlatformAccess($user, 'manage');
    $this->actingAs($user);

    ['period' => $period, 'record' => $record] = makeEligibleWpsExport($company, 'WPS-PLAT-001');

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.wps.export'), [
            'period_id' => $period->id,
            'format' => 'sif',
        ])
        ->assertForbidden();

    expect($record->fresh()->wps_status)->toBeNull();
});

test('forged company_id does not change wps tenant context', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    ['company' => $companyB] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.wps.export']);

    ['period' => $periodA, 'record' => $recordA] = makeEligibleWpsExport($company, 'WPS-FORGE-A');
    ['period' => $periodB, 'record' => $recordB] = makeEligibleWpsExport($companyB, 'WPS-FORGE-B');

    $this->withSession(['current_company_id' => $company->id])
        ->from(route('payroll.index'))
        ->post(route('payroll.wps.export'), [
            'company_id' => $companyB->id,
            'period_id' => $periodB->id,
            'format' => 'sif',
            'record_ids' => [$recordB->id],
        ])
        ->assertRedirect(route('payroll.index'))
        ->assertSessionHasErrors('period_id');

    expect($recordA->fresh()->wps_status)->toBeNull()
        ->and($recordB->fresh()->wps_status)->toBeNull();
});

test('company A cannot view a company B payroll period or destroy its records', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    ['company' => $companyB] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.periods.view',
        'payroll.periods.update',
    ]);

    $periodA = PayrollPeriod::factory()->for($company)->create([
        'status' => PayrollPeriodStatus::Processing,
        'name' => 'Company A Period',
    ]);
    $periodB = PayrollPeriod::factory()->for($companyB)->create([
        'status' => PayrollPeriodStatus::Processing,
        'name' => 'Company B Period',
    ]);
    $recordB = makeApprovedPayrollRecord($companyB, 'REC-B-404', $periodB);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', $periodA))
        ->assertOk();

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', $periodB))
        ->assertNotFound();

    $this->withSession(['current_company_id' => $company->id])
        ->delete(route('payroll.records.destroy', [$periodA, $recordB]))
        ->assertNotFound();

    $this->withSession(['current_company_id' => $company->id])
        ->delete(route('payroll.records.destroy', [$periodB, $recordB]))
        ->assertNotFound();

    expect(PayrollRecord::query()->whereKey($recordB->id)->exists())->toBeTrue();
});

test('dual-company user cannot act on company B payroll until switching', function () {
    ['user' => $user, 'company' => $companyA] = makePayrollFixtures();
    ['company' => $companyB] = makePayrollFixtures();

    grantCompanyPermissions($user, $companyA, [
        'payroll.periods.view',
        'payroll.periods.approve',
    ]);
    grantCompanyPermissions($user, $companyB, [
        'payroll.periods.view',
        'payroll.periods.approve',
    ]);

    $this->actingAs($user);

    $periodA = PayrollPeriod::factory()->for($companyA)->create(['name' => 'Active A Period']);
    $periodB = PayrollPeriod::factory()->for($companyB)->create(['name' => 'Inactive B Period']);

    $this->withSession(['current_company_id' => $companyA->id])
        ->get(route('payroll.show', $periodA))
        ->assertOk();

    $this->withSession(['current_company_id' => $companyA->id])
        ->get(route('payroll.show', $periodB))
        ->assertNotFound();

    $this->withSession(['current_company_id' => $companyA->id])
        ->post(route('payroll.approve', $periodB))
        ->assertNotFound();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->post('/organization/companies/switch', ['company_id' => $companyB->id])
        ->assertRedirect();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyB->id])
        ->get(route('payroll.show', $periodB))
        ->assertOk();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyB->id])
        ->get(route('payroll.show', $periodA))
        ->assertNotFound();
});

test('recalculate allows periods.recalculate or periods.update and rejects others', function (array $permissions, bool $allowed) {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, $permissions);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);
    $employee = createOfficeEmployeeWithContract($company, 'OFF-RC-AUTH', 10000, 0, 0, 0);
    PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Office,
        'gross_salary' => 10000,
        'net_salary' => 10000,
    ]);

    $response = $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.recalculate', $period));

    if ($allowed) {
        $response->assertRedirect(route('payroll.show', ['payrollPeriod' => $period]))
            ->assertSessionHas('success');

        return;
    }

    $response->assertForbidden();
})->with([
    'recalculate permission' => [['payroll.periods.recalculate'], true],
    'update permission' => [['payroll.periods.update'], true],
    'view permission only' => [['payroll.periods.view'], false],
]);

test('recalculate returns 404 for a foreign payroll period', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    ['company' => $companyB] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.recalculate', 'payroll.periods.update']);

    $foreignPeriod = PayrollPeriod::factory()->for($companyB)->office()->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.recalculate', $foreignPeriod))
        ->assertNotFound();
});

test('salary input store is forbidden without capability and tenant-safe for foreign targets', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    ['company' => $companyB] = makePayrollFixtures();
    $this->actingAs($user);

    $periodA = PayrollPeriod::factory()->for($company)->office()->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);
    $periodB = PayrollPeriod::factory()->for($companyB)->office()->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);
    $employeeA = createOfficeEmployeeWithContract($company, 'OFF-AUTH-A', 10000, 0, 0, 0);
    $employeeB = createOfficeEmployeeWithContract($companyB, 'OFF-AUTH-B', 10000, 0, 0, 0);
    $typeA = salaryInputTypeId($company, 'bonus');

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.salary-inputs.store', $periodA), [
            'employee_id' => $employeeA->id,
            'salary_input_type_id' => $typeA,
            'amount' => 100,
        ])
        ->assertForbidden();

    grantCompanyPermissions($user, $company, ['payroll.salary_inputs.create']);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.salary-inputs.store', $periodB), [
            'employee_id' => $employeeA->id,
            'salary_input_type_id' => $typeA,
            'amount' => 100,
        ])
        ->assertNotFound();

    $this->withSession(['current_company_id' => $company->id])
        ->from(route('payroll.show', $periodA))
        ->post(route('payroll.salary-inputs.store', $periodA), [
            'employee_id' => $employeeB->id,
            'salary_input_type_id' => $typeA,
            'amount' => 100,
        ])
        ->assertRedirect(route('payroll.show', $periodA))
        ->assertSessionHasErrors('employee_id');

    expect(SalaryInput::query()->where('company_id', $companyB->id)->count())->toBe(0)
        ->and(SalaryInput::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('company A cannot approve or mark paid a company B payroll period', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    ['company' => $companyB] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.periods.approve',
        'payroll.periods.mark_paid',
    ]);

    $periodB = PayrollPeriod::factory()->for($companyB)->office()->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);
    makeApprovedPayrollRecord($companyB, 'PAY-B-APPROVE', $periodB);
    $periodB->update(['status' => PayrollPeriodStatus::Processing]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.approve', $periodB))
        ->assertNotFound();

    $approvedB = PayrollPeriod::factory()->for($companyB)->office()->approved()->create();
    makeApprovedPayrollRecord($companyB, 'PAY-B-PAID', $approvedB);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.mark-paid', $approvedB))
        ->assertNotFound();

    expect($periodB->fresh()->status)->toBe(PayrollPeriodStatus::Processing)
        ->and($approvedB->fresh()->status)->toBe(PayrollPeriodStatus::Approved)
        ->and($approvedB->fresh()->payment_date)->toBeNull();
});

test('platform access without membership cannot open payroll urls', function () {
    ['company' => $company] = makePayrollFixtures();
    $platformUser = User::factory()->create(['company_id' => null]);
    grantPlatformAccess($platformUser, 'manage');

    $period = PayrollPeriod::factory()->for($company)->create();
    $record = makeApprovedPayrollRecord($company, 'PAY-PLAT-NONE');

    $this->actingAs($platformUser)
        ->withSession([])
        ->get(route('payroll.show', $period))
        ->assertForbidden();

    $this->actingAs($platformUser)
        ->withSession([])
        ->get(route('payroll.timesheets.import.template', $period))
        ->assertNotFound();

    $this->actingAs($platformUser)
        ->withSession([])
        ->get(route('payroll.payslips.show', $record))
        ->assertNotFound();

    $this->actingAs($platformUser)
        ->withSession([])
        ->post(route('payroll.wps.export'), [
            'period_id' => $period->id,
            'format' => 'sif',
        ])
        ->assertForbidden();
});

function makeApprovedPayrollRecord(Company $company, string $employeeNo, ?PayrollPeriod $period = null): PayrollRecord
{
    $period ??= PayrollPeriod::factory()->for($company)->office()->approved()->create();

    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => $employeeNo,
        'status' => 'active',
    ]);

    return PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => $period->isOffice() ? PayrollCategory::Office : PayrollCategory::Crew,
        'status' => 'approved',
        'net_salary' => 5000,
    ]);
}

/**
 * @return array{period: PayrollPeriod, record: PayrollRecord, employee: Employee}
 */
function makeEligibleWpsExport(Company $company, string $employeeNo): array
{
    $company->forceFill([
        'timezone' => 'Asia/Dubai',
        'wps_mol_uid' => 'MOL-'.$company->id,
        'wps_agent_code' => 'AGENT-'.$company->id,
        'wps_employer_iban' => 'AE07033123456789012'.str_pad((string) $company->id, 4, '0', STR_PAD_LEFT),
    ])->save();

    $period = PayrollPeriod::factory()->for($company)->office()->approved()->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => $employeeNo,
    ]);

    EmployeeContract::factory()->create([
        'employee_id' => $employee->id,
        'company_id' => $company->id,
        'status' => 'active',
        'payroll_category' => PayrollCategory::Office,
        'labor_contract_id' => str_pad((string) $employee->id, 14, '1', STR_PAD_LEFT),
    ]);

    $bank = Bank::query()->create([
        'name' => 'WPS Auth Bank '.$company->id.' '.$employeeNo,
        'uae_routing_code_agent_id' => str_pad((string) $company->id, 6, '9', STR_PAD_LEFT),
        'is_active' => true,
    ]);

    EmployeeBankAccount::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'bank_id' => $bank->id,
        'iban' => 'AE07033123456789'.str_pad((string) $employee->id, 6, '0', STR_PAD_LEFT),
        'account_name' => $employee->name,
        'is_primary' => true,
    ]);

    $record = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Office,
        'basic_salary' => 4000,
        'housing_allowance' => 1000,
        'net_salary' => 5000,
        'status' => 'approved',
        'working_days' => 30,
        'present_days' => 30,
    ]);

    return compact('period', 'record', 'employee');
}
