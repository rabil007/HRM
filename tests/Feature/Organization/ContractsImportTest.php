<?php

use App\Enums\PayrollCategory;
use App\Imports\ContractsImport;
use App\Models\Company;
use App\Models\CompanyVisaType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\User;
use App\Support\Contracts\ContractImportTemplateExporter;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

test('users without contracts import permission cannot download template', function () {
    ['user' => $user, 'company' => $company] = makeContractsImportFixtures();

    grantCompanyPermissions($user, $company, ['contracts.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.contracts.import.template', [
            'payroll_category' => PayrollCategory::Office->value,
        ]))
        ->assertForbidden();
});

test('office contracts template excludes crew-only employees', function () {
    ['user' => $user, 'company' => $company, 'crewEmployee' => $crewEmployee] = makeContractsImportFixtures();

    grantCompanyPermissions($user, $company, ['contracts.view', 'contracts.import']);

    $result = app(ContractImportTemplateExporter::class)->export($company->id, PayrollCategory::Office);
    $sheet = IOFactory::load($result['path'])->getSheetByName('Office Contracts');

    $employeeNumbers = [];

    for ($row = ContractsImport::DATA_START_ROW; $row <= $sheet->getHighestDataRow(); $row++) {
        $employeeNumbers[] = (string) $sheet->getCell('A'.$row)->getValue();
    }

    expect($employeeNumbers)->not->toContain($crewEmployee->employee_no);

    @unlink($result['path']);
});

test('crew contracts template excludes office-only employees', function () {
    ['user' => $user, 'company' => $company, 'officeEmployee' => $officeEmployee] = makeContractsImportFixtures();

    grantCompanyPermissions($user, $company, ['contracts.view', 'contracts.import']);

    $result = app(ContractImportTemplateExporter::class)->export($company->id, PayrollCategory::Crew);
    $sheet = IOFactory::load($result['path'])->getSheetByName('Crew Contracts');

    $employeeNumbers = [];

    for ($row = ContractsImport::DATA_START_ROW; $row <= $sheet->getHighestDataRow(); $row++) {
        $employeeNumbers[] = (string) $sheet->getCell('A'.$row)->getValue();
    }

    expect($employeeNumbers)->not->toContain($officeEmployee->employee_no);

    @unlink($result['path']);
});

test('office contracts template includes office department employees without contracts', function () {
    ['user' => $user, 'company' => $company, 'blankEmployee' => $blankEmployee] = makeContractsImportFixtures();

    grantCompanyPermissions($user, $company, ['contracts.view', 'contracts.import']);

    $result = app(ContractImportTemplateExporter::class)->export($company->id, PayrollCategory::Office);
    $sheet = IOFactory::load($result['path'])->getSheetByName('Office Contracts');

    $employeeNumbers = [];

    for ($row = ContractsImport::DATA_START_ROW; $row <= $sheet->getHighestDataRow(); $row++) {
        $employeeNumbers[] = (string) $sheet->getCell('A'.$row)->getValue();
    }

    expect($employeeNumbers)->toContain($blankEmployee->employee_no);

    @unlink($result['path']);
});

test('office contracts template lists active employees and prefills matching office contracts', function () {
    ['user' => $user, 'company' => $company, 'officeEmployee' => $officeEmployee] = makeContractsImportFixtures();

    grantCompanyPermissions($user, $company, ['contracts.view', 'contracts.import']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.contracts.import.template', [
            'payroll_category' => PayrollCategory::Office->value,
        ]))
        ->assertOk()
        ->assertDownload('office-contracts-template.xlsx');

    $result = app(ContractImportTemplateExporter::class)->export($company->id, PayrollCategory::Office);
    $sheet = IOFactory::load($result['path'])->getSheetByName('Office Contracts');

    expect($sheet)->not->toBeNull()
        ->and($sheet->getHighestDataRow())->toBe(3);

    $rowsByEmployeeNo = [];

    for ($row = ContractsImport::DATA_START_ROW; $row <= $sheet->getHighestDataRow(); $row++) {
        $rowsByEmployeeNo[(string) $sheet->getCell('A'.$row)->getValue()] = [
            'start_date' => $sheet->getCell('C'.$row)->getValue(),
        ];
    }

    expect($rowsByEmployeeNo[$officeEmployee->employee_no]['start_date'])->toBe('2025-01-01');

    @unlink($result['path']);
});

test('crew contracts template includes crew allowance columns', function () {
    ['user' => $user, 'company' => $company] = makeContractsImportFixtures();

    grantCompanyPermissions($user, $company, ['contracts.view', 'contracts.import']);

    $result = app(ContractImportTemplateExporter::class)->export($company->id, PayrollCategory::Crew);
    $sheet = IOFactory::load($result['path'])->getSheetByName('Crew Contracts');

    expect($sheet)->not->toBeNull()
        ->and($sheet->getCell('H1')->getValue())->toBe('Supplementary Allowance')
        ->and($sheet->getCell('I1')->getValue())->toBe('Site Allowance');

    @unlink($result['path']);
});

test('contracts import preview rejects unknown employee numbers', function () {
    ['user' => $user, 'company' => $company] = makeContractsImportFixtures();

    grantCompanyPermissions($user, $company, ['contracts.view', 'contracts.import']);

    $file = makeContractsImportFile(PayrollCategory::Office, [
        [
            'employee_no' => 'UNKNOWN-999',
            'name' => 'Unknown',
            'start_date' => '2026-01-01',
            'status' => 'active',
        ],
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.contracts.import.preview'), [
            'payroll_category' => PayrollCategory::Office->value,
            'file' => $file,
        ])
        ->assertOk()
        ->assertJsonPath('summary.invalid', 1)
        ->assertJsonPath('rows.0.errors.0.field', 'employee_no');
});

test('contracts import preview flags invalid status values', function () {
    ['user' => $user, 'company' => $company, 'blankEmployee' => $blankEmployee] = makeContractsImportFixtures();

    grantCompanyPermissions($user, $company, ['contracts.view', 'contracts.import']);

    $file = makeContractsImportFile(PayrollCategory::Office, [
        [
            'employee_no' => $blankEmployee->employee_no,
            'name' => $blankEmployee->name,
            'start_date' => '2026-01-01',
            'status' => 'draft',
        ],
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.contracts.import.preview'), [
            'payroll_category' => PayrollCategory::Office->value,
            'file' => $file,
        ])
        ->assertOk()
        ->assertJsonPath('summary.invalid', 1)
        ->assertJsonPath('rows.0.errors.0.field', 'status');
});

test('contracts import creates new contracts and skips empty rows', function () {
    ['user' => $user, 'company' => $company, 'blankEmployee' => $blankEmployee, 'officeEmployee' => $officeEmployee] = makeContractsImportFixtures();

    grantCompanyPermissions($user, $company, ['contracts.view', 'contracts.import']);

    $file = makeContractsImportFile(PayrollCategory::Office, [
        [
            'employee_no' => $blankEmployee->employee_no,
            'name' => $blankEmployee->name,
            'start_date' => '2026-02-01',
            'end_date' => '2027-02-01',
            'status' => 'active',
            'basic_salary' => 7500,
        ],
        [
            'employee_no' => $officeEmployee->employee_no,
            'name' => $officeEmployee->name,
        ],
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.contracts.import'), [
            'payroll_category' => PayrollCategory::Office->value,
            'file' => $file,
        ])
        ->assertRedirect(route('organization.contracts'))
        ->assertSessionHas('success');

    $created = EmployeeContract::query()
        ->where('employee_id', $blankEmployee->id)
        ->where('status', 'active')
        ->first();

    expect($created)->not->toBeNull()
        ->and($created->payroll_category)->toBe(PayrollCategory::Office)
        ->and((string) $created->basic_salary)->toBe('7500.00');
});

test('contracts import updates existing contracts by employee number', function () {
    ['user' => $user, 'company' => $company, 'officeEmployee' => $officeEmployee, 'officeContract' => $officeContract] = makeContractsImportFixtures();

    grantCompanyPermissions($user, $company, ['contracts.view', 'contracts.import']);

    $file = makeContractsImportFile(PayrollCategory::Office, [
        [
            'employee_no' => $officeEmployee->employee_no,
            'name' => $officeEmployee->name,
            'start_date' => '2025-06-01',
            'status' => 'active',
            'basic_salary' => 9100,
        ],
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.contracts.import'), [
            'payroll_category' => PayrollCategory::Office->value,
            'file' => $file,
        ])
        ->assertRedirect(route('organization.contracts'));

    expect((string) $officeContract->fresh()->basic_salary)->toBe('9100.00');
});

test('contracts import ends other active contracts when importing an active contract', function () {
    ['user' => $user, 'company' => $company, 'officeEmployee' => $officeEmployee, 'officeContract' => $officeContract] = makeContractsImportFixtures();

    $otherActive = EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $officeEmployee->id,
        'payroll_category' => PayrollCategory::Office->value,
        'start_date' => '2024-01-01',
        'status' => 'active',
        'basic_salary' => 4000,
    ]);

    grantCompanyPermissions($user, $company, ['contracts.view', 'contracts.import']);

    $file = makeContractsImportFile(PayrollCategory::Office, [
        [
            'employee_no' => $officeEmployee->employee_no,
            'name' => $officeEmployee->name,
            'start_date' => '2025-01-01',
            'status' => 'active',
            'basic_salary' => 8000,
        ],
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.contracts.import'), [
            'payroll_category' => PayrollCategory::Office->value,
            'file' => $file,
        ])
        ->assertRedirect(route('organization.contracts'));

    expect($otherActive->fresh()->status)->toBe('ended')
        ->and($officeContract->fresh()->status)->toBe('active');
});

test('contracts import resolves company visa type by name', function () {
    ['user' => $user, 'company' => $company, 'blankEmployee' => $blankEmployee] = makeContractsImportFixtures();

    $visaType = CompanyVisaType::query()->create([
        'name' => 'Import Visa Co '.uniqid(),
        'is_active' => true,
    ]);

    grantCompanyPermissions($user, $company, ['contracts.view', 'contracts.import']);

    $file = makeContractsImportFile(PayrollCategory::Office, [
        [
            'employee_no' => $blankEmployee->employee_no,
            'name' => $blankEmployee->name,
            'start_date' => '2026-02-01',
            'status' => 'active',
            'basic_salary' => 7500,
            'company_visa_type' => $visaType->name,
        ],
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.contracts.import'), [
            'payroll_category' => PayrollCategory::Office->value,
            'file' => $file,
        ])
        ->assertRedirect(route('organization.contracts'))
        ->assertSessionHas('success');

    $created = EmployeeContract::query()
        ->where('employee_id', $blankEmployee->id)
        ->where('status', 'active')
        ->first();

    expect($created)->not->toBeNull()
        ->and($created->company_visa_type_id)->toBe($visaType->id);
});

test('contracts import rejects unknown company visa type names', function () {
    ['user' => $user, 'company' => $company, 'blankEmployee' => $blankEmployee] = makeContractsImportFixtures();

    grantCompanyPermissions($user, $company, ['contracts.view', 'contracts.import']);

    $file = makeContractsImportFile(PayrollCategory::Office, [
        [
            'employee_no' => $blankEmployee->employee_no,
            'name' => $blankEmployee->name,
            'start_date' => '2026-02-01',
            'status' => 'active',
            'basic_salary' => 7500,
            'company_visa_type' => 'Totally Unknown Visa Co',
        ],
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.contracts.import.preview'), [
            'payroll_category' => PayrollCategory::Office->value,
            'file' => $file,
        ])
        ->assertOk()
        ->assertJsonPath('summary.invalid', 1)
        ->assertJsonPath('rows.0.errors.0.field', 'company_visa_type');
});

test('contracts import rejects inactive company visa type names', function () {
    ['user' => $user, 'company' => $company, 'blankEmployee' => $blankEmployee] = makeContractsImportFixtures();

    $inactiveVisaType = CompanyVisaType::query()->create([
        'name' => 'Inactive Visa Co '.uniqid(),
        'is_active' => false,
    ]);

    grantCompanyPermissions($user, $company, ['contracts.view', 'contracts.import']);

    $file = makeContractsImportFile(PayrollCategory::Office, [
        [
            'employee_no' => $blankEmployee->employee_no,
            'name' => $blankEmployee->name,
            'start_date' => '2026-02-01',
            'status' => 'active',
            'basic_salary' => 7500,
            'company_visa_type' => $inactiveVisaType->name,
        ],
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.contracts.import.preview'), [
            'payroll_category' => PayrollCategory::Office->value,
            'file' => $file,
        ])
        ->assertOk()
        ->assertJsonPath('summary.invalid', 1)
        ->assertJsonPath('rows.0.errors.0.field', 'company_visa_type');
});

test('crew monthly import template uses monthly allowances and preserves monthly EOS', function () {
    ['company' => $company, 'crewEmployee' => $crewEmployee] = makeContractsImportFixtures();

    EmployeeContract::query()->where('employee_id', $crewEmployee->id)->update([
        'salary_structure' => 'monthly',
        'basic_salary' => 55000,
        'housing_allowance' => 2000,
        'transport_allowance' => 500,
        'eos_monthly_amount' => 1500,
    ]);

    $result = app(ContractImportTemplateExporter::class)->export($company->id, PayrollCategory::Crew, 'monthly');
    $sheet = IOFactory::load($result['path'])->getSheetByName('Crew Monthly Contracts');

    expect($sheet)->not->toBeNull()
        ->and((string) $sheet->getCell('K1')->getValue())->toBe('EOS (Monthly)')
        ->and((float) $sheet->getCell('G2')->getValue())->toBe(55000.0)
        ->and((float) $sheet->getCell('H2')->getValue())->toBe(2000.0)
        ->and((float) $sheet->getCell('K2')->getValue())->toBe(1500.0);

    @unlink($result['path']);
});

test('daily crew import cannot silently overwrite an existing monthly crew contract', function () {
    ['user' => $user, 'company' => $company, 'crewEmployee' => $crewEmployee] = makeContractsImportFixtures();

    EmployeeContract::query()->where('employee_id', $crewEmployee->id)->update([
        'salary_structure' => 'monthly',
        'basic_salary' => 55000,
        'housing_allowance' => 2000,
    ]);

    grantCompanyPermissions($user, $company, ['contracts.view', 'contracts.import']);

    $file = makeContractsImportFile(PayrollCategory::Crew, [
        [
            'employee_no' => $crewEmployee->employee_no,
            'name' => $crewEmployee->name,
            'start_date' => '2025-01-01',
            'status' => 'active',
            'basic_salary' => 200,
        ],
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.contracts.import.preview'), [
            'payroll_category' => 'crew',
            'salary_structure' => 'daily',
            'file' => $file,
        ])
        ->assertOk()
        ->assertJsonPath('summary.invalid', 1)
        ->assertJsonPath('rows.0.errors.0.field', 'salary_structure');
});

test('monthly crew import previews effective values and keeps blank metadata and EOS on update', function () {
    ['user' => $user, 'company' => $company, 'crewEmployee' => $crewEmployee] = makeContractsImportFixtures();

    $contract = EmployeeContract::query()
        ->where('employee_id', $crewEmployee->id)
        ->where('status', 'active')
        ->firstOrFail();
    $contract->update([
        'salary_structure' => 'monthly',
        'start_date' => '2025-01-01',
        'end_date' => '2027-12-31',
        'labor_contract_id' => 'PRESERVE-MONTHLY-1',
        'basic_salary' => 55000,
        'housing_allowance' => 2000,
        'transport_allowance' => 500,
        'other_allowances' => 300,
        'eos_monthly_amount' => 1500,
        'note' => 'Keep existing note',
    ]);

    grantCompanyPermissions($user, $company, ['contracts.view', 'contracts.import']);

    $file = makeContractsImportFile(PayrollCategory::Crew, [[
        'employee_no' => $crewEmployee->employee_no,
        'name' => $crewEmployee->name,
        'basic_salary' => 56000,
        'status' => null,
        'start_date' => null,
        'housing_allowance' => null,
        'eos_monthly_amount' => null,
    ]], 'monthly');

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.contracts.import.preview'), [
            'payroll_category' => 'crew',
            'salary_structure' => 'monthly',
            'file' => $file,
        ])
        ->assertOk()
        ->assertJsonPath('summary.importable', 1)
        ->assertJsonPath('rows.0.start_date', '2025-01-01')
        ->assertJsonPath('rows.0.end_date', '2027-12-31')
        ->assertJsonPath('rows.0.labor_contract_id', 'PRESERVE-MONTHLY-1')
        ->assertJsonPath('rows.0.total_salary', 60300)
        ->assertJsonPath('rows.0.eos_monthly_amount', '1500.00');

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.contracts.import'), [
            'payroll_category' => 'crew',
            'salary_structure' => 'monthly',
            'file' => $file,
        ])
        ->assertRedirect(route('organization.contracts'));

    $saved = $contract->fresh();
    expect((float) $saved->basic_salary)->toBe(56000.0)
        ->and((float) $saved->housing_allowance)->toBe(2000.0)
        ->and((float) $saved->eos_monthly_amount)->toBe(1500.0)
        ->and($saved->end_date?->toDateString())->toBe('2027-12-31')
        ->and($saved->labor_contract_id)->toBe('PRESERVE-MONTHLY-1')
        ->and($saved->note)->toBe('Keep existing note');
});

/**
 * @return array{
 *     user: User,
 *     company: Company,
 *     officeEmployee: Employee,
 *     blankEmployee: Employee,
 *     crewEmployee: Employee,
 *     officeContract: EmployeeContract
 * }
 */
function makeContractsImportFixtures(): array
{
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'CIM',
        'name' => 'Contracts Import Land',
        'dial_code' => '+994',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CIM',
        'name' => 'Contracts Import Currency',
        'symbol' => 'C$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Contracts Import Co',
        'slug' => 'contracts-import-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $officeDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Office',
        'code' => 'OFF',
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);

    $marineDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Marine',
        'code' => 'MAR',
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);

    $officeEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'EMP-OFF-01',
        'name' => 'Office Worker',
        'department_id' => $officeDepartment->id,
        'status' => 'active',
    ]);
    EmployeeContract::query()->where('employee_id', $officeEmployee->id)->delete();

    $blankEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'EMP-BLANK-02',
        'name' => 'Blank Worker',
        'department_id' => $officeDepartment->id,
        'status' => 'active',
    ]);
    EmployeeContract::query()->where('employee_id', $blankEmployee->id)->delete();

    $officeContract = EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $officeEmployee->id,
        'payroll_category' => PayrollCategory::Office->value,
        'start_date' => '2025-01-01',
        'end_date' => '2025-12-31',
        'status' => 'active',
        'basic_salary' => 6000,
    ]);

    $crewEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'EMP-CREW-03',
        'name' => 'Crew Worker',
        'department_id' => $marineDepartment->id,
        'status' => 'active',
    ]);
    EmployeeContract::query()->where('employee_id', $crewEmployee->id)->delete();

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $crewEmployee->id,
        'payroll_category' => PayrollCategory::Crew->value,
        'start_date' => '2025-01-01',
        'status' => 'active',
        'basic_salary' => 5000,
    ]);

    return compact('user', 'company', 'officeEmployee', 'blankEmployee', 'crewEmployee', 'officeContract');
}

/**
 * @param  list<array<string, mixed>>  $rows
 */
function makeContractsImportFile(PayrollCategory $payrollCategory, array $rows, string $salaryStructure = 'daily'): UploadedFile
{
    $import = app(ContractsImport::class);
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle($import->sheetName($payrollCategory, $salaryStructure));

    foreach ($import->headers($payrollCategory, $salaryStructure) as $columnIndex => $header) {
        $sheet->setCellValueByColumnAndRow($columnIndex + 1, 1, $header);
    }

    $rowNumber = ContractsImport::DATA_START_ROW;

    foreach ($rows as $row) {
        $sheet->setCellValueByColumnAndRow(1, $rowNumber, $row['employee_no'] ?? null);
        $sheet->setCellValueByColumnAndRow(2, $rowNumber, $row['name'] ?? null);
        $sheet->setCellValueByColumnAndRow(3, $rowNumber, $row['start_date'] ?? null);
        $sheet->setCellValueByColumnAndRow(4, $rowNumber, $row['end_date'] ?? null);
        $sheet->setCellValueByColumnAndRow(5, $rowNumber, $row['labor_contract_id'] ?? null);
        $sheet->setCellValueByColumnAndRow(6, $rowNumber, $row['status'] ?? null);
        $sheet->setCellValueByColumnAndRow(7, $rowNumber, $row['basic_salary'] ?? null);

        if ($payrollCategory === PayrollCategory::Office) {
            $sheet->setCellValueByColumnAndRow(8, $rowNumber, $row['housing_allowance'] ?? null);
            $sheet->setCellValueByColumnAndRow(9, $rowNumber, $row['transport_allowance'] ?? null);
            $sheet->setCellValueByColumnAndRow(10, $rowNumber, $row['other_allowances'] ?? null);
            $sheet->setCellValueByColumnAndRow(11, $rowNumber, $row['note'] ?? null);
            $sheet->setCellValueByColumnAndRow(12, $rowNumber, $row['company_visa_type'] ?? null);
        } elseif ($salaryStructure === 'monthly') {
            $sheet->setCellValueByColumnAndRow(8, $rowNumber, $row['housing_allowance'] ?? null);
            $sheet->setCellValueByColumnAndRow(9, $rowNumber, $row['transport_allowance'] ?? null);
            $sheet->setCellValueByColumnAndRow(10, $rowNumber, $row['other_allowances'] ?? null);
            $sheet->setCellValueByColumnAndRow(11, $rowNumber, $row['eos_monthly_amount'] ?? null);
            $sheet->setCellValueByColumnAndRow(12, $rowNumber, $row['note'] ?? null);
            $sheet->setCellValueByColumnAndRow(13, $rowNumber, $row['company_visa_type'] ?? null);
        } else {
            $sheet->setCellValueByColumnAndRow(8, $rowNumber, $row['supplementary_allowance'] ?? null);
            $sheet->setCellValueByColumnAndRow(9, $rowNumber, $row['site_allowance'] ?? null);
            $sheet->setCellValueByColumnAndRow(10, $rowNumber, $row['note'] ?? null);
            $sheet->setCellValueByColumnAndRow(11, $rowNumber, $row['company_visa_type'] ?? null);
        }

        $rowNumber++;
    }

    $path = storage_path('app/temp/'.uniqid('contracts-import-test-', true).'.xlsx');
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    (new Xlsx($spreadsheet))->save($path);

    return new UploadedFile($path, 'contracts-import.xlsx', null, null, true);
}

test('previously downloaded monthly crew EOS templates remain importable', function () {
    $file = makeContractsImportFile(PayrollCategory::Crew, [[
        'employee_no' => 'LEGACY-EOS', 'name' => 'Legacy Monthly Crew',
        'basic_salary' => 15000, 'eos_monthly_amount' => 875,
    ]], 'monthly');
    $book = IOFactory::load($file->getRealPath());
    $book->getSheetByName('Crew Monthly Contracts')->setCellValue('K1', 'EOS Provision (Non-Payable)');
    (new Xlsx($book))->save($file->getRealPath());
    $rows = app(ContractsImport::class)->parse($file, PayrollCategory::Crew, 'monthly');
    expect((float) $rows[0]['eos_monthly_amount'])->toBe(875.0);
});
