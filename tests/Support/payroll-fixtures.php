<?php

use App\Enums\PayrollCategory;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeContract;
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
