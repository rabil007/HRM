<?php

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Enums\SalaryPaymentMethod;
use App\Models\Bank;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeContract;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\SalaryInput;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

test('office payroll generation creates records for all office employees with full monthly salary', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.periods.view',
        'payroll.periods.update',
    ]);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-05',
    ]);

    $firstEmployee = createOfficeEmployeeWithContract($company, 'OFF-100', 10000, 2000, 1000, 500);
    $secondEmployee = createOfficeEmployeeWithContract($company, 'OFF-200', 8000, 0, 0, 0);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period]))
        ->assertSessionHas('success')
        ->assertSessionHas('payroll_generation');

    $period->refresh();
    expect($period->status)->toBe(PayrollPeriodStatus::Processing);

    $record = PayrollRecord::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $firstEmployee->id)
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->payroll_category)->toBe(PayrollCategory::Office)
        ->and($record->basic_salary)->toBe('10000.00')
        ->and($record->housing_allowance)->toBe('2000.00')
        ->and($record->transport_allowance)->toBe('1000.00')
        ->and($record->other_allowances)->toBe('500.00')
        ->and($record->overtime_pay)->toBe('0.00')
        ->and($record->gross_salary)->toBe('13500.00')
        ->and($record->net_salary)->toBe('13500.00')
        ->and($record->total_deductions)->toBe('0.00')
        ->and($record->working_days)->toBe(5)
        ->and($record->present_days)->toBe(5)
        ->and((float) $record->leave_days)->toBe(0.0);

    expect(PayrollRecord::query()->where('period_id', $period->id)->count())->toBe(2);

    $summary = session('payroll_generation');
    expect($summary['generated_count'])->toBe(2)
        ->and($summary['skipped_count'])->toBe(0)
        ->and($summary['skipped_employees'])->toBe([]);
});

test('office payroll generation stamps payment date with today and refreshes on regeneration', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-05',
        'payment_date' => null,
    ]);

    createOfficeEmployeeWithContract($company, 'OFF-PAY', 10000, 2000, 1000, 500);

    Carbon::setTestNow('2026-07-03 09:00:00');

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period]));

    $period->refresh();
    expect($period->payment_date?->toDateString())->toBe('2026-07-03');

    Carbon::setTestNow('2026-07-08 09:00:00');

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period]));

    $period->refresh();
    expect($period->payment_date?->toDateString())->toBe('2026-07-08');

    Carbon::setTestNow();
});

test('office payroll generation snapshots contract and bank account linkage', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-05',
    ]);

    $employee = createOfficeEmployeeWithContract($company, 'OFF-SNAP', 10000, 0, 0, 0);
    $contract = $employee->fresh()->currentContract;
    expect($contract)->not->toBeNull();

    $bank = Bank::query()->create([
        'name' => 'Office Snapshot Bank',
        'uae_routing_code_agent_id' => '112233',
        'is_active' => true,
    ]);

    $bankAccount = EmployeeBankAccount::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'bank_id' => $bank->id,
        'iban' => 'AE070331234567890123488',
        'account_name' => 'Office Primary',
        'is_primary' => true,
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

test('office payroll generation from draft only includes selected employees and moves period to processing', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-05',
        'status' => PayrollPeriodStatus::Draft,
    ]);

    $includedEmployee = createOfficeEmployeeWithContract($company, 'OFF-IN', 10000, 0, 0, 0);
    $excludedEmployee = createOfficeEmployeeWithContract($company, 'OFF-OUT', 8000, 0, 0, 0);

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

test('office payroll generation from draft stays draft when all employees are excluded', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-05',
        'status' => PayrollPeriodStatus::Draft,
    ]);

    $firstEmployee = createOfficeEmployeeWithContract($company, 'OFF-A', 10000, 0, 0, 0);
    $secondEmployee = createOfficeEmployeeWithContract($company, 'OFF-B', 8000, 0, 0, 0);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period), [
            'excluded_employee_ids' => [$firstEmployee->id, $secondEmployee->id],
        ])
        ->assertRedirect();

    $period->refresh();

    expect($period->status)->toBe(PayrollPeriodStatus::Draft)
        ->and(PayrollRecord::query()->where('period_id', $period->id)->count())->toBe(0);
});

test('office payroll generation stores leave days from approved requests in period', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-05',
    ]);

    $employee = createOfficeEmployeeWithContract($company, 'OFF-150', 10000, 0, 0, 0);
    $leaveType = LeaveType::factory()->for($company)->create([
        'code' => 'AL',
        'status' => 'active',
    ]);

    LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-02',
        'end_date' => '2026-06-03',
        'total_days' => 2,
        'status' => 'approved',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertRedirect();

    $record = PayrollRecord::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $employee->id)
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->basic_salary)->toBe('10000.00')
        ->and($record->gross_salary)->toBe('10000.00')
        ->and((float) $record->leave_days)->toBe(2.0)
        ->and($record->present_days)->toBe(3)
        ->and($record->calculation_breakdown['leave_usage'][0]['days'])->toBe(2);
});

test('office payroll generation upserts existing payroll records on re-generate', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'status' => PayrollPeriodStatus::Processing,
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-05',
    ]);

    $employee = createOfficeEmployeeWithContract($company, 'OFF-300', 8000, 0, 0, 0);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertRedirect();

    SalaryInput::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'salary_input_type_id' => salaryInputTypeId($company, 'bonus'),
        'amount' => 500,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertRedirect();

    expect(PayrollRecord::query()->where('period_id', $period->id)->count())->toBe(1)
        ->and(SalaryInput::query()->where('period_id', $period->id)->count())->toBe(1);

    $record = PayrollRecord::query()->where('period_id', $period->id)->first();

    expect($record?->bonus)->toBe('500.00')
        ->and($record?->gross_salary)->toBe('8500.00')
        ->and($record?->net_salary)->toBe('8500.00');
});

test('office payroll generation is blocked on approved periods', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $period = PayrollPeriod::factory()->for($company)->office()->approved()->create([
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-05',
    ]);

    createOfficeEmployeeWithContract($company, 'OFF-500', 10000, 0, 0, 0);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertSessionHasErrors('period_id');
});

test('office payroll generation reports detailed errors for employees missing contract salary', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-05',
    ]);

    createOfficeEmployeeWithContract($company, 'OFF-OK', 10000, 0, 0, 0);

    $missingBasicEmployee = Employee::factory()->forCompany($company)->create([
        'name' => 'Abdellah Bellymani',
        'employee_no' => 'DEMO-OFFSHORE-CV',
        'status' => 'active',
    ]);

    EmployeeContract::factory()->create([
        'employee_id' => $missingBasicEmployee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Office,
        'status' => 'active',
        'basic_salary' => 0,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertRedirect()
        ->assertSessionHas('payroll_generation');

    $summary = session('payroll_generation');

    expect($summary['generated_count'])->toBe(1)
        ->and($summary['errors'])->toHaveCount(1)
        ->and($summary['errors'][0]['employee_id'])->toBe($missingBasicEmployee->id)
        ->and($summary['errors'][0]['employee_name'])->toBe('Abdellah Bellymani')
        ->and($summary['errors'][0]['employee_no'])->toBe('DEMO-OFFSHORE-CV')
        ->and($summary['errors'][0]['field'])->toBe('basic_salary')
        ->and($summary['errors'][0]['field_label'])->toBe('Basic monthly salary')
        ->and($summary['errors'][0]['message'])->toBe('Active basic monthly salary is required on the office contract.')
        ->and($summary['errors'][0]['employee_url'])->toBe(
            route('organization.employees.show', $missingBasicEmployee),
        );
});

test('processing pay period show returns payslip summary', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.periods.view',
    ]);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);

    $employee = createOfficeEmployeeWithContract($company, 'OFF-PS-PROC', 10000, 0, 0, 0);

    PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Office,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', ['payrollPeriod' => $period]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->where('period.status', 'processing')
            ->where('payslip_summary.total', 1));
});

test('approved pay period show includes payslip summary', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.periods.view',
    ]);

    $period = PayrollPeriod::factory()->for($company)->office()->approved()->create();
    $employee = createOfficeEmployeeWithContract($company, 'OFF-PS-APP', 10000, 0, 0, 0);

    PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Office,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', ['payrollPeriod' => $period]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->where('period.status', 'approved')
            ->where('payslip_summary.total', 1)
            ->where('payslip_summary.generated', 0)
            ->where('payslip_summary.pending', 1));
});

test('payroll show includes office payroll records and leave usage on employees tab', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.view']);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'status' => PayrollPeriodStatus::Processing,
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-05',
    ]);

    $employee = createOfficeEmployeeWithContract($company, 'OFF-600', 10000, 0, 0, 0);
    $leaveType = LeaveType::factory()->for($company)->create([
        'code' => 'AL',
        'name' => 'Annual Leave',
        'status' => 'active',
    ]);

    $bank = Bank::query()->create([
        'name' => 'Payroll Bank',
        'uae_routing_code_agent_id' => '123456',
        'is_active' => true,
    ]);

    EmployeeBankAccount::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'bank_id' => $bank->id,
        'iban' => 'AE070331234567890123456',
        'account_name' => 'Primary Account',
        'is_primary' => true,
    ]);

    LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-02',
        'end_date' => '2026-06-02',
        'total_days' => 1,
        'status' => 'approved',
    ]);

    PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Office,
        'basic_salary' => 10000,
        'gross_salary' => 10000,
        'net_salary' => 10000,
        'calculation_breakdown' => [
            'present_days' => 4,
            'working_days' => 5,
            'lines' => ['basic' => 10000],
        ],
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', ['payrollPeriod' => $period]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->has('payroll_records', 1)
            ->where('payroll_records.0.net_salary', '10000.00')
            ->where('payroll_records.0.payroll_category', 'office')
            ->where('payroll_records.0.primary_account.bank_name', 'Payroll Bank')
            ->where('payroll_records.0.primary_account.iban', 'AE070331234567890123456')
            ->where('period.can_generate_payroll', true));

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', ['payrollPeriod' => $period]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->has('leave_types', 1)
            ->has('rows', 1)
            ->where('rows.0.total_leave_days', 1)
            ->where('rows.0.leave_usage.0.days', 1)
            ->where('rows.0.primary_account.bank_name', 'Payroll Bank')
            ->where('rows.0.primary_account.iban', 'AE070331234567890123456'));
});

test('office payroll employees tab exposes salary payment method for cash employees', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.periods.view',
        'payroll.periods.update',
    ]);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
    ]);

    $cashEmployee = createOfficeEmployeeWithContract($company, 'CASH-001', 5000, 0, 0, 0);
    $cashEmployee->update(['salary_payment_method' => SalaryPaymentMethod::CashC3]);

    $this->get(route('payroll.show', ['payrollPeriod' => $period]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->has('rows', 1)
            ->where('rows.0.salary_payment_method', SalaryPaymentMethod::CashC3->value)
            ->where('rows.0.salary_payment_method_label', 'C3'));
});

test('office payroll generation snapshots employee salary payment method on payroll record', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.periods.view',
        'payroll.periods.update',
    ]);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
    ]);

    $cashEmployee = createOfficeEmployeeWithContract($company, 'CASH-SNAPSHOT', 5000, 0, 0, 0);
    $cashEmployee->update(['salary_payment_method' => SalaryPaymentMethod::CashC3]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertRedirect();

    $record = PayrollRecord::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $cashEmployee->id)
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->salary_payment_method)->toBe(SalaryPaymentMethod::CashC3);

    $cashEmployee->update(['salary_payment_method' => SalaryPaymentMethod::BankTransfer]);

    expect($record->fresh()->salary_payment_method)->toBe(SalaryPaymentMethod::CashC3);
});
