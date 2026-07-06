<?php

use App\Enums\PayrollCategory;
use App\Models\Bank;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;

test('employees linked to pay runs cannot be deleted', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['employees.delete']);

    $employee = Employee::factory()->forCompany($company)->create();
    $period = PayrollPeriod::factory()->for($company)->create();

    PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Office,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->delete(route('organization.employees.destroy', $employee))
        ->assertRedirect(route('organization.employees'))
        ->assertSessionHasErrors('employee');

    $this->assertDatabaseHas('employees', ['id' => $employee->id, 'deleted_at' => null]);
});

test('contracts linked to pay runs cannot be deleted', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['contracts.delete']);

    $employee = Employee::factory()->forCompany($company)->create();
    $contract = EmployeeContract::factory()->create([
        'employee_id' => $employee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Office,
        'status' => 'active',
    ]);

    $period = PayrollPeriod::factory()->for($company)->create();

    PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'contract_id' => $contract->id,
        'payroll_category' => PayrollCategory::Office,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->from(route('organization.employees.show', $employee))
        ->delete(route('organization.employees.contracts.destroy', [$employee, $contract]))
        ->assertRedirect(route('organization.employees.show', $employee))
        ->assertSessionHasErrors('employee_contract');

    $this->assertDatabaseHas('employee_contracts', ['id' => $contract->id, 'deleted_at' => null]);
});

test('banks linked to pay runs cannot be deleted', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.banks.view',
        'settings.master-data.banks.delete',
    ]);

    $employee = Employee::factory()->forCompany($company)->create();
    $bank = Bank::query()->create([
        'name' => 'Linked Payroll Bank',
        'uae_routing_code_agent_id' => '445566',
        'is_active' => true,
    ]);
    $period = PayrollPeriod::factory()->for($company)->create();

    PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'bank_id' => $bank->id,
        'payroll_category' => PayrollCategory::Office,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->delete(route('settings.master-data.banks.destroy', $bank))
        ->assertRedirect(route('settings.master-data.banks.index'))
        ->assertSessionHasErrors('bank');

    $this->assertDatabaseHas('banks', ['id' => $bank->id, 'deleted_at' => null]);
});

test('employee bank accounts linked to pay runs cannot be deleted', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['employees.bank_accounts.manage', 'bank_accounts.delete']);

    $employee = Employee::factory()->forCompany($company)->create();
    $bank = Bank::query()->create([
        'name' => 'Account Link Bank',
        'uae_routing_code_agent_id' => '778899',
        'is_active' => true,
    ]);
    $bankAccount = EmployeeBankAccount::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'bank_id' => $bank->id,
        'iban' => 'AE070331234567890123477',
        'account_name' => 'Linked Account',
        'is_primary' => true,
    ]);
    $period = PayrollPeriod::factory()->for($company)->create();

    PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'bank_id' => $bank->id,
        'employee_bank_account_id' => $bankAccount->id,
        'payroll_category' => PayrollCategory::Office,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->from(route('organization.employees.show', $employee))
        ->delete(route('organization.employees.bank-accounts.destroy', [$employee, $bankAccount]))
        ->assertRedirect(route('organization.employees.show', $employee))
        ->assertSessionHasErrors('employee_bank_account');

    $this->assertDatabaseHas('employee_bank_accounts', ['id' => $bankAccount->id, 'deleted_at' => null]);
});
