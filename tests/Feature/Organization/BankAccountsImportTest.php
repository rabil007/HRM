<?php

use App\Imports\BankAccountsImport;
use App\Models\Bank;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\User;
use App\Support\BankAccounts\BankAccountImportTemplateExporter;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

test('users without bank accounts import permission cannot download template', function () {
    ['user' => $user, 'company' => $company] = makeBankAccountsImportFixtures();

    grantCompanyPermissions($user, $company, ['bank_accounts.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.bank-accounts.import.template'))
        ->assertForbidden();
});

test('bank accounts template lists active employees', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeBankAccountsImportFixtures();

    grantCompanyPermissions($user, $company, ['bank_accounts.view', 'bank_accounts.import']);

    $result = app(BankAccountImportTemplateExporter::class)->export($company->id);
    $sheet = IOFactory::load($result['path'])->getSheetByName('Bank Accounts');

    $employeeNumbers = [];

    for ($row = BankAccountsImport::DATA_START_ROW; $row <= $sheet->getHighestDataRow(); $row++) {
        $employeeNumbers[] = (string) $sheet->getCell('A'.$row)->getValue();
    }

    expect($employeeNumbers)->toContain($employee->employee_no);

    @unlink($result['path']);
});

test('bank accounts import preview rejects unknown employee numbers', function () {
    ['user' => $user, 'company' => $company, 'bank' => $bank] = makeBankAccountsImportFixtures();

    grantCompanyPermissions($user, $company, ['bank_accounts.view', 'bank_accounts.import']);

    $file = makeBankAccountsImportFile([
        [
            'employee_no' => 'UNKNOWN-999',
            'name' => 'Unknown',
            'bank_name' => $bank->name,
            'iban' => 'AE123456789',
            'account_name' => 'Unknown Account',
            'is_primary' => 'Yes',
        ],
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.bank-accounts.import.preview'), [
            'file' => $file,
        ])
        ->assertOk()
        ->assertJsonPath('summary.invalid', 1)
        ->assertJsonPath('rows.0.errors.0.field', 'employee_no');
});

test('bank accounts import creates new bank accounts', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'bank' => $bank] = makeBankAccountsImportFixtures();

    grantCompanyPermissions($user, $company, ['bank_accounts.view', 'bank_accounts.import']);

    $file = makeBankAccountsImportFile([
        [
            'employee_no' => $employee->employee_no,
            'name' => $employee->name,
            'bank_name' => $bank->name,
            'iban' => 'AE987654321',
            'account_name' => 'John Doe Account',
            'is_primary' => 'Yes',
        ],
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.bank-accounts.import'), [
            'file' => $file,
        ])
        ->assertRedirect(route('organization.bank-accounts'))
        ->assertSessionHas('success');

    $created = EmployeeBankAccount::query()
        ->where('employee_id', $employee->id)
        ->where('iban', 'AE987654321')
        ->first();

    expect($created)->not->toBeNull()
        ->and($created->bank_id)->toBe($bank->id)
        ->and($created->is_primary)->toBeTrue();
});

test('bank accounts import updates existing bank accounts', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'bank' => $bank, 'account' => $account] = makeBankAccountsImportFixtures();

    grantCompanyPermissions($user, $company, ['bank_accounts.view', 'bank_accounts.import']);

    $file = makeBankAccountsImportFile([
        [
            'employee_no' => $employee->employee_no,
            'name' => $employee->name,
            'bank_name' => $bank->name,
            'iban' => $account->iban,
            'account_name' => 'Updated Account Name',
            'is_primary' => 'Yes',
        ],
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.bank-accounts.import'), [
            'file' => $file,
        ])
        ->assertRedirect(route('organization.bank-accounts'));

    expect($account->fresh()->account_name)->toBe('Updated Account Name');
});

/**
 * @return array{
 *     user: User,
 *     company: Company,
 *     employee: Employee,
 *     bank: Bank,
 *     account: EmployeeBankAccount
 * }
 */
function makeBankAccountsImportFixtures(): array
{
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'BIM',
        'name' => 'Bank Import Land',
        'dial_code' => '+995',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'BIM',
        'name' => 'Bank Import Currency',
        'symbol' => 'B$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Bank Import Co',
        'slug' => 'bank-import-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Finance',
        'code' => 'FIN',
        'status' => 'active',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'EMP-FIN-01',
        'name' => 'Finance Worker',
        'department_id' => $department->id,
        'status' => 'active',
    ]);

    $bank = Bank::query()->create([
        'name' => 'First Import Bank',
        'country_id' => $country->id,
        'is_active' => true,
    ]);

    $account = EmployeeBankAccount::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'bank_id' => $bank->id,
        'iban' => 'AE1111111111',
        'account_name' => 'Old Account Name',
        'is_primary' => true,
    ]);

    return compact('user', 'company', 'employee', 'bank', 'account');
}

/**
 * @param  list<array<string, mixed>>  $rows
 */
function makeBankAccountsImportFile(array $rows): UploadedFile
{
    $import = app(BankAccountsImport::class);
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle($import->sheetName());

    foreach ($import->headers() as $columnIndex => $header) {
        $sheet->setCellValueByColumnAndRow($columnIndex + 1, 1, $header);
    }

    $rowNumber = BankAccountsImport::DATA_START_ROW;

    foreach ($rows as $row) {
        $sheet->setCellValueByColumnAndRow(1, $rowNumber, $row['employee_no'] ?? null);
        $sheet->setCellValueByColumnAndRow(2, $rowNumber, $row['name'] ?? null);
        $sheet->setCellValueByColumnAndRow(3, $rowNumber, $row['bank_name'] ?? null);
        $sheet->setCellValueByColumnAndRow(4, $rowNumber, $row['iban'] ?? null);
        $sheet->setCellValueByColumnAndRow(5, $rowNumber, $row['account_name'] ?? null);
        $sheet->setCellValueByColumnAndRow(6, $rowNumber, $row['is_primary'] ?? null);

        $rowNumber++;
    }

    $path = storage_path('app/temp/'.uniqid('bank-accounts-import-test-', true).'.xlsx');
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    (new Xlsx($spreadsheet))->save($path);

    return new UploadedFile($path, 'bank-accounts-import.xlsx', null, null, true);
}
