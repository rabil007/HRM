<?php

use App\Enums\PayrollCategory;
use App\Exports\ContractsExport;
use App\Exports\EmployeesExport;
use App\Exports\SeaServicesExport;
use App\Exports\TrainingsExport;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Country;
use App\Models\Course;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeSeaService;
use App\Models\EmployeeTraining;
use App\Models\Vessel;
use App\Models\VesselType;
use App\Services\Settings\SettingService;
use App\Support\Departments\DepartmentManagerExportContext;
use App\Support\Employees\EmployeeExportFieldResolver;
use App\Support\Employees\SeaServiceDuration;
use App\Support\Settings\PlatformDateFormatter;
use App\Support\Settings\SettingKey;

/**
 * Ambiguous calendar day used to prove export→import cannot flip month/day.
 * 2026-05-06 becomes 05/06/2026 under m/d/Y and 06/05/2026 under d/m/Y.
 */
const REIMPORT_AMBIGUOUS_DATE = '2026-05-06';

function makeReimportExportFixtures(): array
{
    $country = Country::query()->firstOrCreate(
        ['code' => 'REX'],
        ['name' => 'Reimport Export Land', 'dial_code' => '+971', 'is_active' => true],
    );
    $currency = Currency::query()->firstOrCreate(
        ['code' => 'REX'],
        ['name' => 'Reimport Export Currency', 'symbol' => 'R$', 'is_active' => true],
    );

    $company = Company::query()->create([
        'name' => 'Reimport Export Co',
        'slug' => 'reimport-export-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $branch = Branch::query()->create([
        'company_id' => $company->id,
        'name' => 'HQ',
        'code' => 'HQ',
        'status' => 'active',
        'is_headquarters' => true,
    ]);

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Ops',
        'code' => 'OPS',
        'status' => 'active',
    ]);

    $employee = Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'department_id' => $department->id,
        'employee_no' => 'REIMP-001',
        'name' => 'Reimport Employee',
        'status' => 'active',
        'date_of_birth' => REIMPORT_AMBIGUOUS_DATE,
        'hire_date' => REIMPORT_AMBIGUOUS_DATE,
    ]);

    return compact('company', 'branch', 'department', 'employee');
}

beforeEach(function () {
    app(SettingService::class)->set(SettingKey::DateFormat, 'm/d/Y');
});

test('employees export keeps date-only cells as iso under non-default platform format', function () {
    ['company' => $company, 'employee' => $employee] = makeReimportExportFixtures();

    $fields = ['date_of_birth', 'hire_date'];
    $export = new EmployeesExport(
        Employee::query()->whereKey($employee->id),
        new EmployeeExportFieldResolver(DepartmentManagerExportContext::forCompany($company->id)),
        $fields,
    );

    $row = $export->map($employee->fresh());

    expect($row)->toBe([REIMPORT_AMBIGUOUS_DATE, REIMPORT_AMBIGUOUS_DATE])
        ->and($row)->not->toContain('05/06/2026')
        ->and(PlatformDateFormatter::make()->format())->toBe('m/d/Y');
});

test('sea services export keeps start and end dates as iso', function () {
    ['company' => $company, 'employee' => $employee] = makeReimportExportFixtures();

    $vesselType = VesselType::query()->create([
        'name' => 'Reimport Type '.uniqid(),
        'is_active' => true,
    ]);
    $vessel = Vessel::query()->create([
        'company_id' => $company->id,
        'name' => 'MV Reimport '.uniqid(),
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);
    $duration = SeaServiceDuration::fromDates(REIMPORT_AMBIGUOUS_DATE, '2026-06-05');
    $seaService = EmployeeSeaService::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'vessel_type_id' => $vesselType->id,
        'vessel_id' => $vessel->id,
        'start_date' => REIMPORT_AMBIGUOUS_DATE,
        'end_date' => '2026-06-05',
        'total_months' => $duration['months'],
        'total_days' => $duration['days'],
        'sort_order' => 0,
    ]);

    $loaded = $seaService->fresh(['employee.department', 'vessel', 'vesselType', 'position', 'client']);
    $row = (new SeaServicesExport(collect([$loaded])))->map($loaded);

    expect($row[7])->toBe(REIMPORT_AMBIGUOUS_DATE)
        ->and($row[8])->toBe('2026-06-05')
        ->and($row)->not->toContain('05/06/2026');
});

test('trainings export keeps issue and expiry dates as iso', function () {
    ['company' => $company, 'employee' => $employee] = makeReimportExportFixtures();

    $course = Course::query()->create([
        'name' => 'Reimport Course '.uniqid(),
        'is_active' => true,
    ]);
    $training = EmployeeTraining::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'course_id' => $course->id,
        'issue_date' => REIMPORT_AMBIGUOUS_DATE,
        'expiry_date' => '2027-05-06',
        'sort_order' => 0,
    ]);

    $loaded = $training->fresh(['employee.department', 'employee.position', 'course', 'country']);
    $row = (new TrainingsExport(
        EmployeeTraining::query()->whereKey($training->id),
    ))->map($loaded);

    expect($row[5])->toBe(REIMPORT_AMBIGUOUS_DATE)
        ->and($row[6])->toBe('2027-05-06')
        ->and($row)->not->toContain('05/06/2026');
});

test('contracts export keeps start and end dates as iso', function () {
    ['company' => $company, 'employee' => $employee] = makeReimportExportFixtures();

    $contract = EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'payroll_category' => PayrollCategory::Office->value,
        'start_date' => REIMPORT_AMBIGUOUS_DATE,
        'end_date' => '2027-05-06',
        'status' => 'active',
        'basic_salary' => 5000,
    ]);

    $loaded = $contract->fresh(['employee.department', 'employee.position', 'companyVisaType']);
    $row = (new ContractsExport(
        EmployeeContract::query()->whereKey($contract->id),
        'office',
    ))->map($loaded);

    expect($row[6])->toBe(REIMPORT_AMBIGUOUS_DATE)
        ->and($row[7])->toBe('2027-05-06')
        ->and($row)->not->toContain('05/06/2026');
});

test('platform formatter still renders ambiguous date in display format for report channels', function () {
    expect(PlatformDateFormatter::make()->date(REIMPORT_AMBIGUOUS_DATE))->toBe('05/06/2026');
});
