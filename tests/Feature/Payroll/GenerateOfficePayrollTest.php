<?php

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\Bank;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeContract;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Support\Payroll\Actions\SyncContractSalaryComponentsFromContract;
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
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period, 'tab' => 'payroll']))
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

    createOfficeEmployeeWithContract($company, 'OFF-300', 8000, 0, 0, 0);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertRedirect();

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertRedirect();

    expect(PayrollRecord::query()->where('period_id', $period->id)->count())->toBe(1);
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
        ->get(route('payroll.show', ['payrollPeriod' => $period, 'tab' => 'payroll']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->where('tab', 'payroll')
            ->has('payroll_records', 1)
            ->where('payroll_records.0.net_salary', '10000.00')
            ->where('payroll_records.0.payroll_category', 'office')
            ->where('payroll_records.0.primary_account.bank_name', 'Payroll Bank')
            ->where('payroll_records.0.primary_account.iban', 'AE070331234567890123456')
            ->where('period.can_generate_payroll', true));

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', ['payrollPeriod' => $period, 'tab' => 'employees']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->where('tab', 'employees')
            ->has('leave_types', 1)
            ->has('rows', 1)
            ->where('rows.0.total_leave_days', 1)
            ->where('rows.0.leave_usage.0.days', 1)
            ->where('rows.0.primary_account.bank_name', 'Payroll Bank')
            ->where('rows.0.primary_account.iban', 'AE070331234567890123456'));
});

function createOfficeEmployeeWithContract(
    $company,
    string $employeeNo,
    float $basic,
    float $housing,
    float $transport,
    float $other,
): Employee {
    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => $employeeNo,
        'status' => 'active',
    ]);

    $contract = EmployeeContract::factory()->create([
        'employee_id' => $employee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Office,
        'status' => 'active',
        'basic_salary' => $basic,
        'housing_allowance' => $housing,
        'transport_allowance' => $transport,
        'other_allowances' => $other,
    ]);

    (new SyncContractSalaryComponentsFromContract)->handle($contract);

    return $employee;
}
