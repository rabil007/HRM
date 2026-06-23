<?php

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Support\Payroll\Actions\SyncContractSalaryComponentsFromContract;
use Inertia\Testing\AssertableInertia as Assert;

test('office payroll generation creates records for employees with attendance and skips others', function () {
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

    $officeWithAttendance = createOfficeEmployeeWithContract($company, 'OFF-100', 10000, 2000, 1000, 500);
    $officeWithoutAttendance = createOfficeEmployeeWithContract($company, 'OFF-200', 10000, 0, 0, 0);

    foreach (['2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04', '2026-06-05'] as $date) {
        AttendanceRecord::factory()->forEmployee($officeWithAttendance)->create([
            'date' => $date,
            'status' => AttendanceRecord::STATUS_PRESENT,
            'overtime_hours' => $date === '2026-06-05' ? 2 : 0,
        ]);
    }

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period, 'tab' => 'payroll']))
        ->assertSessionHas('success')
        ->assertSessionHas('payroll_generation');

    $period->refresh();
    expect($period->status)->toBe(PayrollPeriodStatus::Processing);

    $record = PayrollRecord::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $officeWithAttendance->id)
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->payroll_category)->toBe(PayrollCategory::Office)
        ->and($record->basic_salary)->toBe('10000.00')
        ->and($record->housing_allowance)->toBe('2000.00')
        ->and($record->transport_allowance)->toBe('1000.00')
        ->and($record->other_allowances)->toBe('500.00')
        ->and($record->overtime_pay)->toBe('500.00')
        ->and($record->gross_salary)->toBe('14000.00')
        ->and($record->net_salary)->toBe('14000.00')
        ->and($record->working_days)->toBe(5)
        ->and($record->present_days)->toBe(5);

    expect(PayrollRecord::query()->where('period_id', $period->id)->count())->toBe(1);

    $summary = session('payroll_generation');
    expect($summary['generated_count'])->toBe(1)
        ->and($summary['skipped_count'])->toBe(1)
        ->and($summary['skipped_employees'][0]['id'])->toBe($officeWithoutAttendance->id);
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

    AttendanceRecord::factory()->forEmployee($employee)->create([
        'date' => '2026-06-01',
        'status' => AttendanceRecord::STATUS_PRESENT,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertRedirect();

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertRedirect();

    expect(PayrollRecord::query()->where('period_id', $period->id)->count())->toBe(1);
});

test('office payroll generation prorates salary when attendance is partial', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-05',
    ]);

    $employee = createOfficeEmployeeWithContract($company, 'OFF-400', 10000, 0, 0, 0);

    AttendanceRecord::factory()->forEmployee($employee)->create([
        'date' => '2026-06-01',
        'status' => AttendanceRecord::STATUS_PRESENT,
    ]);
    AttendanceRecord::factory()->forEmployee($employee)->create([
        'date' => '2026-06-02',
        'status' => AttendanceRecord::STATUS_HALF_DAY,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertRedirect();

    $record = PayrollRecord::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $employee->id)
        ->first();

    // 1.5 present days / 5 working days = 0.3 ratio -> 3000 basic
    expect($record)->not->toBeNull()
        ->and($record->basic_salary)->toBe('3000.00')
        ->and($record->present_days)->toBe(2);
});

test('office payroll generation is blocked on approved periods', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $period = PayrollPeriod::factory()->for($company)->office()->approved()->create([
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-05',
    ]);

    $employee = createOfficeEmployeeWithContract($company, 'OFF-500', 10000, 0, 0, 0);

    AttendanceRecord::factory()->forEmployee($employee)->create([
        'date' => '2026-06-01',
        'status' => AttendanceRecord::STATUS_PRESENT,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertSessionHasErrors('period_id');
});

test('payroll show includes office payroll records and attendance summary on employees tab', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.view']);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'status' => PayrollPeriodStatus::Processing,
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-05',
    ]);

    $employee = createOfficeEmployeeWithContract($company, 'OFF-600', 10000, 0, 0, 0);

    AttendanceRecord::factory()->forEmployee($employee)->create([
        'date' => '2026-06-01',
        'status' => AttendanceRecord::STATUS_PRESENT,
    ]);

    PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Office,
        'basic_salary' => 5000,
        'gross_salary' => 5000,
        'net_salary' => 5000,
        'calculation_breakdown' => [
            'present_days' => 1,
            'working_days' => 5,
            'lines' => ['basic' => 5000],
        ],
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', ['payrollPeriod' => $period, 'tab' => 'payroll']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->where('tab', 'payroll')
            ->has('payroll_records', 1)
            ->where('payroll_records.0.net_salary', '5000.00')
            ->where('payroll_records.0.payroll_category', 'office')
            ->where('period.can_generate_payroll', true));

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', ['payrollPeriod' => $period, 'tab' => 'employees']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->where('tab', 'employees')
            ->has('rows', 1)
            ->where('rows.0.attendance_summary.present_days', 1));
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
