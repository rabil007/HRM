<?php

use App\Enums\PayrollCategory;
use App\Imports\ContractsImport;
use App\Models\Company;
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
            'contract_type' => $sheet->getCell('C'.$row)->getValue(),
        ];
    }

    expect($rowsByEmployeeNo[$officeEmployee->employee_no]['contract_type'])->toBe('limited');

    @unlink($result['path']);
});

test('crew contracts template includes crew allowance columns', function () {
    ['user' => $user, 'company' => $company] = makeContractsImportFixtures();

    grantCompanyPermissions($user, $company, ['contracts.view', 'contracts.import']);

    $result = app(ContractImportTemplateExporter::class)->export($company->id, PayrollCategory::Crew);
    $sheet = IOFactory::load($result['path'])->getSheetByName('Crew Contracts');

    expect($sheet)->not->toBeNull()
        ->and($sheet->getCell('I1')->getValue())->toBe('Supplementary Allowance')
        ->and($sheet->getCell('J1')->getValue())->toBe('Site Allowance');

    @unlink($result['path']);
});

test('contracts import preview rejects unknown employee numbers', function () {
    ['user' => $user, 'company' => $company] = makeContractsImportFixtures();

    grantCompanyPermissions($user, $company, ['contracts.view', 'contracts.import']);

    $file = makeContractsImportFile(PayrollCategory::Office, [
        [
            'employee_no' => 'UNKNOWN-999',
            'name' => 'Unknown',
            'contract_type' => 'limited',
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
            'contract_type' => 'limited',
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
            'contract_type' => 'limited',
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
        ->and($created->contract_type)->toBe('limited')
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
            'contract_type' => 'unlimited',
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

    expect($officeContract->fresh()->contract_type)->toBe('unlimited')
        ->and((string) $officeContract->fresh()->basic_salary)->toBe('9100.00');
});

test('contracts import ends other active contracts when importing an active contract', function () {
    ['user' => $user, 'company' => $company, 'officeEmployee' => $officeEmployee, 'officeContract' => $officeContract] = makeContractsImportFixtures();

    $otherActive = EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $officeEmployee->id,
        'contract_type' => 'contract',
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
            'contract_type' => 'limited',
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
    ]);

    $marineDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Marine',
        'code' => 'MAR',
        'status' => 'active',
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
        'contract_type' => 'limited',
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
        'contract_type' => 'contract',
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
function makeContractsImportFile(PayrollCategory $payrollCategory, array $rows): UploadedFile
{
    $import = app(ContractsImport::class);
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle($import->sheetName($payrollCategory));

    foreach ($import->headers($payrollCategory) as $columnIndex => $header) {
        $sheet->setCellValueByColumnAndRow($columnIndex + 1, 1, $header);
    }

    $rowNumber = ContractsImport::DATA_START_ROW;

    foreach ($rows as $row) {
        $sheet->setCellValueByColumnAndRow(1, $rowNumber, $row['employee_no'] ?? null);
        $sheet->setCellValueByColumnAndRow(2, $rowNumber, $row['name'] ?? null);
        $sheet->setCellValueByColumnAndRow(3, $rowNumber, $row['contract_type'] ?? null);
        $sheet->setCellValueByColumnAndRow(4, $rowNumber, $row['start_date'] ?? null);
        $sheet->setCellValueByColumnAndRow(5, $rowNumber, $row['end_date'] ?? null);
        $sheet->setCellValueByColumnAndRow(6, $rowNumber, $row['labor_contract_id'] ?? null);
        $sheet->setCellValueByColumnAndRow(7, $rowNumber, $row['status'] ?? null);
        $sheet->setCellValueByColumnAndRow(8, $rowNumber, $row['basic_salary'] ?? null);

        if ($payrollCategory === PayrollCategory::Office) {
            $sheet->setCellValueByColumnAndRow(9, $rowNumber, $row['housing_allowance'] ?? null);
            $sheet->setCellValueByColumnAndRow(10, $rowNumber, $row['transport_allowance'] ?? null);
            $sheet->setCellValueByColumnAndRow(11, $rowNumber, $row['other_allowances'] ?? null);
            $sheet->setCellValueByColumnAndRow(12, $rowNumber, $row['note'] ?? null);
        } else {
            $sheet->setCellValueByColumnAndRow(9, $rowNumber, $row['supplementary_allowance'] ?? null);
            $sheet->setCellValueByColumnAndRow(10, $rowNumber, $row['site_allowance'] ?? null);
            $sheet->setCellValueByColumnAndRow(11, $rowNumber, $row['note'] ?? null);
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
