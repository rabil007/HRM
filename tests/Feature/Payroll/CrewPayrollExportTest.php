<?php

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\Client;
use App\Models\CrewTimesheet;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\Position;
use App\Models\Project;
use App\Support\Payroll\Services\CrewPayrollSalarySheetExporter;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

test('users without crew timesheet view permission cannot export approved crew payroll', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.view']);

    [$period] = createApprovedCrewExportFixture($company);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.export', $period))
        ->assertForbidden();
});

test('crew payroll export is only available for approved or paid periods', function (PayrollPeriodStatus $status) {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.crew_timesheets.view']);

    [$period] = createApprovedCrewExportFixture($company);
    $period->update(['status' => $status]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.export', $period->fresh()))
        ->assertForbidden();
})->with([
    'draft' => PayrollPeriodStatus::Draft,
    'processing' => PayrollPeriodStatus::Processing,
]);

test('paid crew payroll export downloads salary sheet workbook', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.crew_timesheets.view']);

    [$period] = createApprovedCrewExportFixture($company);
    $period->update(['status' => PayrollPeriodStatus::Paid]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.export', $period->fresh()))
        ->assertOk()
        ->assertDownload('crew-payroll-june-2026-crew.xlsx');
});

test('users with only crew timesheet permission cannot export approved office payroll', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.crew_timesheets.view']);

    [$period] = createApprovedOfficeExportFixture($company);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.export', $period))
        ->assertForbidden();
});

test('approved crew payroll export downloads salary sheet workbook', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.periods.view',
        'payroll.crew_timesheets.view',
    ]);

    [$period] = createApprovedCrewExportFixture($company);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.export', $period))
        ->assertOk()
        ->assertDownload('crew-payroll-june-2026-crew.xlsx');
});

test('crew payroll salary sheet export populates payroll and timesheet data', function () {
    ['company' => $company] = makePayrollFixtures();

    [$period, $employee, $client, $project] = createApprovedCrewExportFixture($company);

    $result = app(CrewPayrollSalarySheetExporter::class)->export($company->id, $period->fresh());
    $sheet = IOFactory::load($result['path'])->getSheetByName(CrewPayrollSalarySheetExporter::SHEET_NAME);

    expect($sheet)->not->toBeNull()
        ->and($sheet->getCell('B2')->getValue())->toBe('EMP.NO.')
        ->and($sheet->getCell('S2')->getValue())->toBe('OT')
        ->and($sheet->getCell('T2')->getValue())->toBe('TOTAL SALARY')
        ->and($sheet->getCell('B3')->getValue())->toBe('2025')
        ->and($sheet->getCell('C3')->getValue())->toBe('VINOD MENON')
        ->and($sheet->getCell('D3')->getValue())->toBe('JUB PORT CAPT.')
        ->and($sheet->getCell('E3')->getValue())->toBe($client->name)
        ->and($sheet->getCell('F3')->getValue())->toBe($project->title)
        ->and($sheet->getCell('I3')->getCalculatedValue())->toEqual(0)
        ->and($sheet->getStyle('I3')->getNumberFormat()->getFormatCode())->toBe(NumberFormat::FORMAT_NUMBER)
        ->and($sheet->getCell('L3')->getCalculatedValue())->toEqual(30)
        ->and($sheet->getCell('P3')->getCalculatedValue())->toEqual(0)
        ->and($sheet->getCell('S3')->getCalculatedValue())->toEqual(0)
        ->and($sheet->getCell('T3')->getCalculatedValue())->toEqual(55000)
        ->and($sheet->getCell('U3')->getValue())->toBe('Bank transfer')
        ->and($sheet->getCell('V3')->getValue())->toBe('Daily')
        ->and($sheet->getAutoFilter()->getRange())->toBe('A2:V3');

    @unlink($result['path']);
});

test('crew payroll salary sheet export includes overtime pay and formats standby days as numbers', function () {
    ['company' => $company] = makePayrollFixtures();

    [$period, $employee, $client, $project] = createApprovedCrewExportFixture($company);

    CrewTimesheet::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $employee->id)
        ->update([
            'standby_days' => 0,
            'overtime_hours' => 78,
        ]);

    PayrollRecord::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $employee->id)
        ->update([
            'overtime_pay' => '1875.21',
            'gross_salary' => '56875.21',
            'net_salary' => '56875.21',
            'calculation_breakdown' => [
                'standby_days' => 0,
                'onsite_days' => 30,
                'rates' => [
                    'basic_daily' => 0,
                    'site_allowance_daily' => 0,
                    'supplementary_allowance_daily' => 0,
                ],
                'lines' => [
                    'standby_pay' => 0,
                    'onsite_pay' => 0,
                    'site_allowance' => 0,
                    'supplementary_allowance' => 0,
                    'overtime' => 1875.21,
                ],
            ],
        ]);

    $result = app(CrewPayrollSalarySheetExporter::class)->export($company->id, $period->fresh());
    $sheet = IOFactory::load($result['path'])->getSheetByName(CrewPayrollSalarySheetExporter::SHEET_NAME);

    expect($sheet->getCell('I3')->getFormattedValue())->toBe('0')
        ->and($sheet->getCell('S3')->getCalculatedValue())->toEqual(1875.21)
        ->and($sheet->getCell('T3')->getCalculatedValue())->toEqual(56875.21);

    @unlink($result['path']);
});

test('crew payroll salary sheet export includes salary structure column for monthly crew', function () {
    ['company' => $company] = makePayrollFixtures();

    [$period, $employee] = createApprovedCrewExportFixture($company);

    PayrollRecord::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $employee->id)
        ->update([
            'housing_allowance' => '2000.00',
            'calculation_breakdown' => [
                'salary_structure' => 'monthly',
                'standby_days' => 5,
                'onsite_days' => 25,
                'rates' => [
                    'basic_monthly' => 5000,
                    'housing_monthly' => 2000,
                    'transport_monthly' => 1000,
                    'other_monthly' => 500,
                ],
                'lines' => [
                    'basic' => 4166.67,
                    'housing' => 1666.67,
                    'transport' => 833.33,
                    'other' => 416.67,
                    'unpaid_leave_deduction' => 1416.67,
                ],
            ],
        ]);

    $result = app(CrewPayrollSalarySheetExporter::class)->export($company->id, $period->fresh());
    $sheet = IOFactory::load($result['path'])->getSheetByName(CrewPayrollSalarySheetExporter::SHEET_NAME);

    expect($sheet->getCell('V2')->getValue())->toBe('SALARY STRUCTURE')
        ->and($sheet->getCell('V3')->getValue())->toBe('Monthly');

    @unlink($result['path']);
});

test('crew payroll salary sheet export highlights missing client in red', function () {
    ['company' => $company] = makePayrollFixtures();

    [$period, $employee] = createApprovedCrewExportFixture($company, withClient: false);

    $result = app(CrewPayrollSalarySheetExporter::class)->export($company->id, $period->fresh());
    $sheet = IOFactory::load($result['path'])->getSheetByName(CrewPayrollSalarySheetExporter::SHEET_NAME);

    expect($sheet->getStyle('E3')->getFill()->getFillType())->toBe(Fill::FILL_SOLID)
        ->and($sheet->getStyle('E3')->getFill()->getStartColor()->getRGB())->toBe('FF0000');

    Employee::query()
        ->whereKey($employee->id)
        ->update([
            'client_id' => Client::query()->create([
                'name' => 'TARGET',
                'is_active' => true,
            ])->id,
        ]);

    $resultWithClient = app(CrewPayrollSalarySheetExporter::class)->export($company->id, $period->fresh());
    $sheetWithClient = IOFactory::load($resultWithClient['path'])->getSheetByName(CrewPayrollSalarySheetExporter::SHEET_NAME);

    expect($sheetWithClient->getCell('E3')->getValue())->toBe('TARGET')
        ->and($sheetWithClient->getStyle('E3')->getFill()->getStartColor()->getRGB())->not->toBe('FF0000');

    @unlink($result['path']);
    @unlink($resultWithClient['path']);
});

test('approved crew payroll show page exposes export permission flag', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.periods.view',
        'payroll.crew_timesheets.view',
    ]);

    [$period] = createApprovedCrewExportFixture($company);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', ['payrollPeriod' => $period, 'tab' => 'payroll']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('permissions.export_payroll', true)
        );
});

test('paid crew payroll show page exposes export permission flag', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.periods.view',
        'payroll.crew_timesheets.view',
    ]);

    [$period] = createApprovedCrewExportFixture($company);
    $period->update(['status' => PayrollPeriodStatus::Paid]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', ['payrollPeriod' => $period->fresh(), 'tab' => 'payroll']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('permissions.export_payroll', true)
        );
});

/**
 * @return array{0: PayrollPeriod, 1: Employee, 2: Client, 3: Project}
 */
function createApprovedCrewExportFixture($company, bool $withClient = true): array
{
    $period = PayrollPeriod::factory()->for($company)->approved()->create([
        'name' => 'June 2026 Crew',
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
    ]);

    $position = Position::query()->create([
        'company_id' => $company->id,
        'title' => 'JUB PORT CAPT.',
        'status' => 'active',
    ]);

    $project = Project::query()->create([
        'title' => 'CREWING',
        'is_active' => true,
    ]);

    $client = Client::query()->create([
        'name' => 'NMDC ENERGY',
        'is_active' => true,
    ]);

    $employee = createCrewEmployeeWithContract($company, '2025', 0, 0, 0);
    $employee->update([
        'name' => 'VINOD MENON',
        'position_id' => $position->id,
        'project_id' => $project->id,
    ]);

    if ($withClient) {
        $employee->update(['client_id' => $client->id]);
    }

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'standby_from' => null,
        'standby_to' => null,
        'standby_days' => 0,
        'onsite_from' => '2026-06-01',
        'onsite_to' => '2026-06-30',
        'onsite_days' => 30,
    ]);

    PayrollRecord::factory()->for($company)->create([
        'period_id' => $period->id,
        'employee_id' => $employee->id,
        'payroll_category' => PayrollCategory::Crew,
        'basic_salary' => '0.00',
        'other_allowances' => '0.00',
        'overtime_pay' => '0.00',
        'bonus' => '0.00',
        'other_deductions' => '0.00',
        'total_deductions' => '0.00',
        'gross_salary' => '55000.00',
        'net_salary' => '55000.00',
        'status' => 'approved',
        'calculation_breakdown' => [
            'salary_structure' => 'daily',
            'standby_days' => 0,
            'onsite_days' => 30,
            'rates' => [
                'basic_daily' => 0,
                'site_allowance_daily' => 0,
                'supplementary_allowance_daily' => 0,
            ],
            'lines' => [
                'standby_pay' => 0,
                'onsite_pay' => 0,
                'site_allowance' => 0,
                'supplementary_allowance' => 0,
            ],
        ],
    ]);

    return [$period, $employee, $client, $project];
}
