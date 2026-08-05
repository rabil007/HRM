<?php

use App\Enums\PayrollCategory;
use App\Jobs\GeneratePayrollPayslipsJob;
use App\Mail\PayslipMail;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Support\Payroll\Actions\GeneratePayslip;
use App\Support\Payroll\PayslipData;
use Database\Seeders\EmailTemplatesSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

test('authorized users can generate payslip pdfs', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
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
        ->assertSee('Salary Slip - '.$employee->name);
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
        'payroll.payslips.generate',
    ]);

    Storage::fake('local');
    Queue::fake();

    $period = PayrollPeriod::factory()->for($company)->create();
    $firstEmployee = Employee::factory()->forCompany($company)->create(['employee_no' => 'PAY-010']);
    $secondEmployee = Employee::factory()->forCompany($company)->create(['employee_no' => 'PAY-011']);

    $firstRecord = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $firstEmployee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Office,
        'status' => 'approved',
        'payslip_path' => 'payslips/'.$company->id.'/'.$period->id.'/old.pdf',
    ]);

    Storage::disk('local')->put((string) $firstRecord->payslip_path, 'old-payslip');

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

    expect(PayrollRecord::query()->where('period_id', $period->id)->whereNotNull('payslip_path')->count())->toBe(0)
        ->and(Storage::disk('local')->exists('payslips/'.$company->id.'/'.$period->id.'/old.pdf'))->toBeFalse();

    Queue::assertPushed(GeneratePayrollPayslipsJob::class, 1);
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

test('crew payslip shows overtime in earnings without calculation breakdown', function () {
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
            'total_standby_days' => 30,
            'onsite_days' => 33.5,
            'lines' => [
                'total_standby_pay' => 0,
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

    expect($data)->not->toHaveKey('overtime')
        ->and(collect($data['earnings'])->firstWhere('label', 'Overtime (98 hrs)'))->not->toBeNull()
        ->and(collect($data['earnings'])->firstWhere('label', 'Overtime (98 hrs)')['amount'])->toBe('3523.97');

    $html = view('payroll.payslip', $data)->render();

    expect($html)
        ->not->toContain('Overtime Calculation')
        ->not->toContain('Monthly base (days × daily onsite rate)')
        ->not->toContain('Hour rate (monthly base ÷ 365)')
        ->not->toContain('Overtime hourly rate (hour rate × 1.25)')
        ->toContain('Overtime (98 hrs)')
        ->toContain('3523.97')
        ->toContain('Crew Attendance');
});

test('crew payslip presentation lines include detail and blade renders it', function () {
    ['company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $employee = Employee::factory()->forCompany($company)->create(['employee_no' => 'CREW-DET']);
    $record = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Crew,
        'gross_salary' => 350.00,
        'net_salary' => 350.00,
        'total_deductions' => 0,
        'status' => 'approved',
        'calculation_breakdown' => [
            'salary_structure' => 'daily',
            'presentation_lines' => [
                [
                    'from_date' => '2026-06-28',
                    'to_date' => '2026-06-29',
                    'days' => 2,
                    'pay_category' => 'onsite',
                    'period_classification' => 'prior',
                    'basic_daily_rate' => 100,
                    'site_allowance_daily_rate' => 50,
                    'supplementary_allowance_daily_rate' => 25,
                    'amount' => 350,
                ],
            ],
            'lines' => [
                'prior_period_amount' => 350,
            ],
        ],
    ]);

    $data = PayslipData::for($record, $company->id);
    $earning = collect($data['earnings'])->firstWhere('label', 'Prior-period onsite pay');

    expect($earning)->not->toBeNull()
        ->and($earning['detail'] ?? null)->toContain('28 Jun 2026')
        ->and($earning['detail'] ?? null)->toContain('2 days')
        ->and($earning['detail'] ?? null)->toContain('basic 100.00')
        ->and($earning['detail'] ?? null)->toContain('site 50.00')
        ->and($earning['detail'] ?? null)->toContain('supp 25.00');

    $html = view('payroll.payslip', $data)->render();

    expect($html)
        ->toContain('Prior-period onsite pay')
        ->toContain('line-detail')
        ->toContain('28 Jun 2026');
});

test('crew payslip combines sign-on and sign-off standby into total standby pay', function () {
    ['company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
    ]);
    $employee = Employee::factory()->forCompany($company)->create(['employee_no' => 'CREW-SB']);
    $record = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Crew,
        'gross_salary' => 850.00,
        'net_salary' => 850.00,
        'total_deductions' => 0,
        'status' => 'approved',
        'calculation_breakdown' => [
            'salary_structure' => 'daily',
            'total_standby_days' => 6,
            'sign_on_standby_days' => 4,
            'sign_off_standby_days' => 2,
            'onsite_days' => 1,
            'lines' => [
                'sign_on_standby_pay' => 200.00,
                'sign_off_standby_pay' => 100.00,
                'total_standby_pay' => 300.00,
                'onsite_pay' => 550.00,
                'site_allowance' => 0,
                'supplementary_allowance' => 0,
            ],
        ],
    ]);

    $data = PayslipData::for($record, $company->id);
    $earningsLabels = collect($data['earnings'])->pluck('label')->all();
    $totalStandbyLine = collect($data['earnings'])->firstWhere('label', 'Total standby pay');

    expect($totalStandbyLine['amount'] ?? null)->toBe('300.00')
        ->and($earningsLabels)->toContain('Total standby pay')
        ->and($earningsLabels)->not->toContain('Standby pay')
        ->and($earningsLabels)->not->toContain('Sign-off standby pay')
        ->and($data['gross_salary'])->toBe('850.00')
        ->and($data['net_salary'])->toBe('850.00')
        ->and($data['crew_summary'])->toContain([
            'label' => 'Total standby days',
            'value' => '6',
        ])
        ->and($record->calculation_breakdown['lines']['sign_on_standby_pay'])->toEqual(200.00)
        ->and($record->calculation_breakdown['lines']['sign_off_standby_pay'])->toEqual(100.00)
        ->and($record->calculation_breakdown['lines']['total_standby_pay'])->toEqual(300.00);

    $html = view('payroll.payslip', $data)->render();

    expect($html)
        ->toContain('Total standby pay')
        ->toContain('300.00')
        ->not->toContain('Sign-off standby pay')
        ->not->toContain('>Standby pay<');
});

test('crew payslip omits total standby pay when both standby amounts are zero', function () {
    ['company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
    ]);
    $employee = Employee::factory()->forCompany($company)->create(['employee_no' => 'CREW-SB-0']);
    $record = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Crew,
        'gross_salary' => 500.00,
        'net_salary' => 500.00,
        'calculation_breakdown' => [
            'salary_structure' => 'daily',
            'total_standby_days' => 0,
            'onsite_days' => 2,
            'lines' => [
                'sign_on_standby_pay' => 0,
                'sign_off_standby_pay' => 0,
                'total_standby_pay' => 0,
                'onsite_pay' => 500.00,
            ],
        ],
    ]);

    $data = PayslipData::for($record, $company->id);

    expect(collect($data['earnings'])->pluck('label')->all())
        ->not->toContain('Total standby pay')
        ->toContain('Onsite pay');
});

test('authorized users can download all generated payslips as a ZIP file', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.records.view']);

    Storage::fake('local');

    $period = PayrollPeriod::factory()->for($company)->create();
    $employee = Employee::factory()->forCompany($company)->create(['employee_no' => 'PAY-ZIP-1']);
    $record = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'status' => 'approved',
    ]);

    app(GeneratePayslip::class)->handle($record);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.payslips.download-zip', ['period_id' => $period->id]))
        ->assertOk()
        ->assertHeader('content-disposition');
});

test('authorized users can download all generated payslips as one merged pdf', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.records.view']);

    Storage::fake('local');

    $period = PayrollPeriod::factory()->for($company)->create();

    $firstEmployee = Employee::factory()->forCompany($company)->create(['employee_no' => 'PAY-PDF-1']);
    $secondEmployee = Employee::factory()->forCompany($company)->create(['employee_no' => 'PAY-PDF-2']);

    $firstPath = "payslips/{$company->id}/{$period->id}/{$firstEmployee->id}.pdf";
    $secondPath = "payslips/{$company->id}/{$period->id}/{$secondEmployee->id}.pdf";

    Storage::disk('local')->put($firstPath, minimalPdfBytes());
    Storage::disk('local')->put($secondPath, minimalPdfBytes());

    PayrollRecord::factory()->for($company)->create([
        'employee_id' => $firstEmployee->id,
        'period_id' => $period->id,
        'status' => 'approved',
        'payslip_path' => $firstPath,
    ]);

    PayrollRecord::factory()->for($company)->create([
        'employee_id' => $secondEmployee->id,
        'period_id' => $period->id,
        'status' => 'approved',
        'payslip_path' => $secondPath,
    ]);

    $response = $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.payslips.download-pdf', ['period_id' => $period->id]));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
    expect(str_starts_with($response->streamedContent(), '%PDF'))->toBeTrue();
});

test('merged payslip pdf download redirects when no generated payslips exist', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.records.view']);

    $period = PayrollPeriod::factory()->for($company)->create();

    $this->withSession(['current_company_id' => $company->id])
        ->from(route('payroll.show', $period))
        ->get(route('payroll.payslips.download-pdf', ['period_id' => $period->id]))
        ->assertRedirect(route('payroll.show', $period))
        ->assertSessionHas('error', 'No generated payslips found for this period.');
});
