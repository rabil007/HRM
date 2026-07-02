<?php

use App\Enums\PayrollCategory;
use App\Imports\CrewTimesheetsImport;
use App\Models\CrewTimesheet;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Models\Position;
use App\Support\Payroll\Actions\SyncContractSalaryComponentsFromContract;
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
        ->and($sheet->getCell('K1')->getValue())->toBe('Onsite Days')
        ->and($sheet->getCell('A2')->getValue())->toBe('2057')
        ->and($sheet->getCell('B2')->getValue())->toBe('AHMED LATECH')
        ->and($sheet->getCell('C2')->getValue())->toBe('Marine')
        ->and($sheet->getCell('D2')->getValue())->toBe('Deck')
        ->and($sheet->getCell('E2')->getValue())->toBe('Chief Officer')
        ->and($sheet->getCell('F2')->getValue())->toBeNull()
        ->and($sheet->getAutoFilter()->getRange())->toBe('A1:K2');

    @unlink($result['path']);
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

    $file = makeCrewTimesheetImportFile([
        ['employee_no' => '9999', 'name' => 'Unknown', 'onsite_days' => 10],
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

    $file = makeCrewTimesheetImportFile([
        [
            'employee_no' => '2057',
            'name' => 'AHMED LATECH',
            'standby_from' => '2026-01-16',
            'standby_to' => '2026-01-17',
            'standby_days' => 2,
            'onsite_from' => '2026-01-01',
            'onsite_to' => '2026-01-15',
            'onsite_days' => 15,
        ],
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.import', $period), [
            'file' => $file,
        ])
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period, 'tab' => 'timesheets']))
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
        ->and($timesheet->overtime_amount)->toBe('0.00');
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

    $file = makeCrewTimesheetImportFile([
        ['employee_no' => '2057', 'name' => 'AHMED LATECH', 'onsite_days' => 10],
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

/**
 * @param  list<array<string, mixed>>  $rows
 */
function makeCrewTimesheetImportFile(array $rows): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle(CrewTimesheetsImport::SHEET_NAME);

    $headers = [
        'Employee No',
        'Employee Name',
        'Division',
        'Department',
        'Position',
        'Standby From',
        'Standby To',
        'Standby Days',
        'Onsite From',
        'Onsite To',
        'Onsite Days',
    ];

    foreach ($headers as $columnIndex => $header) {
        $sheet->setCellValueByColumnAndRow($columnIndex + 1, 1, $header);
    }

    $rowNumber = CrewTimesheetsImport::DATA_START_ROW;

    foreach ($rows as $row) {
        $sheet->setCellValue('A'.$rowNumber, $row['employee_no'] ?? '');
        $sheet->setCellValue('B'.$rowNumber, $row['name'] ?? '');
        $sheet->setCellValue('C'.$rowNumber, $row['division'] ?? '');
        $sheet->setCellValue('D'.$rowNumber, $row['department'] ?? '');
        $sheet->setCellValue('E'.$rowNumber, $row['position'] ?? '');
        $sheet->setCellValue('F'.$rowNumber, $row['standby_from'] ?? '');
        $sheet->setCellValue('G'.$rowNumber, $row['standby_to'] ?? '');
        $sheet->setCellValue('H'.$rowNumber, $row['standby_days'] ?? 0);
        $sheet->setCellValue('I'.$rowNumber, $row['onsite_from'] ?? '');
        $sheet->setCellValue('J'.$rowNumber, $row['onsite_to'] ?? '');
        $sheet->setCellValue('K'.$rowNumber, $row['onsite_days'] ?? 0);
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
