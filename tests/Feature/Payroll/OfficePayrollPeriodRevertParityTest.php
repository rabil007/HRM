<?php

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use Inertia\Testing\AssertableInertia as Assert;

test('office processing period show exposes revert to draft like crew', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.periods.view',
        'payroll.periods.revert_to_draft',
    ]);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', $period))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->where('period.payroll_category', PayrollCategory::Office->value)
            ->where('period.supports_timesheets', false)
            ->where('period.can_revert_to_draft', true)
            ->where('period.can_revert_to_processing', false)
            ->where('period.can_revert_to_approved', false)
            ->where('permissions.revert_to_draft', true)
            ->where('permissions.revert_to_processing', false)
            ->where('permissions.revert_to_approved', false));
});

test('office approved period show exposes revert to processing like crew', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.periods.view',
        'payroll.periods.revert_to_processing',
    ]);

    $period = PayrollPeriod::factory()->for($company)->office()->approved()->create([
        'approved_by' => $user->id,
        'approved_at' => now(),
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', $period))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->where('period.payroll_category', PayrollCategory::Office->value)
            ->where('period.can_revert_to_draft', false)
            ->where('period.can_revert_to_processing', true)
            ->where('period.can_revert_to_approved', false)
            ->where('permissions.revert_to_processing', true));
});

test('office paid period show exposes revert to approved like crew', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.periods.view',
        'payroll.periods.revert_to_approved',
    ]);

    $period = PayrollPeriod::factory()->for($company)->office()->paid()->create();

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', $period))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->where('period.payroll_category', PayrollCategory::Office->value)
            ->where('period.can_revert_to_draft', false)
            ->where('period.can_revert_to_processing', false)
            ->where('period.can_revert_to_approved', true)
            ->where('permissions.revert_to_approved', true));
});

test('authorized users can revert office paid period to approved', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.revert_to_approved']);

    $period = PayrollPeriod::factory()->for($company)->office()->paid()->create([
        'payment_proof_path' => 'test-path.pdf',
    ]);
    $employee = createOfficeEmployeeWithContract($company, 'OFF-REV-PAID', 9000, 0, 0, 0);

    $record = PayrollRecord::factory()->for($company)->for($period, 'period')->for($employee)->create([
        'payroll_category' => PayrollCategory::Office,
        'status' => 'paid',
        'paid_at' => now(),
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.revert-to-approved', $period))
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period]))
        ->assertSessionHas('success');

    $period->refresh();
    $record->refresh();

    expect($period->status)->toBe(PayrollPeriodStatus::Approved)
        ->and($period->payroll_category)->toBe(PayrollCategory::Office)
        ->and($record->status)->toBe('approved')
        ->and($record->paid_at)->toBeNull();
});

test('authorized users can revert office approved period to processing', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.revert_to_processing']);

    $period = PayrollPeriod::factory()->for($company)->office()->approved()->create([
        'approved_by' => $user->id,
        'approved_at' => now(),
    ]);
    $employee = createOfficeEmployeeWithContract($company, 'OFF-REV-PROC', 9000, 0, 0, 0);

    $record = PayrollRecord::factory()->for($company)->for($period, 'period')->for($employee)->create([
        'payroll_category' => PayrollCategory::Office,
        'status' => 'approved',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.revert-to-processing', $period))
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period]))
        ->assertSessionHas('success');

    $period->refresh();
    $record->refresh();

    expect($period->status)->toBe(PayrollPeriodStatus::Processing)
        ->and($period->payroll_category)->toBe(PayrollCategory::Office)
        ->and($period->approved_by)->toBeNull()
        ->and($record->status)->toBe('draft');
});

test('authorized users can revert office processing period to draft', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.revert_to_draft']);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);
    $employee = createOfficeEmployeeWithContract($company, 'OFF-REV-DRAFT', 9000, 0, 0, 0);

    PayrollRecord::factory()->for($company)->for($period, 'period')->for($employee)->create([
        'payroll_category' => PayrollCategory::Office,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.revert-to-draft', $period))
        ->assertRedirect()
        ->assertSessionHas('success', 'Pay period reverted to draft. Payroll records were cleared.');

    $period->refresh();

    expect($period->status)->toBe(PayrollPeriodStatus::Draft)
        ->and($period->payroll_category)->toBe(PayrollCategory::Office)
        ->and(PayrollRecord::query()->where('period_id', $period->id)->count())->toBe(0);
});
