<?php

use App\Enums\PayrollCategory;
use App\Imports\CrewTimesheetsImport;
use App\Models\CrewTimesheet;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Models\Position;
use App\Models\SalaryInput;
use App\Support\Payroll\Actions\SyncContractSalaryComponentsFromContract;
use App\Support\Payroll\CrewTimesheetImportSchema;
use App\Support\Payroll\Services\CrewTimesheetTemplateExporter;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

test('crew timesheet template download includes roster with department and position', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.import',
    ]);

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
        'name' => 'June 2026 Crew',
    ]);

    $parentDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Marine',
        'parent_id' => null,
    ]);

    $childDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Deck',
        'parent_id' => $parentDepartment->id,
    ]);

    $position = Position::query()->create([
        'company_id' => $company->id,
        'department_id' => $childDepartment->id,
        'title' => 'Chief Officer',
        'status' => 'active',
    ]);

    $employee = createImportCrewEmployee($company, '2057', 50, 661, 611);
    $employee->update([
        'name' => 'AHMED LATECH',
        'department_id' => $childDepartment->id,
        'position_id' => $position->id,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.timesheets.import.template', $period))
        ->assertOk()
        ->assertDownload('crew-timesheet-june-2026-crew.xlsx');

    $result = app(CrewTimesheetTemplateExporter::class)->export($company->id, $period->fresh());
    $sheet = IOFactory::load($result['path'])->getSheetByName(CrewTimesheetsImport::SHEET_NAME);

    expect($sheet)->not->toBeNull()
        ->and($sheet->getCell('A1')->getValue())->toBe('Employee No')
        ->and($sheet->getCell('C1')->getValue())->toBe('Division')
        ->and($sheet->getCell('I1')->getValue())->toBe('Onsite To')
        ->and($sheet->getCell('J1')->getValue())->toBe('Overtime Hours')
        ->and($sheet->getCell('K1')->getValue())->toBe('Bonus')
        ->and($sheet->getCell('L1')->getValue())->toBe('Commission')
        ->and($sheet->getCell('Q1')->getValue())->toBe('Remarks')
        ->and($sheet->getCell('A2')->getValue())->toBe('2057')
        ->and($sheet->getCell('B2')->getValue())->toBe('AHMED LATECH')
        ->and($sheet->getCell('C2')->getValue())->toBe('Marine')
        ->and($sheet->getCell('D2')->getValue())->toBe('Deck')
        ->and($sheet->getCell('E2')->getValue())->toBe('Chief Officer')
        ->and($sheet->getCell('F2')->getValue())->toBeNull()
        ->and($sheet->getAutoFilter()->getRange())->toBe('A1:Q2')
        ->and($sheet->getStyle('F2')->getNumberFormat()->getFormatCode())->toBe(CrewTimesheetTemplateExporter::DATE_FORMAT);

    @unlink($result['path']);
});

test('crew timesheet import parses dd-mm-yyyy dates from excel', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.import',
    ]);

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
    ]);

    createImportCrewEmployee($company, '2057', 50, 661, 611);

    $file = makeCrewTimesheetImportFile($company->id, [
        [
            'employee_no' => '2057',
            'name' => 'AHMED LATECH',
            'standby_from' => '01-07-2026',
            'standby_to' => '15-07-2026',
            'onsite_from' => '16-07-2026',
            'onsite_to' => '25-07-2026',
        ],
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.import.preview', $period), [
            'file' => $file,
        ])
        ->assertOk()
        ->assertJsonPath('summary.total', 1)
        ->assertJsonPath('summary.valid', 1)
        ->assertJsonPath('rows.0.standby_days', 15)
        ->assertJsonPath('rows.0.onsite_days', 10);
});

test('crew timesheet import preview rejects unknown employee numbers', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.import',
    ]);

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
    ]);

    $file = makeCrewTimesheetImportFile($company->id, [
        ['employee_no' => '9999', 'name' => 'Unknown'],
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.import.preview', $period), [
            'file' => $file,
        ])
        ->assertOk()
        ->assertJsonPath('summary.total', 1)
        ->assertJsonPath('summary.valid', 0)
        ->assertJsonPath('summary.invalid', 1);
});

test('crew timesheet import creates timesheets for valid rows', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.import',
    ]);

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
    ]);

    $employee = createImportCrewEmployee($company, '2057', 50, 661, 611);

    $file = makeCrewTimesheetImportFile($company->id, [
        [
            'employee_no' => '2057',
            'name' => 'AHMED LATECH',
            'standby_from' => '2026-01-16',
            'standby_to' => '2026-01-17',
            'onsite_from' => '2026-01-01',
            'onsite_to' => '2026-01-15',
        ],
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.import', $period), [
            'file' => $file,
        ])
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period]))
        ->assertSessionHas('success');

    $timesheet = CrewTimesheet::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $employee->id)
        ->first();

    expect($timesheet)->not->toBeNull()
        ->and($timesheet->standby_days)->toBe('2.00')
        ->and($timesheet->onsite_days)->toBe('15.00')
        ->and($timesheet->standby_from?->toDateString())->toBe('2026-01-16')
        ->and($timesheet->onsite_from?->toDateString())->toBe('2026-01-01')
        ->and($timesheet->overtime_hours)->toBe('0.00');
});

test('crew timesheet import stores overtime hours from excel', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.import',
    ]);

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
    ]);

    $employee = createImportCrewEmployee($company, '2057', 50, 661, 611);

    $file = makeCrewTimesheetImportFile($company->id, [
        [
            'employee_no' => '2057',
            'name' => 'AHMED LATECH',
            'standby_from' => '2026-01-16',
            'standby_to' => '2026-01-17',
            'onsite_from' => '2026-01-01',
            'onsite_to' => '2026-01-15',
            'overtime_hours' => 76,
        ],
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.import.preview', $period), [
            'file' => $file,
        ])
        ->assertOk()
        ->assertJsonPath('rows.0.overtime_hours', 76);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.import', $period), [
            'file' => $file,
        ])
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period]))
        ->assertSessionHas('success');

    $timesheet = CrewTimesheet::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $employee->id)
        ->first();

    expect($timesheet)->not->toBeNull()
        ->and($timesheet->overtime_hours)->toBe('76.00');
});

test('crew timesheet import cannot run on approved periods', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.import',
    ]);

    $period = PayrollPeriod::factory()->for($company)->approved()->create([
        'payroll_category' => PayrollCategory::Crew,
    ]);

    createImportCrewEmployee($company, '2057', 50, 661, 611);

    $file = makeCrewTimesheetImportFile($company->id, [
        ['employee_no' => '2057', 'name' => 'AHMED LATECH'],
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.import.preview', $period), [
            'file' => $file,
        ])
        ->assertSessionHasErrors('period_id');
});

test('crew timesheet import preview rejects invalid template headers', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.import',
    ]);

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
    ]);

    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle(CrewTimesheetsImport::SHEET_NAME);
    $sheet->setCellValue('A1', 'Wrong Header');
    $path = tempnam(sys_get_temp_dir(), 'crew-import-bad-').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);
    $file = new UploadedFile($path, 'bad.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.import.preview', $period), [
            'file' => $file,
        ])
        ->assertSessionHasErrors('file');
});

test('crew timesheet import stores remarks from excel', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.import',
    ]);

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
    ]);

    $employee = createImportCrewEmployee($company, '2057', 50, 661, 611);

    $file = makeCrewTimesheetImportFile($company->id, [
        [
            'employee_no' => '2057',
            'name' => 'AHMED LATECH',
            'remarks' => 'Imported adjustment',
        ],
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.import.preview', $period), [
            'file' => $file,
        ])
        ->assertOk()
        ->assertJsonPath('rows.0.remarks', 'Imported adjustment');

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.import', $period), [
            'file' => $file,
        ])
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period]))
        ->assertSessionHas('success');

    $timesheet = CrewTimesheet::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $employee->id)
        ->first();

    expect($timesheet)->not->toBeNull()
        ->and($timesheet->remarks)->toBe('Imported adjustment');
});

test('crew timesheet import stores typed salary input from excel', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.import',
    ]);

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
    ]);

    $employee = createImportCrewEmployee($company, '2057', 50, 661, 611);

    $file = makeCrewTimesheetImportFile($company->id, [
        [
            'employee_no' => '2057',
            'name' => 'AHMED LATECH',
            'salary_inputs' => [
                'Bonus' => 500,
            ],
        ],
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.import', $period), [
            'file' => $file,
        ])
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period]))
        ->assertSessionHas('success');

    $salaryInput = SalaryInput::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $employee->id)
        ->where('salary_input_type_id', salaryInputTypeId($company, 'bonus'))
        ->first();

    expect($salaryInput)->not->toBeNull()
        ->and($salaryInput->amount)->toBe('500.00');
});

test('crew timesheet import clears typed salary input when column is blank', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.import',
    ]);

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
    ]);

    $employee = createImportCrewEmployee($company, '2057', 50, 661, 611);

    SalaryInput::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'salary_input_type_id' => salaryInputTypeId($company, 'bonus'),
        'amount' => 300,
    ]);

    $file = makeCrewTimesheetImportFile($company->id, [
        [
            'employee_no' => '2057',
            'name' => 'AHMED LATECH',
            'salary_inputs' => [
                'Bonus' => '',
            ],
        ],
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.import', $period), [
            'file' => $file,
        ])
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period]))
        ->assertSessionHas('success');

    expect(SalaryInput::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $employee->id)
        ->where('salary_input_type_id', salaryInputTypeId($company, 'bonus'))
        ->exists())->toBeFalse();
});

test('crew timesheet import still accepts legacy ten column files', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.import',
    ]);

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
    ]);

    $employee = createImportCrewEmployee($company, '2057', 50, 661, 611);

    $file = makeCrewTimesheetImportFile($company->id, [
        [
            'employee_no' => '2057',
            'name' => 'AHMED LATECH',
            'standby_from' => '2026-01-16',
            'standby_to' => '2026-01-17',
            'onsite_from' => '2026-01-01',
            'onsite_to' => '2026-01-15',
        ],
    ], legacyHeaders: true);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.import', $period), [
            'file' => $file,
        ])
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period]))
        ->assertSessionHas('success');

    $timesheet = CrewTimesheet::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $employee->id)
        ->first();

    expect($timesheet)->not->toBeNull()
        ->and($timesheet->additional_amount)->toBe('0.00')
        ->and($timesheet->deduction_amount)->toBe('0.00')
        ->and($timesheet->remarks)->toBeNull();
});

/**
 * @param  list<array<string, mixed>>  $rows
 */
function makeCrewTimesheetImportFile(int $companyId, array $rows, bool $legacyHeaders = false): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle(CrewTimesheetsImport::SHEET_NAME);

    $schema = app(CrewTimesheetImportSchema::class);
    $headers = $legacyHeaders
        ? CrewTimesheetImportSchema::rosterHeaders()
        : $schema->headers($companyId);

    foreach ($headers as $columnIndex => $header) {
        $sheet->setCellValueByColumnAndRow($columnIndex + 1, 1, $header);
    }

    $headerIndexByName = collect($headers)
        ->mapWithKeys(fn (string $header, int $index) => [$header => $index + 1])
        ->all();

    $rowNumber = CrewTimesheetsImport::DATA_START_ROW;

    foreach ($rows as $row) {
        $setCell = function (string $header, mixed $value) use ($sheet, $headerIndexByName, $rowNumber): void {
            if (! isset($headerIndexByName[$header])) {
                return;
            }

            $sheet->setCellValueByColumnAndRow($headerIndexByName[$header], $rowNumber, $value ?? '');
        };

        $setCell('Employee No', $row['employee_no'] ?? '');
        $setCell('Employee Name', $row['name'] ?? '');
        $setCell('Division', $row['division'] ?? '');
        $setCell('Department', $row['department'] ?? '');
        $setCell('Position', $row['position'] ?? '');
        $setCell('Standby From', $row['standby_from'] ?? '');
        $setCell('Standby To', $row['standby_to'] ?? '');
        $setCell('Onsite From', $row['onsite_from'] ?? '');
        $setCell('Onsite To', $row['onsite_to'] ?? '');
        $setCell('Overtime Hours', $row['overtime_hours'] ?? '');
        $setCell('Remarks', $row['remarks'] ?? '');

        foreach ($row['salary_inputs'] ?? [] as $typeName => $amount) {
            $setCell((string) $typeName, $amount);
        }

        $rowNumber++;
    }

    $path = tempnam(sys_get_temp_dir(), 'crew-import-').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return new UploadedFile($path, 'crew-timesheet.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

function createImportCrewEmployee(
    $company,
    string $employeeNo,
    float $basicRate,
    float $siteRate,
    float $supplementaryRate,
): Employee {
    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => $employeeNo,
        'status' => 'active',
    ]);

    $contract = EmployeeContract::factory()->create([
        'employee_id' => $employee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'status' => 'active',
        'basic_salary' => $basicRate,
        'site_allowance' => $siteRate,
        'supplementary_allowance' => $supplementaryRate,
    ]);

    (new SyncContractSalaryComponentsFromContract)->handle($contract);

    return $employee;
}
