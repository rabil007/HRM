<?php

use App\Enums\SalaryAdjustmentStatus;
use App\Enums\SalaryAdjustmentType;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\SalaryAdjustment;
use Inertia\Testing\AssertableInertia as Assert;

test('users without permission cannot view salary adjustments index', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.adjustments.index'))
        ->assertForbidden();
});

test('authorized users can create and list salary adjustments', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.adjustments.view',
        'payroll.adjustments.create',
    ]);

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $period = PayrollPeriod::factory()->for($company)->office()->create();

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.adjustments.store'), [
            'employee_id' => $employee->id,
            'period_id' => $period->id,
            'type' => SalaryAdjustmentType::Bonus->value,
            'amount' => 500,
            'reason' => 'Performance bonus',
        ])
        ->assertRedirect(route('payroll.adjustments.index'))
        ->assertSessionHas('success');

    $this->assertDatabaseHas('salary_adjustments', [
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'type' => SalaryAdjustmentType::Bonus->value,
        'status' => SalaryAdjustmentStatus::Pending->value,
        'amount' => 500,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.adjustments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/adjustments')
            ->has('adjustments', 1)
            ->where('adjustments.0.type', 'bonus')
            ->where('adjustments.0.amount', '500.00'));
});

test('authorized users can approve and reject pending salary adjustments', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.adjustments.approve']);

    $employee = Employee::factory()->forCompany($company)->create();

    $pending = SalaryAdjustment::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'type' => SalaryAdjustmentType::Deduction,
        'amount' => 100,
        'reason' => 'Late penalty',
        'status' => SalaryAdjustmentStatus::Pending,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->put(route('payroll.adjustments.approve', $pending))
        ->assertRedirect(route('payroll.adjustments.index'))
        ->assertSessionHas('success');

    expect($pending->fresh()->status)->toBe(SalaryAdjustmentStatus::Approved)
        ->and($pending->fresh()->approved_by)->toBe($user->id);

    $rejectTarget = SalaryAdjustment::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'status' => SalaryAdjustmentStatus::Pending,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->put(route('payroll.adjustments.reject', $rejectTarget), [
            'rejection_reason' => 'Not eligible',
        ])
        ->assertRedirect(route('payroll.adjustments.index'));

    expect($rejectTarget->fresh()->status)->toBe(SalaryAdjustmentStatus::Rejected)
        ->and($rejectTarget->fresh()->rejection_reason)->toBe('Not eligible');
});

test('only pending salary adjustments can be updated or deleted', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.adjustments.view',
        'payroll.adjustments.update',
        'payroll.adjustments.delete',
    ]);

    $employee = Employee::factory()->forCompany($company)->create();

    $approved = SalaryAdjustment::factory()->for($company)->approved()->create([
        'employee_id' => $employee->id,
        'approved_by' => $user->id,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->put(route('payroll.adjustments.update', $approved), [
            'employee_id' => $employee->id,
            'period_id' => null,
            'type' => SalaryAdjustmentType::Bonus->value,
            'amount' => 200,
            'reason' => 'Updated',
        ])
        ->assertRedirect(route('payroll.adjustments.index'))
        ->assertSessionHasErrors('salary_adjustment');

    $pending = SalaryAdjustment::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'type' => SalaryAdjustmentType::Bonus,
        'amount' => 150,
        'reason' => 'Original',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->put(route('payroll.adjustments.update', $pending), [
            'employee_id' => $employee->id,
            'period_id' => null,
            'type' => SalaryAdjustmentType::Commission->value,
            'amount' => 250,
            'reason' => 'Updated commission',
        ])
        ->assertRedirect(route('payroll.adjustments.index'))
        ->assertSessionHas('success');

    expect($pending->fresh()->type)->toBe(SalaryAdjustmentType::Commission)
        ->and((float) $pending->fresh()->amount)->toBe(250.0);

    $this->withSession(['current_company_id' => $company->id])
        ->delete(route('payroll.adjustments.destroy', $pending))
        ->assertRedirect(route('payroll.adjustments.index'));

    expect(SalaryAdjustment::query()->whereKey($pending->id)->exists())->toBeFalse();
});

test('salary adjustments are scoped to current company', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    ['company' => $otherCompany] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.adjustments.view']);

    $employee = Employee::factory()->forCompany($otherCompany)->create();

    SalaryAdjustment::factory()->for($otherCompany)->create([
        'employee_id' => $employee->id,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.adjustments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('adjustments', 0));
});
