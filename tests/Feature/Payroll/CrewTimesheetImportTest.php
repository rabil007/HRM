<?php

use App\Enums\PayrollCategory;
use App\Models\CrewTimesheet;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Support\Payroll\Actions\SyncContractSalaryComponentsFromContract;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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
            'basic_rate' => 50,
            'supplementary_rate' => 611,
            'site_rate' => 661,
            'overtime' => 0,
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
        ->and($timesheet->onsite_from?->toDateString())->toBe('2026-01-01');
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

test('crew timesheet import preview warns when file rates differ from contract', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.import',
    ]);

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
    ]);

    createImportCrewEmployee($company, '2057', 50, 661, 611);

    $file = makeCrewTimesheetImportFile([
        [
            'employee_no' => '2057',
            'name' => 'AHMED LATECH',
            'onsite_days' => 10,
            'basic_rate' => 99,
            'supplementary_rate' => 611,
            'site_rate' => 661,
        ],
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.import.preview', $period), [
            'file' => $file,
        ])
        ->assertOk()
        ->assertJsonPath('summary.warnings', 1)
        ->assertJsonPath('summary.valid', 1);
});

/**
 * @param  list<array<string, mixed>>  $rows
 */
function makeCrewTimesheetImportFile(array $rows): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Salary Sheet');

    $sheet->setCellValue('A1', 'PAYROLL SUMMARY FOR TEST');
    $sheet->setCellValue('B2', 'EMP.NO.');
    $sheet->setCellValue('G3', 'STAND BY');
    $sheet->setCellValue('J3', 'ON SITE');
    $sheet->setCellValue('G4', 'FROM');
    $sheet->setCellValue('H4', 'TO');
    $sheet->setCellValue('I4', 'NO. OF DAYS');
    $sheet->setCellValue('J4', 'FROM');
    $sheet->setCellValue('K4', 'TO');
    $sheet->setCellValue('L4', 'NO. OF DAYS');

    $rowNumber = 5;

    foreach ($rows as $row) {
        $sheet->setCellValue('B'.$rowNumber, $row['employee_no'] ?? '');
        $sheet->setCellValue('C'.$rowNumber, $row['name'] ?? '');
        $sheet->setCellValue('G'.$rowNumber, $row['standby_from'] ?? '-');
        $sheet->setCellValue('H'.$rowNumber, $row['standby_to'] ?? '-');
        $sheet->setCellValue('I'.$rowNumber, $row['standby_days'] ?? 0);
        $sheet->setCellValue('J'.$rowNumber, $row['onsite_from'] ?? '-');
        $sheet->setCellValue('K'.$rowNumber, $row['onsite_to'] ?? '-');
        $sheet->setCellValue('L'.$rowNumber, $row['onsite_days'] ?? 0);
        $sheet->setCellValue('M'.$rowNumber, $row['basic_rate'] ?? 0);
        $sheet->setCellValue('N'.$rowNumber, $row['supplementary_rate'] ?? 0);
        $sheet->setCellValue('O'.$rowNumber, $row['site_rate'] ?? 0);
        $sheet->setCellValue('S'.$rowNumber, $row['overtime'] ?? 0);
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
