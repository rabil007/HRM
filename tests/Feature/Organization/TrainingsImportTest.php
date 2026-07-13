<?php

use App\Imports\TrainingsImport;
use App\Models\Company;
use App\Models\Country;
use App\Models\Course;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeTraining;
use App\Models\User;
use App\Support\EmployeeTrainings\TrainingImportTemplateExporter;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

test('users without training import permission cannot download template', function () {
    ['user' => $user, 'company' => $company] = makeTrainingsImportFixtures();

    grantCompanyPermissions($user, $company, ['training.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.training.import.template'))
        ->assertForbidden();
});

test('training template lists active employees', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeTrainingsImportFixtures();

    grantCompanyPermissions($user, $company, ['training.view', 'training.import']);

    $result = app(TrainingImportTemplateExporter::class)->export($company->id);
    $sheet = IOFactory::load($result['path'])->getSheetByName('Training');

    $employeeNumbers = [];

    for ($row = TrainingsImport::DATA_START_ROW; $row <= $sheet->getHighestDataRow(); $row++) {
        $employeeNumbers[] = (string) $sheet->getCell('A'.$row)->getValue();
    }

    expect($employeeNumbers)->toContain($employee->employee_no);

    @unlink($result['path']);
});

test('training import preview rejects unknown employee numbers', function () {
    ['user' => $user, 'company' => $company, 'course' => $course] = makeTrainingsImportFixtures();

    grantCompanyPermissions($user, $company, ['training.view', 'training.import']);

    $file = makeTrainingsImportFile([
        [
            'employee_no' => 'UNKNOWN-999',
            'name' => 'Unknown',
            'course' => $course->name,
            'issue_date' => '2024-01-15',
            'expiry_date' => '2025-01-15',
            'institute_center' => 'Safety Academy',
            'country' => null,
        ],
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.training.import.preview'), [
            'file' => $file,
        ])
        ->assertOk()
        ->assertJsonPath('summary.invalid', 1)
        ->assertJsonPath('rows.0.errors.0.field', 'employee_no');
});

test('training import creates new training records', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'course' => $course, 'country' => $country] = makeTrainingsImportFixtures();

    grantCompanyPermissions($user, $company, ['training.view', 'training.import']);

    $file = makeTrainingsImportFile([
        [
            'employee_no' => $employee->employee_no,
            'name' => $employee->name,
            'course' => $course->name,
            'issue_date' => '2024-03-01',
            'expiry_date' => '2025-03-01',
            'institute_center' => 'Maritime Academy',
            'country' => $country->name,
        ],
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.training.import'), [
            'file' => $file,
        ])
        ->assertRedirect(route('organization.training'))
        ->assertSessionHas('success');

    $created = EmployeeTraining::query()
        ->where('employee_id', $employee->id)
        ->where('course_id', $course->id)
        ->where('institute_center', 'Maritime Academy')
        ->first();

    expect($created)->not->toBeNull()
        ->and($created->issue_date?->toDateString())->toBe('2024-03-01')
        ->and($created->expiry_date?->toDateString())->toBe('2025-03-01')
        ->and($created->country_id)->toBe($country->id);
});

test('training import skips rows without training data', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeTrainingsImportFixtures();

    grantCompanyPermissions($user, $company, ['training.view', 'training.import']);

    $file = makeTrainingsImportFile([
        [
            'employee_no' => $employee->employee_no,
            'name' => $employee->name,
            'course' => null,
            'issue_date' => null,
            'expiry_date' => null,
            'institute_center' => null,
            'country' => null,
        ],
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.training.import.preview'), [
            'file' => $file,
        ])
        ->assertOk()
        ->assertJsonPath('summary.importable', 0)
        ->assertJsonPath('summary.skipped', 1)
        ->assertJsonPath('rows.0.action', 'skip');
});

/**
 * @return array{
 *     user: User,
 *     company: Company,
 *     employee: Employee,
 *     course: Course,
 *     country: Country
 * }
 */
function makeTrainingsImportFixtures(): array
{
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'TIM',
        'name' => 'Training Import Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TIM',
        'name' => 'Training Import Currency',
        'symbol' => 'T$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Training Import Co',
        'slug' => 'training-import-co-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Operations',
        'code' => 'OPS',
        'status' => 'active',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'EMP-TRN-01',
        'name' => 'Training Worker',
        'department_id' => $department->id,
        'status' => 'active',
    ]);

    $course = Course::query()->create([
        'name' => 'Import Safety Course '.uniqid(),
        'is_active' => true,
    ]);

    return compact('user', 'company', 'employee', 'course', 'country');
}

/**
 * @param  list<array<string, mixed>>  $rows
 */
function makeTrainingsImportFile(array $rows): UploadedFile
{
    $import = app(TrainingsImport::class);
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle($import->sheetName());

    foreach ($import->headers() as $columnIndex => $header) {
        $sheet->setCellValueByColumnAndRow($columnIndex + 1, 1, $header);
    }

    $rowNumber = TrainingsImport::DATA_START_ROW;

    foreach ($rows as $row) {
        $sheet->setCellValueByColumnAndRow(1, $rowNumber, $row['employee_no'] ?? null);
        $sheet->setCellValueByColumnAndRow(2, $rowNumber, $row['name'] ?? null);
        $sheet->setCellValueByColumnAndRow(3, $rowNumber, $row['course'] ?? null);
        $sheet->setCellValueByColumnAndRow(4, $rowNumber, $row['issue_date'] ?? null);
        $sheet->setCellValueByColumnAndRow(5, $rowNumber, $row['expiry_date'] ?? null);
        $sheet->setCellValueByColumnAndRow(6, $rowNumber, $row['institute_center'] ?? null);
        $sheet->setCellValueByColumnAndRow(7, $rowNumber, $row['country'] ?? null);

        $rowNumber++;
    }

    $path = storage_path('app/temp/'.uniqid('training-import-test-', true).'.xlsx');
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    (new Xlsx($spreadsheet))->save($path);

    return new UploadedFile($path, 'training-import.xlsx', null, null, true);
}
