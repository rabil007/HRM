<?php

use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeContract;
use App\Models\EmployeeSeaService;
use App\Models\EmployeeTraining;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\User;

/**
 * @return array{
 *     employee: Employee,
 *     contract: EmployeeContract,
 *     seaService: EmployeeSeaService,
 *     training: EmployeeTraining,
 *     primaryBankAccount: EmployeeBankAccount,
 *     secondaryBankAccount: EmployeeBankAccount
 * }
 */
function makeOrganizationBulkRecordFixtures(): array
{
    $employee = Employee::factory()->create();
    $contract = $employee->contracts()->firstOrFail();
    $seaService = EmployeeSeaService::factory()->forEmployee($employee)->create();
    $training = EmployeeTraining::factory()->forEmployee($employee)->create();
    $primaryBankAccount = EmployeeBankAccount::query()->create([
        'company_id' => $employee->company_id,
        'employee_id' => $employee->id,
        'iban' => 'AE100000000000001',
        'account_name' => $employee->name,
        'is_primary' => true,
    ]);
    $secondaryBankAccount = EmployeeBankAccount::query()->create([
        'company_id' => $employee->company_id,
        'employee_id' => $employee->id,
        'iban' => 'AE100000000000002',
        'account_name' => $employee->name,
        'is_primary' => false,
    ]);

    return compact(
        'employee',
        'contract',
        'seaService',
        'training',
        'primaryBankAccount',
        'secondaryBankAccount',
    );
}

test('authorized users can bulk delete organization records', function () {
    $user = User::factory()->create();
    $fixtures = makeOrganizationBulkRecordFixtures();
    $company = $fixtures['employee']->company;

    grantCompanyPermissions($user, $company, [
        'sea_services.view',
        'sea_services.delete',
        'training.view',
        'training.delete',
        'bank_accounts.view',
        'bank_accounts.delete',
        'contracts.view',
        'contracts.delete',
    ]);

    $this->actingAs($user)
        ->delete(route('organization.sea-services.bulk-destroy'), [
            'ids' => [$fixtures['seaService']->id],
        ])
        ->assertRedirect();

    $this->delete(route('organization.training.bulk-destroy'), [
        'ids' => [$fixtures['training']->id],
    ])->assertRedirect();

    $this->delete(route('organization.bank-accounts.bulk-destroy'), [
        'ids' => [$fixtures['primaryBankAccount']->id],
    ])->assertRedirect();

    $this->delete(route('organization.contracts.bulk-destroy'), [
        'ids' => [$fixtures['contract']->id],
    ])->assertRedirect();

    $this->assertSoftDeleted($fixtures['seaService']);
    $this->assertSoftDeleted($fixtures['training']);
    $this->assertSoftDeleted($fixtures['primaryBankAccount']);
    $this->assertSoftDeleted($fixtures['contract']);
    expect($fixtures['secondaryBankAccount']->fresh()->is_primary)->toBeTrue();
});

test('bulk delete routes require their module delete permission', function () {
    $user = User::factory()->create();
    $fixtures = makeOrganizationBulkRecordFixtures();

    grantCompanyPermissions($user, $fixtures['employee']->company, [
        'sea_services.view',
        'training.view',
        'bank_accounts.view',
        'contracts.view',
    ]);

    $requests = [
        'organization.sea-services.bulk-destroy' => $fixtures['seaService']->id,
        'organization.training.bulk-destroy' => $fixtures['training']->id,
        'organization.bank-accounts.bulk-destroy' => $fixtures['primaryBankAccount']->id,
        'organization.contracts.bulk-destroy' => $fixtures['contract']->id,
    ];

    foreach ($requests as $routeName => $recordId) {
        $this->actingAs($user)
            ->delete(route($routeName), ['ids' => [$recordId]])
            ->assertForbidden();
    }
});

test('bulk delete rejects a mixed-company selection without deleting any records', function () {
    $user = User::factory()->create();
    $companyFixtures = makeOrganizationBulkRecordFixtures();
    $otherCompanyFixtures = makeOrganizationBulkRecordFixtures();

    grantCompanyPermissions($user, $companyFixtures['employee']->company, [
        'sea_services.view',
        'sea_services.delete',
    ]);

    $this->actingAs($user)
        ->delete(route('organization.sea-services.bulk-destroy'), [
            'ids' => [
                $companyFixtures['seaService']->id,
                $otherCompanyFixtures['seaService']->id,
            ],
        ])
        ->assertNotFound();

    $this->assertNotSoftDeleted($companyFixtures['seaService']);
    $this->assertNotSoftDeleted($otherCompanyFixtures['seaService']);
});

test('bulk delete preserves bank accounts and contracts linked to payroll records', function () {
    $user = User::factory()->create();
    $fixtures = makeOrganizationBulkRecordFixtures();
    $company = $fixtures['employee']->company;
    $period = PayrollPeriod::factory()->for($company)->create();

    PayrollRecord::factory()
        ->for($company)
        ->for($fixtures['employee'])
        ->for($period, 'period')
        ->create([
            'contract_id' => $fixtures['contract']->id,
            'employee_bank_account_id' => $fixtures['primaryBankAccount']->id,
        ]);

    grantCompanyPermissions($user, $company, [
        'bank_accounts.view',
        'bank_accounts.delete',
        'contracts.view',
        'contracts.delete',
    ]);

    $this->actingAs($user)
        ->from(route('organization.bank-accounts'))
        ->delete(route('organization.bank-accounts.bulk-destroy'), [
            'ids' => [$fixtures['primaryBankAccount']->id],
        ])
        ->assertRedirect(route('organization.bank-accounts'))
        ->assertSessionHasErrors('bulk_delete');

    $this->from(route('organization.contracts'))
        ->delete(route('organization.contracts.bulk-destroy'), [
            'ids' => [$fixtures['contract']->id],
        ])
        ->assertRedirect(route('organization.contracts'))
        ->assertSessionHasErrors('bulk_delete');

    $this->assertNotSoftDeleted($fixtures['primaryBankAccount']);
    $this->assertNotSoftDeleted($fixtures['contract']);
});
