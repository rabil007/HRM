<?php

use App\Enums\PayrollCategory;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Support\Payroll\Actions\GeneratePayslip;
use App\Support\Payroll\PayslipData;
use Illuminate\Support\Facades\Storage;

test('authorized users can download payslips', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.records.view']);

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

test('authorized users can view payslips as inline pdf', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.records.view']);

    Storage::fake('local');

    $period = PayrollPeriod::factory()->for($company)->create();
    $employee = Employee::factory()->forCompany($company)->create(['employee_no' => 'PAY-003']);
    $record = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'status' => 'approved',
    ]);

    app(GeneratePayslip::class)->handle($record);

    $response = $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.payslips.show', $record));

    $response->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    expect(str_contains(strtolower((string) $response->headers->get('content-disposition')), 'inline'))->toBeTrue();
});

test('authorized users can preview payslip html when requested', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.records.view']);

    $period = PayrollPeriod::factory()->for($company)->create();
    $employee = Employee::factory()->forCompany($company)->create(['employee_no' => 'PAY-004']);
    $record = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'status' => 'approved',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.payslips.show', ['payrollRecord' => $record, 'view' => 'html']))
        ->assertOk()
        ->assertSee('Salary Slip - '.$employee->name, false);
});

test('inertia requests to payslip show force a full page visit', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.records.view']);

    $period = PayrollPeriod::factory()->for($company)->create();
    $employee = Employee::factory()->forCompany($company)->create(['employee_no' => 'PAY-005']);
    $record = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'status' => 'approved',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.payslips.show', $record), [
            'X-Inertia' => 'true',
        ])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', route('payroll.payslips.show', $record));
});

test('payslip data embeds company logo as data uri for pdf rendering', function () {
    Storage::fake('public');

    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    Storage::disk('public')->put('logos/company.png', $png);
    $company->update(['logo' => 'logos/company.png']);

    $period = PayrollPeriod::factory()->for($company)->create();
    $employee = Employee::factory()->forCompany($company)->create(['employee_no' => 'PAY-020']);
    $record = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Office,
        'status' => 'approved',
    ]);

    $data = PayslipData::for($record, $company->id);

    expect($data['company_logo'])
        ->toStartWith('data:image/png;base64,')
        ->and(base64_decode(substr((string) $data['company_logo'], strlen('data:image/png;base64,'))))
        ->toBe($png);
});

test('office payslip earnings always include core salary components even when zero', function () {
    ['company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->create();
    $employee = Employee::factory()->forCompany($company)->create(['employee_no' => 'PAY-021']);
    $record = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Office,
        'basic_salary' => 4000,
        'housing_allowance' => 0,
        'transport_allowance' => 0,
        'other_allowances' => 0,
        'overtime_pay' => 0,
        'bonus' => 0,
        'gross_salary' => 4000,
        'net_salary' => 4000,
        'status' => 'approved',
    ]);

    $data = PayslipData::for($record, $company->id);

    expect($data['earnings'])->toHaveCount(4)
        ->and(collect($data['earnings'])->pluck('label')->all())->toBe([
            'Basic salary',
            'Housing allowance',
            'Transport allowance',
            'Other allowances',
        ])
        ->and(collect($data['earnings'])->pluck('amount')->all())->toBe([
            '4000.00',
            '0.00',
            '0.00',
            '0.00',
        ]);
});

test('crew payslip includes overtime calculation breakdown', function () {
    ['company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
    ]);
    $employee = Employee::factory()->forCompany($company)->create(['employee_no' => 'CREW-OT']);
    $record = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Crew,
        'overtime_hours' => 98,
        'overtime_pay' => 3523.97,
        'gross_salary' => 14082.60,
        'net_salary' => 14082.60,
        'status' => 'approved',
        'calculation_breakdown' => [
            'standby_days' => 30,
            'onsite_days' => 33.5,
            'lines' => [
                'standby_pay' => 0,
                'onsite_pay' => 0,
                'site_allowance' => 0,
                'supplementary_allowance' => 0,
                'overtime' => 3523.97,
            ],
            'overtime' => [
                'hours' => 98,
                'period_days' => 30,
                'daily_onsite_rate' => 350,
                'monthly_salary' => 10500,
                'hour_rate' => 28.77,
                'overtime_hourly_rate' => 35.96,
                'overtime_pay' => 3523.97,
            ],
        ],
    ]);

    $data = PayslipData::for($record, $company->id);

    expect($data['overtime'])->not->toBeNull()
        ->and($data['overtime']['hours'])->toBe('98')
        ->and($data['overtime']['monthly_salary'])->toBe('10500.00')
        ->and($data['overtime']['monthly_base_formula'])->toBe('30 × 350.00')
        ->and($data['overtime']['overtime_formula'])->toBe('98 × 35.96')
        ->and($data['overtime']['overtime_pay'])->toBe('3523.97')
        ->and(collect($data['earnings'])->firstWhere('label', 'Overtime (98 hrs)'))->not->toBeNull();

    $html = view('payroll.payslip', $data)->render();

    expect($html)
        ->toContain('Overtime Calculation')
        ->toContain('30 × 350.00 = 10500.00')
        ->toContain('98 × 35.96')
        ->toContain('3523.97')
        ->toContain('Crew Attendance');
});
