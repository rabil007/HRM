<?php

use App\Enums\PayrollCategory;
use App\Mail\PayslipMail;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Support\Payroll\Actions\GeneratePayslip;
use Database\Seeders\EmailTemplatesSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

test('authorized users can generate payslip pdfs', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.payslips.view',
        'payroll.payslips.generate',
    ]);

    Storage::fake('local');

    $period = PayrollPeriod::factory()->for($company)->create();
    $employee = Employee::factory()->forCompany($company)->create(['employee_no' => 'PAY-001']);
    $record = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Office,
        'status' => 'approved',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.payslips.generate'), [
            'record_ids' => [$record->id],
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $record->refresh();

    expect($record->payslip_path)->not->toBeNull()
        ->and(Storage::disk('local')->exists((string) $record->payslip_path))->toBeTrue();
});

test('authorized users can download payslips', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.payslips.view']);

    Storage::fake('local');

    $period = PayrollPeriod::factory()->for($company)->create();
    $employee = Employee::factory()->forCompany($company)->create(['employee_no' => 'PAY-002']);
    $record = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'status' => 'approved',
    ]);

    app(GeneratePayslip::class)->handle($record);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.payslips.download', $record))
        ->assertOk()
        ->assertHeader('content-disposition');
});

test('bulk payslip email queues messages for employees with payslip pdfs', function () {
    Mail::fake();

    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.payslips.email',
    ]);

    Storage::fake('local');

    EmailTemplatesSeeder::seedPayslipDeliveryTemplate();

    $period = PayrollPeriod::factory()->for($company)->create();
    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'PAY-003',
        'work_email' => 'crew@example.com',
    ]);
    $record = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'status' => 'approved',
        'net_salary' => 5000,
    ]);

    app(GeneratePayslip::class)->handle($record);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.payslips.email'), [
            'record_ids' => [$record->id],
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    Mail::assertQueued(PayslipMail::class);
});

test('authorized users can generate all payslips for a pay period', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.payslips.view',
        'payroll.payslips.generate',
    ]);

    Storage::fake('local');

    $period = PayrollPeriod::factory()->for($company)->create();
    $firstEmployee = Employee::factory()->forCompany($company)->create(['employee_no' => 'PAY-010']);
    $secondEmployee = Employee::factory()->forCompany($company)->create(['employee_no' => 'PAY-011']);

    PayrollRecord::factory()->for($company)->create([
        'employee_id' => $firstEmployee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Office,
        'status' => 'approved',
    ]);

    PayrollRecord::factory()->for($company)->create([
        'employee_id' => $secondEmployee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Office,
        'status' => 'approved',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.payslips.generate'), [
            'period_id' => $period->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(PayrollRecord::query()->where('period_id', $period->id)->whereNotNull('payslip_path')->count())->toBe(2);
});
