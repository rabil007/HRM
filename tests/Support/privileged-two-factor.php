<?php

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\User;

function enablePrivilegedTwoFactorEnforcement(): void
{
    config(['security.privileged_two_factor.enforced' => true]);
}

function disablePrivilegedTwoFactorEnforcement(): void
{
    config(['security.privileged_two_factor.enforced' => false]);
}

function makeUnconfirmedTwoFactorUser(): User
{
    return User::factory()->create([
        'two_factor_secret' => encrypt('secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
        'two_factor_confirmed_at' => null,
    ]);
}

/**
 * @return array{0: PayrollPeriod, 1: Employee}
 */
function makeProcessingPayrollPeriodWithRecord(Company $company): array
{
    $period = PayrollPeriod::factory()->for($company)->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    EmployeeContract::factory()->create([
        'employee_id' => $employee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'status' => 'active',
    ]);

    PayrollRecord::factory()->for($company)->create([
        'period_id' => $period->id,
        'employee_id' => $employee->id,
    ]);

    return [$period, $employee];
}

/**
 * @return array<string, mixed>
 */
function privilegedTwoFactorWhatsAppPayload(array $overrides = []): array
{
    return array_merge([
        'business_account_id' => '123456789',
        'phone_number_id' => '987654321',
        'access_token' => 'test-access-token',
        'app_id' => 'app-id-123',
        'app_secret' => 'test-app-secret',
        'webhook_verify_token' => 'verify-token-abc',
        'enabled' => true,
    ], $overrides);
}
