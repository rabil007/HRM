<?php

use App\Enums\CrewTimesheetApprovalStatus;
use App\Enums\PayrollCategory;
use App\Imports\CrewTimesheetsImport;
use App\Models\CrewTimesheet;
use App\Models\Department;
use App\Models\PayrollPeriod;
use App\Models\Position;
use App\Models\SalaryInput;
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
        ->and($sheet->getCell('F1')->getValue())->toBe('Sign-On Standby From')
        ->and($sheet->getCell('G1')->getValue())->toBe('Sign-On Standby To')
        ->and($sheet->getCell('H1')->getValue())->toBe('Onsite From')
        ->and($sheet->getCell('I1')->getValue())->toBe('Onsite To')
        ->and($sheet->getCell('J1')->getValue())->toBe('Sign-Off Standby From')
        ->and($sheet->getCell('K1')->getValue())->toBe('Sign-Off Standby To')
        ->and($sheet->getCell('L1')->getValue())->toBe('Unpaid Leave Days')
        ->and($sheet->getCell('M1')->getValue())->toBe('Overtime Hours')
        ->and($sheet->getCell('N1')->getValue())->toBe('Bonus')
        ->and($sheet->getCell('O1')->getValue())->toBe('Commission')
        ->and($sheet->getCell('T1')->getValue())->toBe('Remarks')
        ->and($sheet->getCell('A2')->getValue())->toBe('2057')
        ->and($sheet->getCell('B2')->getValue())->toBe('AHMED LATECH')
        ->and($sheet->getCell('C2')->getValue())->toBe('Marine')
        ->and($sheet->getCell('D2')->getValue())->toBe('Deck')
        ->and($sheet->getCell('E2')->getValue())->toBe('Chief Officer')
        ->and($sheet->getCell('F2')->getValue())->toBeNull()
        ->and($sheet->getAutoFilter()->getRange())->toBe('A1:T2')
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
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);

    createImportCrewEmployee($company, '2057', 50, 661, 611);

    $file = makeCrewTimesheetImportFile($company->id, [
        [
            'employee_no' => '2057',
            'name' => 'AHMED LATECH',
            'sign_on_standby_from' => '01-07-2026',
            'sign_on_standby_to' => '15-07-2026',
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
        ->assertJsonPath('rows.0.sign_on_standby_days', 15)
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
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
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
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
    ]);

    $employee = createImportCrewEmployee($company, '2057', 50, 661, 611);

    $file = makeCrewTimesheetImportFile($company->id, [
        [
            'employee_no' => '2057',
            'name' => 'AHMED LATECH',
            'sign_on_standby_from' => '2026-01-16',
            'sign_on_standby_to' => '2026-01-17',
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
        ->and($timesheet->sign_on_standby_days)->toBe('2.00')
        ->and($timesheet->onsite_days)->toBe('15.00')
        ->and($timesheet->sign_on_standby_from?->toDateString())->toBe('2026-01-16')
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
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
    ]);

    $employee = createImportCrewEmployee($company, '2057', 50, 661, 611);

    $file = makeCrewTimesheetImportFile($company->id, [
        [
            'employee_no' => '2057',
            'name' => 'AHMED LATECH',
            'sign_on_standby_from' => '2026-01-16',
            'sign_on_standby_to' => '2026-01-17',
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
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
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
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
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
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
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
        ->and($timesheet->remarks)->toBe('Imported adjustment')
        ->and($timesheet->approval_status)->toBe(CrewTimesheetApprovalStatus::Approved)
        ->and($timesheet->approved_by)->toBe($user->id)
        ->and($timesheet->approved_at)->not->toBeNull();
});

test('crew timesheet import stores typed salary input from excel', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.import',
    ]);

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
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
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
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

test('crew timesheet import rejects pre-1970 excel serials in date columns as empty', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.import',
    ]);

    // Excel serial 92 ≈ 1900-04-01 — typical when overtime hours land in a date column.
    $file = makeCrewTimesheetImportFile($company->id, [
        [
            'employee_no' => '3088',
            'name' => 'FRANKLINE',
            'sign_on_standby_from' => '01-07-2026',
            'sign_on_standby_to' => 92,
            'overtime_hours' => 92,
        ],
    ]);

    $parsed = app(CrewTimesheetsImport::class)->parse($file, $company->id);

    expect($parsed['rows'])->toHaveCount(1)
        ->and($parsed['rows'][0]['sign_on_standby_from'])->toBe('2026-07-01')
        ->and($parsed['rows'][0]['sign_on_standby_to'])->toBeNull()
        ->and((float) $parsed['rows'][0]['overtime_hours'])->toBe(92.0);
});

test('crew timesheet import accepts roster-only files without salary or remarks columns', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.import',
    ]);

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
    ]);

    $employee = createImportCrewEmployee($company, '2057', 50, 661, 611);

    $file = makeCrewTimesheetImportFile($company->id, [
        [
            'employee_no' => '2057',
            'name' => 'AHMED LATECH',
            'sign_on_standby_from' => '2026-01-16',
            'sign_on_standby_to' => '2026-01-17',
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
