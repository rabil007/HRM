<?php

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Enums\SalaryPaymentMethod;
use App\Enums\WpsStatus;
use App\Models\Bank;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Support\Payroll\Wps\WpsExcelExporter;
use App\Support\Payroll\Wps\WpsExportPreview;
use App\Support\Payroll\Wps\WpsSifExporter;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia;
use PhpOffice\PhpSpreadsheet\IOFactory;

test('wps index includes enriched period options for the export UI', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.wps.view']);

    $approved = PayrollPeriod::factory()->for($company)->create([
        'name' => 'Approved Run',
        'status' => PayrollPeriodStatus::Approved,
    ]);

    $draft = PayrollPeriod::factory()->for($company)->create([
        'name' => 'Draft Run',
        'status' => PayrollPeriodStatus::Draft,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.wps.index', ['period_id' => $approved->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('payroll/wps')
            ->where('selected_period_id', $approved->id)
            ->has('preview')
            ->where('preview.period.id', $approved->id)
            ->has('periods', 2)
            ->where('periods.0.id', $approved->id)
            ->where('periods.0.status_label', 'Approved')
            ->where('periods.0.payroll_category_label', 'Crew')
            ->where('periods.1.id', $draft->id));
});

test('wps preview skipped rows include employee id for employee page links', function () {
    ['company' => $company] = makePayrollFixtures();

    $company->forceFill([
        'wps_mol_uid' => 'MOL-12345',
        'wps_agent_code' => 'AGENT-001',
        'wps_employer_iban' => 'AE070331234567890123456',
    ])->save();

    $period = PayrollPeriod::factory()->for($company)->create();
    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'WPS-SKIP',
    ]);

    EmployeeContract::factory()->create([
        'employee_id' => $employee->id,
        'company_id' => $company->id,
        'status' => 'active',
        'payroll_category' => PayrollCategory::Office,
        'labor_contract_id' => null,
    ]);

    PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Office,
        'status' => 'approved',
    ]);

    $preview = app(WpsExportPreview::class)->forPeriod($company, $period);

    expect($preview['skipped'])->toHaveCount(1)
        ->and($preview['skipped'][0]['employee_id'])->toBe($employee->id)
        ->and($preview['skipped'][0]['employee_name'])->toBe($employee->name);
});

test('wps preview surfaces skip reason when employer iban is missing', function () {
    ['company' => $company] = makePayrollFixtures();

    $company->forceFill([
        'wps_mol_uid' => 'MOL-12345',
        'wps_agent_code' => 'AGENT-001',
        'wps_employer_iban' => null,
    ])->save();

    $period = PayrollPeriod::factory()->for($company)->create();

    $preview = app(WpsExportPreview::class)->forPeriod($company, $period);

    expect($preview['skipped'])->toHaveCount(1)
        ->and($preview['skipped'][0]['record_id'])->toBe(0)
        ->and($preview['skipped'][0]['reason'])->toBe('Company WPS employer IBAN is missing.')
        ->and($preview['company']['wps_employer_iban'])->toBeNull();
});

test('wps preview excludes cash c3 employees with explicit skip reason', function () {
    ['company' => $company] = makePayrollFixtures();

    $company->forceFill([
        'wps_mol_uid' => 'MOL-12345',
        'wps_agent_code' => 'AGENT-001',
        'wps_employer_iban' => 'AE070331234567890123456',
    ])->save();

    $period = PayrollPeriod::factory()->for($company)->create();
    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'WPS-CASH',
        'salary_payment_method' => SalaryPaymentMethod::CashC3,
    ]);

    EmployeeContract::factory()->create([
        'employee_id' => $employee->id,
        'company_id' => $company->id,
        'status' => 'active',
        'payroll_category' => PayrollCategory::Office,
        'labor_contract_id' => '12345678901234',
    ]);

    PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Office,
        'status' => 'approved',
    ]);

    $preview = app(WpsExportPreview::class)->forPeriod($company, $period);

    expect($preview['eligible_count'])->toBe(0)
        ->and($preview['skipped'])->toHaveCount(1)
        ->and($preview['skipped'][0]['employee_id'])->toBe($employee->id)
        ->and($preview['skipped'][0]['reason'])->toBe('Salary paid via C3 — excluded from WPS.');
});

test('wps export downloads sif file and marks records submitted', function () {
    Carbon::setTestNow(Carbon::parse('2026-07-06 02:47:10', 'Asia/Dubai'));

    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    $company->forceFill([
        'timezone' => 'Asia/Dubai',
        'wps_mol_uid' => 'MOL-12345',
        'wps_agent_code' => 'AGENT-001',
        'wps_employer_iban' => 'AE070331234567890123456',
    ])->save();

    grantCompanyPermissions($user, $company, ['payroll.wps.export']);

    $period = PayrollPeriod::factory()->for($company)->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'WPS-001',
    ]);

    EmployeeContract::factory()->create([
        'employee_id' => $employee->id,
        'company_id' => $company->id,
        'status' => 'active',
        'payroll_category' => PayrollCategory::Office,
        'labor_contract_id' => '12345678901234',
    ]);

    $bank = Bank::query()->create([
        'name' => 'WPS Bank',
        'uae_routing_code_agent_id' => '987654',
        'is_active' => true,
    ]);

    EmployeeBankAccount::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'bank_id' => $bank->id,
        'iban' => 'AE070331234567890123456',
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

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.wps.export'), [
            'period_id' => $period->id,
            'format' => 'sif',
        ])
        ->assertOk()
        ->assertHeader('content-disposition', 'attachment; filename=MOL-12345260706024710.sif');

    $record->refresh();

    expect($record->wps_status)->toBe(WpsStatus::Submitted)
        ->and($record->wps_reference)->not->toBeNull()
        ->and($record->wps_submitted_at)->not->toBeNull();

    Carbon::setTestNow();
});

test('wps export can be limited to selected payroll record ids', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    $company->forceFill([
        'wps_mol_uid' => 'MOL-12345',
        'wps_agent_code' => 'AGENT-001',
        'wps_employer_iban' => 'AE070331234567890123456',
    ])->save();

    grantCompanyPermissions($user, $company, ['payroll.wps.export']);

    $period = PayrollPeriod::factory()->for($company)->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
    ]);

    $bank = Bank::query()->create([
        'name' => 'WPS Bank',
        'uae_routing_code_agent_id' => '987654',
        'is_active' => true,
    ]);

    $firstEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'WPS-010',
    ]);

    EmployeeContract::factory()->create([
        'employee_id' => $firstEmployee->id,
        'company_id' => $company->id,
        'status' => 'active',
        'payroll_category' => PayrollCategory::Office,
        'labor_contract_id' => '12345678901234',
    ]);

    EmployeeBankAccount::query()->create([
        'company_id' => $company->id,
        'employee_id' => $firstEmployee->id,
        'bank_id' => $bank->id,
        'iban' => 'AE070331234567890123456',
        'account_name' => $firstEmployee->name,
        'is_primary' => true,
    ]);

    $secondEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'WPS-011',
    ]);

    EmployeeContract::factory()->create([
        'employee_id' => $secondEmployee->id,
        'company_id' => $company->id,
        'status' => 'active',
        'payroll_category' => PayrollCategory::Office,
        'labor_contract_id' => '98765432109876',
    ]);

    EmployeeBankAccount::query()->create([
        'company_id' => $company->id,
        'employee_id' => $secondEmployee->id,
        'bank_id' => $bank->id,
        'iban' => 'AE070331234567890123457',
        'account_name' => $secondEmployee->name,
        'is_primary' => true,
    ]);

    $firstRecord = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $firstEmployee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Office,
        'net_salary' => 5000,
        'status' => 'approved',
        'working_days' => 30,
        'present_days' => 30,
    ]);

    $secondRecord = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $secondEmployee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Office,
        'net_salary' => 6000,
        'status' => 'approved',
        'working_days' => 30,
        'present_days' => 30,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.wps.export'), [
            'period_id' => $period->id,
            'format' => 'sif',
            'record_ids' => [$firstRecord->id],
        ])
        ->assertOk()
        ->assertHeader('content-disposition');

    $firstRecord->refresh();
    $secondRecord->refresh();

    expect($firstRecord->wps_status)->toBe(WpsStatus::Submitted)
        ->and($secondRecord->wps_status)->toBeNull();
});

test('wps export downloads excel file in company format layout', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    Carbon::setTestNow(Carbon::parse('2026-07-06 02:47:10', 'Asia/Dubai'));

    $company->forceFill([
        'wps_mol_uid' => '0000001194930',
        'wps_agent_code' => '600310101',
        'wps_employer_iban' => 'AE140260001015853434101',
        'timezone' => 'Asia/Dubai',
    ])->save();

    grantCompanyPermissions($user, $company, ['payroll.wps.export']);

    $period = PayrollPeriod::factory()->for($company)->create([
        'start_date' => '2026-02-01',
        'end_date' => '2026-05-31',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
    ]);

    EmployeeContract::factory()->create([
        'employee_id' => $employee->id,
        'company_id' => $company->id,
        'status' => 'active',
        'payroll_category' => PayrollCategory::Office,
        'labor_contract_id' => '784198015428794',
    ]);

    $bank = Bank::query()->create([
        'name' => 'WPS Excel Bank',
        'uae_routing_code_agent_id' => '302620122',
        'is_active' => true,
    ]);

    EmployeeBankAccount::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'bank_id' => $bank->id,
        'iban' => 'AE140260001015853434101',
        'account_name' => $employee->name,
        'is_primary' => true,
    ]);

    PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Office,
        'net_salary' => 87426,
        'status' => 'approved',
        'working_days' => 120,
        'present_days' => 120,
    ]);

    $response = $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.wps.export'), [
            'period_id' => $period->id,
            'format' => 'xlsx',
        ]);

    $response
        ->assertOk()
        ->assertHeader('content-disposition', 'attachment; filename=0000001194930260706024710.xlsx');

    expect($response->headers->get('content-type'))
        ->toContain('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $tempPath = tempnam(sys_get_temp_dir(), 'wps-xlsx-test-').'.xlsx';
    file_put_contents($tempPath, $response->streamedContent());

    $sheet = IOFactory::load($tempPath)->getActiveSheet();

    expect($sheet->getCell('A1')->getValue())->toBe('EDR')
        ->and((string) $sheet->getCell('B1')->getValue())->toBe('784198015428794')
        ->and($sheet->getCell('D1')->getValue())->toBe('AE140260001015853434101')
        ->and($sheet->getCell('E1')->getValue())->toBe('2026-02-01')
        ->and($sheet->getCell('F1')->getValue())->toBe('2026-05-31')
        ->and((int) $sheet->getCell('G1')->getValue())->toBe(120)
        ->and((int) $sheet->getCell('H1')->getValue())->toBe(87426)
        ->and((int) $sheet->getCell('I1')->getValue())->toBe(0)
        ->and((int) $sheet->getCell('J1')->getValue())->toBe(0)
        ->and($sheet->getCell('K1')->getValue())->toBe($employee->name)
        ->and($sheet->getCell('A2')->getValue())->toBe('SCR')
        ->and((string) $sheet->getCell('B2')->getValue())->toBe('0000001194930')
        ->and((int) $sheet->getCell('H2')->getValue())->toBe(87426)
        ->and($sheet->getCell('J2')->getValue())->toBe('AE140260001015853434101');

    @unlink($tempPath);

    Carbon::setTestNow();
});

test('wps sif exporter builds scr and edr lines', function () {
    ['company' => $company] = makePayrollFixtures();

    $company->forceFill([
        'wps_mol_uid' => 'MOL-999',
        'wps_agent_code' => 'AGENT-999',
        'timezone' => 'Asia/Dubai',
    ])->save();

    $period = PayrollPeriod::factory()->for($company)->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
    ]);

    EmployeeContract::factory()->create([
        'employee_id' => $employee->id,
        'company_id' => $company->id,
        'status' => 'active',
        'payroll_category' => PayrollCategory::Office,
        'labor_contract_id' => '11112222333344',
    ]);

    $bank = Bank::query()->create([
        'name' => 'Export Bank',
        'uae_routing_code_agent_id' => '555666',
        'is_active' => true,
    ]);

    EmployeeBankAccount::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'bank_id' => $bank->id,
        'iban' => 'AE123456789012345678901',
        'account_name' => $employee->name,
        'is_primary' => true,
    ]);

    $record = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'basic_salary' => 3000,
        'housing_allowance' => 500,
        'net_salary' => 3500,
        'status' => 'approved',
        'present_days' => 31,
    ]);

    $content = app(WpsSifExporter::class)->export(
        $company,
        $period,
        collect([$record]),
        'REF-001',
    );

    expect($content)
        ->toContain('SCR,MOL-999,AGENT-999')
        ->toContain('EDR,11112222333344,555666,AE123456789012345678901')
        ->toContain('3500.00');
});

test('wps excel export outputs crew leave days in column j', function () {
    ['company' => $company] = makePayrollFixtures();

    Carbon::setTestNow(Carbon::parse('2026-07-06 02:47:10', 'Asia/Dubai'));

    $company->forceFill([
        'wps_mol_uid' => '0000001194930',
        'wps_agent_code' => '600310101',
        'wps_employer_iban' => 'AE140260001015853434101',
        'timezone' => 'Asia/Dubai',
    ])->save();

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
    ]);

    EmployeeContract::factory()->create([
        'employee_id' => $employee->id,
        'company_id' => $company->id,
        'status' => 'active',
        'payroll_category' => PayrollCategory::Crew,
        'labor_contract_id' => '784198015428794',
    ]);

    $bank = Bank::query()->create([
        'name' => 'WPS Crew Bank',
        'uae_routing_code_agent_id' => '302620122',
        'is_active' => true,
    ]);

    EmployeeBankAccount::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'bank_id' => $bank->id,
        'iban' => 'AE140260001015853434101',
        'account_name' => $employee->name,
        'is_primary' => true,
    ]);

    $record = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Crew,
        'net_salary' => 0,
        'status' => 'approved',
        'working_days' => 30,
        'present_days' => 0,
        'leave_days' => 30,
        'calculation_breakdown' => [
            'rates' => ['basic_daily' => 150],
            'lines' => ['standby_pay' => 0, 'onsite_pay' => 0],
        ],
    ]);

    $content = app(WpsExcelExporter::class)->export(
        $company,
        $period,
        collect([$record]),
        'REF-CREW-LEAVE',
    );

    $tempPath = tempnam(sys_get_temp_dir(), 'wps-crew-leave-').'.xlsx';
    file_put_contents($tempPath, $content);

    $sheet = IOFactory::load($tempPath)->getActiveSheet();

    expect((int) $sheet->getCell('J1')->getValue())->toBe(30);

    @unlink($tempPath);

    Carbon::setTestNow();
});

test('wps preview warns when crew basic daily rate is missing', function () {
    ['company' => $company] = makePayrollFixtures();

    $company->forceFill([
        'wps_mol_uid' => 'MOL-12345',
        'wps_agent_code' => 'AGENT-001',
        'wps_employer_iban' => 'AE070331234567890123456',
    ])->save();

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'CREW-NO-RATE',
    ]);

    EmployeeContract::factory()->create([
        'employee_id' => $employee->id,
        'company_id' => $company->id,
        'status' => 'active',
        'payroll_category' => PayrollCategory::Crew,
        'labor_contract_id' => '12345678901234',
    ]);

    $bank = Bank::query()->create([
        'name' => 'WPS Bank',
        'uae_routing_code_agent_id' => '987654',
        'is_active' => true,
    ]);

    EmployeeBankAccount::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'bank_id' => $bank->id,
        'iban' => 'AE070331234567890123456',
        'account_name' => $employee->name,
        'is_primary' => true,
    ]);

    PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Crew,
        'status' => 'approved',
        'calculation_breakdown' => [
            'rates' => ['basic_daily' => 0],
            'lines' => ['standby_pay' => 0, 'onsite_pay' => 0],
        ],
    ]);

    $preview = app(WpsExportPreview::class)->forPeriod($company, $period);

    expect($preview['eligible_count'])->toBe(0)
        ->and($preview['skipped'])->toHaveCount(1)
        ->and($preview['skipped'][0]['employee_id'])->toBe($employee->id)
        ->and($preview['skipped'][0]['reason'])->toBe('Active basic daily rate is missing.');
});
