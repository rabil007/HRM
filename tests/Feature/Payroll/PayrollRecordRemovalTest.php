<?php

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\SalaryInput;

test('office payroll record can be removed from processing pay run', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.periods.view',
        'payroll.periods.update',
    ]);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
        'status' => PayrollPeriodStatus::Processing,
    ]);

    $firstEmployee = createOfficeEmployeeWithContract($company, 'OFF-100', 10000, 2000, 1000, 500);
    $secondEmployee = createOfficeEmployeeWithContract($company, 'OFF-200', 8000, 0, 0, 0);

    PayrollRecord::factory()->for($company)->for($period, 'period')->for($firstEmployee)->create([
        'payroll_category' => PayrollCategory::Office,
    ]);
    PayrollRecord::factory()->for($company)->for($period, 'period')->for($secondEmployee)->create([
        'payroll_category' => PayrollCategory::Office,
    ]);

    $recordToRemove = PayrollRecord::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $firstEmployee->id)
        ->firstOrFail();

    SalaryInput::factory()->for($company)->create([
        'employee_id' => $firstEmployee->id,
        'period_id' => $period->id,
        'salary_input_type_id' => salaryInputTypeId($company, 'bonus'),
        'amount' => 500,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->delete(route('payroll.records.destroy', [
            'payrollPeriod' => $period,
            'payrollRecord' => $recordToRemove,
        ]))
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period, 'tab' => 'payroll']))
        ->assertSessionHas('success');

    expect(PayrollRecord::query()->whereKey($recordToRemove->id)->exists())->toBeFalse()
        ->and(PayrollRecord::query()->where('period_id', $period->id)->count())->toBe(1)
        ->and(SalaryInput::query()->where('period_id', $period->id)->where('employee_id', $firstEmployee->id)->exists())->toBeFalse();

    $period->refresh();
    expect($period->excluded_employee_ids)->toBe([$firstEmployee->id]);
});

test('removed office employee is not re-added when payroll is updated', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-05',
        'status' => PayrollPeriodStatus::Processing,
        'excluded_employee_ids' => [],
    ]);

    $firstEmployee = createOfficeEmployeeWithContract($company, 'OFF-100', 10000, 0, 0, 0);
    $secondEmployee = createOfficeEmployeeWithContract($company, 'OFF-200', 8000, 0, 0, 0);

    PayrollRecord::factory()->for($company)->for($period, 'period')->for($firstEmployee)->create([
        'payroll_category' => PayrollCategory::Office,
    ]);
    $remainingRecord = PayrollRecord::factory()->for($company)->for($period, 'period')->for($secondEmployee)->create([
        'payroll_category' => PayrollCategory::Office,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->delete(route('payroll.records.destroy', [
            'payrollPeriod' => $period,
            'payrollRecord' => PayrollRecord::query()
                ->where('period_id', $period->id)
                ->where('employee_id', $firstEmployee->id)
                ->firstOrFail(),
        ]))
        ->assertRedirect();

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.generate', $period))
        ->assertRedirect();

    expect(PayrollRecord::query()->where('period_id', $period->id)->where('employee_id', $firstEmployee->id)->exists())->toBeFalse()
        ->and(PayrollRecord::query()->where('period_id', $period->id)->where('employee_id', $secondEmployee->id)->exists())->toBeTrue()
        ->and(PayrollRecord::query()->where('period_id', $period->id)->count())->toBe(1);

    $period->refresh();
    expect($period->excluded_employee_ids)->toBe([$firstEmployee->id]);
});

test('removing the last payroll record reverts pay run to draft', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
        'status' => PayrollPeriodStatus::Processing,
    ]);

    $employee = createOfficeEmployeeWithContract($company, 'OFF-100', 10000, 0, 0, 0);
    $record = PayrollRecord::factory()->for($company)->for($period, 'period')->for($employee)->create([
        'payroll_category' => PayrollCategory::Office,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->delete(route('payroll.records.destroy', [
            'payrollPeriod' => $period,
            'payrollRecord' => $record,
        ]))
        ->assertRedirect();

    $period->refresh();
    expect($period->status)->toBe(PayrollPeriodStatus::Draft)
        ->and(PayrollRecord::query()->where('period_id', $period->id)->count())->toBe(0);
});

test('payroll record cannot be removed from approved pay run', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.update']);

    $period = PayrollPeriod::factory()->for($company)->office()->approved()->create();
    $employee = createOfficeEmployeeWithContract($company, 'OFF-100', 10000, 0, 0, 0);
    $record = PayrollRecord::factory()->for($company)->for($period, 'period')->for($employee)->create([
        'payroll_category' => PayrollCategory::Office,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->delete(route('payroll.records.destroy', [
            'payrollPeriod' => $period,
            'payrollRecord' => $record,
        ]))
        ->assertSessionHasErrors('period_id');

    expect(PayrollRecord::query()->whereKey($record->id)->exists())->toBeTrue();
});
