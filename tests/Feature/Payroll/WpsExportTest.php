<?php

use App\Enums\PayrollCategory;
use App\Enums\WpsStatus;
use App\Models\Bank;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Support\Payroll\Wps\WpsSifExporter;

test('wps export downloads sif file and marks records submitted', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    $company->forceFill([
        'wps_mol_uid' => 'MOL-12345',
        'wps_agent_code' => 'AGENT-001',
    ])->save();

    grantCompanyPermissions($user, $company, ['payroll.wps.export']);

    $period = PayrollPeriod::factory()->for($company)->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'WPS-001',
        'labor_card_number' => '12345678901234',
    ]);

    $bank = Bank::query()->create([
        'name' => 'WPS Bank',
        'uae_routing_code_agent_id' => '987654',
        'is_active' => true,
    ]);

    EmployeeBankAccount::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'bank_id' => $bank->id,
        'iban' => 'AE070331234567890123456',
        'account_name' => $employee->name,
        'is_primary' => true,
    ]);

    $record = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Office,
        'basic_salary' => 4000,
        'housing_allowance' => 1000,
        'net_salary' => 5000,
        'status' => 'approved',
        'working_days' => 30,
        'present_days' => 30,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.wps.export'), [
            'period_id' => $period->id,
        ])
        ->assertOk()
        ->assertHeader('content-disposition');

    $record->refresh();

    expect($record->wps_status)->toBe(WpsStatus::Submitted)
        ->and($record->wps_reference)->not->toBeNull()
        ->and($record->wps_submitted_at)->not->toBeNull();
});

test('wps sif exporter builds scr and edr lines', function () {
    ['company' => $company] = makePayrollFixtures();

    $company->forceFill([
        'wps_mol_uid' => 'MOL-999',
        'wps_agent_code' => 'AGENT-999',
        'timezone' => 'Asia/Dubai',
    ])->save();

    $period = PayrollPeriod::factory()->for($company)->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'labor_card_number' => '11112222333344',
    ]);

    $bank = Bank::query()->create([
        'name' => 'Export Bank',
        'uae_routing_code_agent_id' => '555666',
        'is_active' => true,
    ]);

    EmployeeBankAccount::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'bank_id' => $bank->id,
        'iban' => 'AE123456789012345678901',
        'account_name' => $employee->name,
        'is_primary' => true,
    ]);

    $record = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'basic_salary' => 3000,
        'housing_allowance' => 500,
        'net_salary' => 3500,
        'status' => 'approved',
        'present_days' => 31,
    ]);

    $content = app(WpsSifExporter::class)->export(
        $company,
        $period,
        collect([$record]),
        'REF-001',
    );

    expect($content)
        ->toContain('SCR,MOL-999,AGENT-999')
        ->toContain('EDR,11112222333344,555666,AE123456789012345678901')
        ->toContain('3500.00');
});
