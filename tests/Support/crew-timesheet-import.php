<?php

use App\Enums\PayrollCategory;
use App\Imports\CrewTimesheetsImport;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Support\Payroll\Actions\SyncContractSalaryComponentsFromContract;
use App\Support\Payroll\CrewTimesheetImportSchema;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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
        $setCell('Sign-On Standby From', $row['sign_on_standby_from'] ?? '');
        $setCell('Sign-On Standby To', $row['sign_on_standby_to'] ?? '');
        $setCell('Onsite From', $row['onsite_from'] ?? '');
        $setCell('Onsite To', $row['onsite_to'] ?? '');
        $setCell('Sign-Off Standby From', $row['sign_off_standby_from'] ?? '');
        $setCell('Sign-Off Standby To', $row['sign_off_standby_to'] ?? '');
        $setCell('Unpaid Leave Days', $row['unpaid_leave_days'] ?? '');
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
