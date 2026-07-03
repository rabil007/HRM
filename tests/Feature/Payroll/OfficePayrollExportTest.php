<?php

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\Position;
use App\Support\Payroll\Services\OfficePayrollSalarySheetExporter;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;

test('users without payroll periods view permission cannot export approved office payroll', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.crew_timesheets.view']);

    [$period] = createApprovedOfficeExportFixture($company);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.export', $period))
        ->assertForbidden();
});

test('office payroll export is only available for approved or paid periods', function (PayrollPeriodStatus $status) {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.view']);

    [$period] = createApprovedOfficeExportFixture($company);
    $period->update(['status' => $status]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.export', $period->fresh()))
        ->assertForbidden();
})->with([
    'draft' => PayrollPeriodStatus::Draft,
    'processing' => PayrollPeriodStatus::Processing,
]);

test('paid office payroll export downloads office payroll workbook', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.view']);

    [$period] = createApprovedOfficeExportFixture($company);
    $period->update(['status' => PayrollPeriodStatus::Paid]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.export', $period->fresh()))
        ->assertOk()
        ->assertDownload('office-payroll-june-2026-office.xlsx');
});

test('approved office payroll export downloads office payroll workbook', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.view']);

    [$period] = createApprovedOfficeExportFixture($company);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.export', $period))
        ->assertOk()
        ->assertDownload('office-payroll-june-2026-office.xlsx');
});

test('office payroll salary sheet export populates payroll data without client or project columns', function () {
    ['company' => $company] = makePayrollFixtures();

    [$period, $employee, $department, $position] = createApprovedOfficeExportFixture($company);

    $result = app(OfficePayrollSalarySheetExporter::class)->export($company->id, $period->fresh());
    $sheet = IOFactory::load($result['path'])->getSheetByName(OfficePayrollSalarySheetExporter::SHEET_NAME);

    expect($sheet)->not->toBeNull()
        ->and($sheet->getCell('B2')->getValue())->toBe('EMPLOYEE NO.')
        ->and($sheet->getCell('O2')->getValue())->toBe('TOTAL SALARY')
        ->and($sheet->getCell('B3')->getValue())->toBe('3007')
        ->and($sheet->getCell('C3')->getValue())->toBe('ABDELLAH BELLYMANI')
        ->and($sheet->getCell('D3')->getValue())->toBe($department->name)
        ->and($sheet->getCell('E3')->getValue())->toBe($position->title)
        ->and($sheet->getCell('H3')->getCalculatedValue())->toEqual(22)
        ->and($sheet->getCell('I3')->getCalculatedValue())->toEqual(10000)
        ->and($sheet->getCell('J3')->getCalculatedValue())->toEqual(2000)
        ->and($sheet->getCell('K3')->getCalculatedValue())->toEqual(500)
        ->and($sheet->getCell('L3')->getCalculatedValue())->toEqual(250)
        ->and($sheet->getCell('M3')->getValue())->toBe('Bank transfer')
        ->and($sheet->getCell('O3')->getCalculatedValue())->toEqual(12750);

    @unlink($result['path']);
});

test('office payroll salary sheet export highlights missing department and position in red', function () {
    ['company' => $company] = makePayrollFixtures();

    [$period, $employee] = createApprovedOfficeExportFixture($company, withOrgData: false);

    $result = app(OfficePayrollSalarySheetExporter::class)->export($company->id, $period->fresh());
    $sheet = IOFactory::load($result['path'])->getSheetByName(OfficePayrollSalarySheetExporter::SHEET_NAME);

    expect($sheet->getStyle('D3')->getFill()->getStartColor()->getRGB())->toBe('FF0000')
        ->and($sheet->getStyle('E3')->getFill()->getStartColor()->getRGB())->toBe('FF0000');

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Offshore',
        'parent_id' => null,
    ]);

    $position = Position::query()->create([
        'company_id' => $company->id,
        'department_id' => $department->id,
        'title' => 'Mechanical Technician',
        'status' => 'active',
    ]);

    $employee->update([
        'department_id' => $department->id,
        'position_id' => $position->id,
    ]);

    $resultWithData = app(OfficePayrollSalarySheetExporter::class)->export($company->id, $period->fresh());
    $sheetWithData = IOFactory::load($resultWithData['path'])->getSheetByName(OfficePayrollSalarySheetExporter::SHEET_NAME);

    expect($sheetWithData->getCell('D3')->getValue())->toBe('Offshore')
        ->and($sheetWithData->getCell('E3')->getValue())->toBe('Mechanical Technician')
        ->and($sheetWithData->getStyle('D3')->getFill()->getStartColor()->getRGB())->not->toBe('FF0000')
        ->and($sheetWithData->getStyle('E3')->getFill()->getStartColor()->getRGB())->not->toBe('FF0000');

    @unlink($result['path']);
    @unlink($resultWithData['path']);
});

test('approved office payroll show page exposes export permission flag', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.view']);

    [$period] = createApprovedOfficeExportFixture($company);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', ['payrollPeriod' => $period, 'tab' => 'payroll']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('permissions.export_payroll', true)
        );
});

test('paid office payroll show page exposes export permission flag', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.view']);

    [$period] = createApprovedOfficeExportFixture($company);
    $period->update(['status' => PayrollPeriodStatus::Paid]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', ['payrollPeriod' => $period->fresh(), 'tab' => 'payroll']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('permissions.export_payroll', true)
        );
});

/**
 * @return array{0: PayrollPeriod, 1: Employee, 2: Department, 3: Position}
 */
function createApprovedOfficeExportFixture($company, bool $withOrgData = true): array
{
    $period = PayrollPeriod::factory()->for($company)->office()->approved()->create([
        'name' => 'June 2026 Office',
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
    ]);

    $parentDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Operations',
        'parent_id' => null,
    ]);

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Offshore',
        'parent_id' => $parentDepartment->id,
    ]);

    $position = Position::query()->create([
        'company_id' => $company->id,
        'department_id' => $department->id,
        'title' => 'Mechanical Technician',
        'status' => 'active',
    ]);

    $employee = createOfficeEmployeeWithContract($company, '3007', 10000, 2000, 500, 250);
    $employee->update([
        'name' => 'ABDELLAH BELLYMANI',
        'department_id' => $withOrgData ? $department->id : null,
        'position_id' => $withOrgData ? $position->id : null,
    ]);

    PayrollRecord::factory()->for($company)->create([
        'period_id' => $period->id,
        'employee_id' => $employee->id,
        'payroll_category' => PayrollCategory::Office,
        'basic_salary' => '10000.00',
        'housing_allowance' => '2000.00',
        'transport_allowance' => '500.00',
        'other_allowances' => '250.00',
        'overtime_pay' => '0.00',
        'bonus' => '0.00',
        'other_deductions' => '0.00',
        'total_deductions' => '0.00',
        'gross_salary' => '12750.00',
        'net_salary' => '12750.00',
        'working_days' => 22,
        'present_days' => 22,
        'absent_days' => 0,
        'status' => 'approved',
        'calculation_breakdown' => [
            'working_days' => 22,
            'present_days' => 22,
            'absent_days' => 0,
        ],
    ]);

    return [$period, $employee, $department, $position];
}
