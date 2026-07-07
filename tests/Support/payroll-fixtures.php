<?php

use App\Enums\ContractSalaryStructure;
use App\Enums\PayrollCategory;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\Position;
use App\Models\SalaryInputType;
use App\Models\User;
use App\Support\Payroll\Actions\SyncContractSalaryComponentsFromContract;
use App\Support\Payroll\ProvisionDefaultSalaryInputTypes;
use Illuminate\Support\Facades\DB;

/**
 * @return array{user: User, company: Company}
 */
function makePayrollFixtures(): array
{
    $user = User::factory()->create();
    $country = Country::query()->create([
        'code' => 'PP'.fake()->unique()->numerify('##'),
        'name' => 'Payroll Testland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'PP'.fake()->unique()->numerify('##'),
        'name' => 'Payroll Currency',
        'symbol' => 'P$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Payroll Co',
        'slug' => 'payroll-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    DB::table('company_user')->insert([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(ProvisionDefaultSalaryInputTypes::class)->handle($company->id);

    return ['user' => $user, 'company' => $company];
}

function createOfficeEmployeeWithContract(
    Company $company,
    string $employeeNo,
    float $basic,
    float $housing,
    float $transport,
    float $other,
): Employee {
    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => $employeeNo,
        'status' => 'active',
    ]);

    $contract = EmployeeContract::factory()->create([
        'employee_id' => $employee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Office,
        'status' => 'active',
        'basic_salary' => $basic,
        'housing_allowance' => $housing,
        'transport_allowance' => $transport,
        'other_allowances' => $other,
    ]);

    (new SyncContractSalaryComponentsFromContract)->handle($contract);

    return $employee;
}

function salaryInputTypeId(Company $company, string $code): int
{
    return (int) SalaryInputType::query()
        ->where('company_id', $company->id)
        ->where('code', $code)
        ->value('id');
}

function createCrewEmployeeWithContract(
    Company $company,
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

function createCrewMonthlyEmployeeWithContract(
    Company $company,
    string $employeeNo,
    float $basic,
    float $housing,
    float $transport,
    float $other,
): Employee {
    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => $employeeNo,
        'status' => 'active',
    ]);

    $contract = EmployeeContract::factory()->create([
        'employee_id' => $employee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'salary_structure' => ContractSalaryStructure::Monthly,
        'status' => 'active',
        'basic_salary' => $basic,
        'housing_allowance' => $housing,
        'transport_allowance' => $transport,
        'other_allowances' => $other,
    ]);

    (new SyncContractSalaryComponentsFromContract)->handle($contract);

    return $employee;
}

/**
 * @return array{0: PayrollPeriod, 1: Employee, 2: Department, 3: Position}
 */
function createApprovedOfficeExportFixture(Company $company, bool $withOrgData = true): array
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
