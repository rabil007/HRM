<?php

use App\Models\Bank;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Support\BankAccounts\BankAccountDepartmentTree;
use App\Support\Employees\BuildDepartmentEmployeeTree;
use App\Support\Employees\EmployeeDirectoryFilters;

test('bank account index department tree counts only employees with bank accounts', function () {
    $country = Country::query()->create([
        'code' => 'BDT',
        'name' => 'Bank Tree Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'BDT',
        'name' => 'Bank Tree Currency',
        'symbol' => 'B$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Bank Tree Co',
        'slug' => 'bank-tree-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $officeDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Office',
        'parent_id' => null,
    ]);

    $offshoreDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Offshore',
        'parent_id' => null,
    ]);

    $officeEmployees = Employee::factory()->forCompany($company)->count(2)->create([
        'department_id' => $officeDepartment->id,
    ]);

    Employee::factory()->forCompany($company)->count(3)->create([
        'department_id' => $offshoreDepartment->id,
    ]);

    $bank = Bank::query()->create([
        'name' => 'Tree Bank',
        'is_active' => true,
    ]);

    foreach ($officeEmployees as $employee) {
        EmployeeBankAccount::query()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'bank_id' => $bank->id,
            'iban' => 'AE'.fake()->unique()->numerify('####################'),
            'account_name' => $employee->name,
            'is_primary' => true,
        ]);
    }

    $filters = new EmployeeDirectoryFilters;
    $allEmployeesTree = BuildDepartmentEmployeeTree::for($company->id, $filters);
    $indexTree = BankAccountDepartmentTree::for(
        $company->id,
        $filters,
        BankAccountDepartmentTree::CONTEXT_INDEX,
    );

    $allNode = collect($allEmployeesTree)->firstWhere('id', null);
    $indexAllNode = collect($indexTree)->firstWhere('id', null);
    $officeNode = collect($indexTree)->firstWhere('id', $officeDepartment->id);
    $offshoreNode = collect($indexTree)->firstWhere('id', $offshoreDepartment->id);

    expect($allNode['count'])->toBe(5)
        ->and($indexAllNode['count'])->toBe(2)
        ->and($officeNode['count'])->toBe(2)
        ->and($offshoreNode['count'])->toBe(0);
});

test('bank account no-account department tree counts only employees without bank accounts', function () {
    $country = Country::query()->create([
        'code' => 'BNT',
        'name' => 'No Bank Tree Land',
        'dial_code' => '+972',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'BNT',
        'name' => 'No Bank Tree Currency',
        'symbol' => 'N$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'No Bank Tree Co',
        'slug' => 'no-bank-tree-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $officeDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Office',
        'parent_id' => null,
    ]);

    $offshoreDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Offshore',
        'parent_id' => null,
    ]);

    $officeEmployees = Employee::factory()->forCompany($company)->count(2)->create([
        'department_id' => $officeDepartment->id,
    ]);

    $offshoreEmployees = Employee::factory()->forCompany($company)->count(3)->create([
        'department_id' => $offshoreDepartment->id,
    ]);

    $bank = Bank::query()->create([
        'name' => 'No Tree Bank',
        'is_active' => true,
    ]);

    foreach ($offshoreEmployees as $employee) {
        EmployeeBankAccount::query()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'bank_id' => $bank->id,
            'iban' => 'AE'.fake()->unique()->numerify('####################'),
            'account_name' => $employee->name,
            'is_primary' => true,
        ]);
    }

    $filters = new EmployeeDirectoryFilters;
    $noAccountTree = BankAccountDepartmentTree::for(
        $company->id,
        $filters,
        BankAccountDepartmentTree::CONTEXT_NO_ACCOUNT,
    );

    $allNode = collect($noAccountTree)->firstWhere('id', null);
    $officeNode = collect($noAccountTree)->firstWhere('id', $officeDepartment->id);
    $offshoreNode = collect($noAccountTree)->firstWhere('id', $offshoreDepartment->id);

    expect($allNode['count'])->toBe(2)
        ->and($officeNode['count'])->toBe(2)
        ->and($offshoreNode['count'])->toBe(0);
});

test('bank account department tree rejects unknown context', function () {
    $country = Country::query()->create([
        'code' => 'BTX',
        'name' => 'Bad Context Land',
        'dial_code' => '+973',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'BTX',
        'name' => 'Bad Context Currency',
        'symbol' => 'X$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Bad Context Co',
        'slug' => 'bad-context-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    BankAccountDepartmentTree::for($company->id, new EmployeeDirectoryFilters, 'invalid');
})->throws(InvalidArgumentException::class);
