<?php

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\AttendanceRecord;
use App\Models\Bank;
use App\Models\CrewTimesheet;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Support\Payroll\Actions\SyncContractSalaryComponentsFromContract;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

test('crew payroll generation creates records for all active crew employees including those without timesheets', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.periods.view',
        'payroll.periods.update',
        'payroll.crew_timesheets.view',
    ]);

    $period = PayrollPeriod::factory()->for($company)->manualTimesheets()->create([
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
    ]);

    $crewWithTimesheet = createCrewEmployeeWithContract($company, 'CREW-100', 150, 50, 75);
    $crewWithoutTimesheet = createCrewEmployeeWithContract($company, 'CREW-200', 150, 50, 75);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $crewWithTimesheet->id,
        'period_id' => $period->id,
        'sign_on_standby_days' => 5,
        'onsite_days' => 10,
        'overtime_hours' => 0,
        'additional_amount' => 100,
        'deduction_amount' => 50,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period]))
        ->assertSessionHas('success')
        ->assertSessionHas('payroll_generation');

    $period->refresh();
    expect($period->status)->toBe(PayrollPeriodStatus::Processing);

    $record = PayrollRecord::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $crewWithTimesheet->id)
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->payroll_category)->toBe(PayrollCategory::Crew)
        ->and($record->gross_salary)->toBe('3975.00')
        ->and($record->net_salary)->toBe('3925.00')
        ->and($record->calculation_breakdown['lines']['total_standby_pay'])->toEqual(1125);

    expect(PayrollRecord::query()->where('period_id', $period->id)->count())->toBe(2);

    $zeroWorkRecord = PayrollRecord::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $crewWithoutTimesheet->id)
        ->first();

    expect($zeroWorkRecord)->not->toBeNull()
        ->and($zeroWorkRecord->gross_salary)->toBe('0.00')
        ->and($zeroWorkRecord->net_salary)->toBe('0.00')
        ->and($zeroWorkRecord->present_days)->toBe(0)
        ->and((float) $zeroWorkRecord->leave_days)->toBe(30.0);

    $summary = session('payroll_generation');
    expect($summary['generated_count'])->toBe(2)
        ->and($summary['skipped_count'])->toBe(0);
});

test('crew payroll generation stamps generated_at and leaves payment date null, refreshing on regeneration', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $period = PayrollPeriod::factory()->for($company)->manualTimesheets()->create([
        'payroll_category' => PayrollCategory::Crew,
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
        'payment_date' => null,
        'generated_at' => null,
    ]);

    createCrewEmployeeWithContract($company, 'CREW-PAY', 150, 50, 75);

    Carbon::setTestNow('2026-07-03 09:00:00');

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period]));

    $period->refresh();
    expect($period->generated_at?->toDateTimeString())->toBe('2026-07-03 09:00:00')
        ->and($period->payment_date)->toBeNull();

    Carbon::setTestNow('2026-07-08 12:30:00');

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period]));

    $period->refresh();
    expect($period->generated_at?->toDateTimeString())->toBe('2026-07-08 12:30:00')
        ->and($period->payment_date)->toBeNull();

    Carbon::setTestNow();
});

test('crew payroll generation calculates overtime pay from hours and period daily rates', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $period = PayrollPeriod::factory()->for($company)->manualTimesheets()->create([
        'payroll_category' => PayrollCategory::Crew,
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
    ]);

    $employee = createCrewEmployeeWithContract($company, 'CREW-OT', 33.5, 250, 66.5);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'sign_on_standby_days' => 0,
        'onsite_days' => 0,
        'overtime_hours' => 98,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertRedirect();

    $record = PayrollRecord::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $employee->id)
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->overtime_pay)->toBe('3523.97')
        ->and($record->overtime_hours)->toBe('98.00')
        ->and($record->calculation_breakdown['overtime']['monthly_salary'])->toEqual(10500)
        ->and($record->calculation_breakdown['overtime']['period_days'])->toBe(30)
        ->and($record->calculation_breakdown['overtime']['daily_onsite_rate'])->toEqual(350);
});

test('crew payroll generation uses a fixed 30 day overtime base even in 31 day months', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $period = PayrollPeriod::factory()->for($company)->manualTimesheets()->create([
        'payroll_category' => PayrollCategory::Crew,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);

    $employee = createCrewEmployeeWithContract($company, 'CREW-3095', 33.5, 134, 66.5);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'sign_on_standby_days' => 0,
        'onsite_days' => 0,
        'overtime_hours' => 78,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertRedirect();

    $record = PayrollRecord::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $employee->id)
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->overtime_pay)->toBe('1875.21')
        ->and($record->calculation_breakdown['overtime']['monthly_salary'])->toEqual(7020)
        ->and($record->calculation_breakdown['overtime']['period_days'])->toBe(30)
        ->and($record->calculation_breakdown['overtime']['overtime_hourly_rate'])->toEqual(24.04);
});

test('crew payroll generation upserts existing payroll records on re-generate', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $period = PayrollPeriod::factory()->for($company)->manualTimesheets()->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);

    $employee = createCrewEmployeeWithContract($company, 'CREW-300', 100, 0, 0);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'sign_on_standby_days' => 2,
        'onsite_days' => 0,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertRedirect();

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertRedirect();

    expect(PayrollRecord::query()->where('period_id', $period->id)->count())->toBe(1);
});

test('crew payroll generation does not run on office periods via crew action', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-05',
    ]);

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $contract = EmployeeContract::factory()->create([
        'employee_id' => $employee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Office,
        'status' => 'active',
        'basic_salary' => 10000,
    ]);

    (new SyncContractSalaryComponentsFromContract)->handle($contract);

    AttendanceRecord::factory()->forEmployee($employee)->create([
        'date' => '2026-06-01',
        'status' => AttendanceRecord::STATUS_PRESENT,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period]))
        ->assertSessionHas('payroll_generation');

    expect(PayrollRecord::query()->where('period_id', $period->id)->count())->toBe(1);
});

test('crew payroll generation is blocked on approved periods', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $period = PayrollPeriod::factory()->for($company)->manualTimesheets()->approved()->create();
    $employee = createCrewEmployeeWithContract($company, 'CREW-400', 100, 0, 0);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'sign_on_standby_days' => 1,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertSessionHasErrors('period_id');
});

test('timesheets cannot be edited after crew payroll generation moves period to processing', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.periods.update',
        'payroll.crew_timesheets.update',
    ]);

    $period = PayrollPeriod::factory()->for($company)->manualTimesheets()->create();
    $employee = createCrewEmployeeWithContract($company, 'CREW-500', 100, 0, 0);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'sign_on_standby_days' => 1,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertRedirect();

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.store', $period), [
            'period_id' => $period->id,
            'employee_id' => $employee->id,
            'sign_on_standby_days' => 3,
        ])
        ->assertSessionHasErrors('period_id');
});

test('payroll show includes payroll records on payroll tab', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.crew_timesheets.view']);

    $period = PayrollPeriod::factory()->for($company)->manualTimesheets()->create(['status' => PayrollPeriodStatus::Processing]);
    $employee = createCrewEmployeeWithContract($company, 'CREW-600', 100, 0, 0);

    PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'gross_salary' => 500,
        'net_salary' => 500,
        'calculation_breakdown' => [
            'total_standby_days' => 5,
            'onsite_days' => 0,
            'rates' => [
                'basic_daily' => 100,
                'site_allowance_daily' => 0,
                'supplementary_allowance_daily' => 0,
            ],
            'lines' => ['total_standby_pay' => 500],
        ],
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', ['payrollPeriod' => $period]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->has('payroll_records', 1)
            ->where('payroll_records.0.net_salary', '500.00')
            ->where('payroll_records.0.rates.basic_daily', '100.00')
            ->where('permissions.generate_payroll', false));
});

test('crew payroll generation snapshots contract and bank account linkage', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $period = PayrollPeriod::factory()->for($company)->manualTimesheets()->create([
        'status' => PayrollPeriodStatus::Draft,
    ]);

    $employee = createCrewEmployeeWithContract($company, 'CREW-SNAP', 150, 50, 75);
    $contract = $employee->fresh()->currentContract;
    expect($contract)->not->toBeNull();

    $bank = Bank::query()->create([
        'name' => 'Crew Snapshot Bank',
        'uae_routing_code_agent_id' => '654321',
        'is_active' => true,
    ]);

    $bankAccount = EmployeeBankAccount::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'bank_id' => $bank->id,
        'iban' => 'AE070331234567890123499',
        'account_name' => 'Crew Primary',
        'is_primary' => true,
    ]);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'sign_on_standby_days' => 1,
        'onsite_days' => 1,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertRedirect();

    $record = PayrollRecord::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $employee->id)
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->contract_id)->toBe($contract->id)
        ->and($record->bank_id)->toBe($bank->id)
        ->and($record->employee_bank_account_id)->toBe($bankAccount->id);
});

test('crew payroll generation from draft only includes selected employees and moves period to processing', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $period = PayrollPeriod::factory()->for($company)->manualTimesheets()->create([
        'status' => PayrollPeriodStatus::Draft,
    ]);

    $includedEmployee = createCrewEmployeeWithContract($company, 'CREW-IN', 150, 50, 75);
    $excludedEmployee = createCrewEmployeeWithContract($company, 'CREW-OUT', 150, 50, 75);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $includedEmployee->id,
        'period_id' => $period->id,
        'sign_on_standby_days' => 5,
        'onsite_days' => 10,
    ]);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $excludedEmployee->id,
        'period_id' => $period->id,
        'sign_on_standby_days' => 5,
        'onsite_days' => 10,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period), [
            'excluded_employee_ids' => [$excludedEmployee->id],
        ])
        ->assertRedirect();

    $period->refresh();

    expect($period->status)->toBe(PayrollPeriodStatus::Processing)
        ->and($period->excluded_employee_ids)->toBe([$excludedEmployee->id])
        ->and(PayrollRecord::query()->where('period_id', $period->id)->count())->toBe(1)
        ->and(PayrollRecord::query()
            ->where('period_id', $period->id)
            ->where('employee_id', $includedEmployee->id)
            ->exists())->toBeTrue()
        ->and(PayrollRecord::query()
            ->where('period_id', $period->id)
            ->where('employee_id', $excludedEmployee->id)
            ->exists())->toBeFalse();
});

test('crew payroll generation from draft stays draft when all employees are excluded', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $period = PayrollPeriod::factory()->for($company)->manualTimesheets()->create([
        'status' => PayrollPeriodStatus::Draft,
    ]);

    $firstEmployee = createCrewEmployeeWithContract($company, 'CREW-A', 150, 50, 75);
    $secondEmployee = createCrewEmployeeWithContract($company, 'CREW-B', 150, 50, 75);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $firstEmployee->id,
        'period_id' => $period->id,
        'sign_on_standby_days' => 2,
        'onsite_days' => 8,
    ]);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $secondEmployee->id,
        'period_id' => $period->id,
        'sign_on_standby_days' => 2,
        'onsite_days' => 8,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period), [
            'excluded_employee_ids' => [$firstEmployee->id, $secondEmployee->id],
        ])
        ->assertRedirect();

    $period->refresh();

    expect($period->status)->toBe(PayrollPeriodStatus::Draft)
        ->and($period->excluded_employee_ids)->toBe([$firstEmployee->id, $secondEmployee->id])
        ->and(PayrollRecord::query()->where('period_id', $period->id)->count())->toBe(0);
});

test('crew payroll generation calculates monthly crew contracts from working and leave days', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $period = PayrollPeriod::factory()->for($company)->manualTimesheets()->create([
        'payroll_category' => PayrollCategory::Crew,
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
    ]);

    $employee = createCrewMonthlyEmployeeWithContract($company, 'CREW-MONTHLY', 5000, 2000, 1000, 500);

    CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'unpaid_leave_days' => 5,
        'additional_amount' => 100,
        'deduction_amount' => 50,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertRedirect();

    $record = PayrollRecord::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $employee->id)
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->payroll_category)->toBe(PayrollCategory::Crew)
        ->and($record->calculation_breakdown['salary_structure'])->toBe('monthly')
        ->and($record->housing_allowance)->toBe('1666.67')
        ->and($record->gross_salary)->toBe('7183.34')
        ->and($record->net_salary)->toBe('5716.67');
});
