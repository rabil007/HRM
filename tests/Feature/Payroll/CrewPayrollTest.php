<?php

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Enums\SalaryPaymentMethod;
use App\Models\CompanyVisaType;
use App\Models\CrewTimesheet;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\Position;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot access crew payroll board', function () {
    $this->get(route('payroll.show', ['payrollPeriod' => 1]))
        ->assertRedirect(route('login'));
});

test('crew payroll board lists only employees with active crew contracts', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.crew_timesheets.view']);

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
        'name' => 'May 2026',
        'start_date' => '2026-05-01',
        'end_date' => '2026-05-31',
        'payment_date' => '2026-06-05',
    ]);

    $crewEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'CREW-001',
        'name' => 'Crew Member',
        'status' => 'active',
    ]);

    EmployeeContract::factory()->create([
        'employee_id' => $crewEmployee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'status' => 'active',
    ]);

    $officeEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'OFF-001',
        'name' => 'Office Member',
        'status' => 'active',
    ]);

    EmployeeContract::factory()->create([
        'employee_id' => $officeEmployee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Office,
        'status' => 'active',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', $period))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->has('rows', 1)
            ->where('rows.0.employee.id', $crewEmployee->id)
            ->where('rows.0.is_filled', false)
            ->where('period.id', $period->id));
});

test('payroll board excludes inactive employees even when they have an active contract', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.crew_timesheets.view']);

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
    ]);

    $activeEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'CREW-ACTIVE',
        'name' => 'Active Crew',
        'status' => 'active',
    ]);

    EmployeeContract::factory()->create([
        'employee_id' => $activeEmployee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'status' => 'active',
    ]);

    $inactiveEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'CREW-INACTIVE',
        'name' => 'Inactive Crew',
        'status' => 'inactive',
    ]);

    EmployeeContract::factory()->create([
        'employee_id' => $inactiveEmployee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'status' => 'active',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', $period))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->has('rows', 1)
            ->where('rows.0.employee.id', $activeEmployee->id)
            ->where('all_board_employee_ids', [$activeEmployee->id]));
});

test('payroll show includes all board employee ids matching pagination total', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.crew_timesheets.view']);

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
    ]);

    foreach (range(1, 25) as $index) {
        $employee = Employee::factory()->forCompany($company)->create([
            'employee_no' => sprintf('CREW-%03d', $index),
            'status' => 'active',
        ]);

        EmployeeContract::factory()->create([
            'employee_id' => $employee->id,
            'company_id' => $company->id,
            'payroll_category' => PayrollCategory::Crew,
            'status' => 'active',
        ]);
    }

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', [
            'payrollPeriod' => $period,
            'per_page' => 20,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 20)
            ->has('all_board_employee_ids', 25)
            ->where('pagination.total', 25)
            ->where('pagination.per_page', 20));
});

test('payroll show can filter board rows by department', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.crew_timesheets.view']);

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
    ]);

    $operationsDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Operations',
        'parent_id' => null,
    ]);

    $hrDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Human Resources',
        'parent_id' => null,
    ]);

    $operationsEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'OPS-001',
        'name' => 'Operations Crew',
        'status' => 'active',
        'department_id' => $operationsDepartment->id,
    ]);

    EmployeeContract::factory()->create([
        'employee_id' => $operationsEmployee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'status' => 'active',
    ]);

    $hrEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'HR-001',
        'name' => 'HR Crew',
        'status' => 'active',
        'department_id' => $hrDepartment->id,
    ]);

    EmployeeContract::factory()->create([
        'employee_id' => $hrEmployee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'status' => 'active',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', [
            'payrollPeriod' => $period,
            'department_id' => $operationsDepartment->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->has('rows', 1)
            ->where('rows.0.employee.id', $operationsEmployee->id)
            ->where('filters.department_id', (string) $operationsDepartment->id)
            ->where('department_tree_selected_id', $operationsDepartment->id));
});

test('payroll show can filter board rows by employee sponsor', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.crew_timesheets.view']);

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
    ]);

    $sponsorA = CompanyVisaType::query()->create([
        'name' => 'Payroll Sponsor A '.uniqid(),
        'is_active' => true,
    ]);
    $sponsorB = CompanyVisaType::query()->create([
        'name' => 'Payroll Sponsor B '.uniqid(),
        'is_active' => true,
    ]);

    $employeeA = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'SPN-A-001',
        'name' => 'Sponsor A Crew',
        'status' => 'active',
        'company_visa_type_id' => $sponsorA->id,
    ]);

    EmployeeContract::factory()->create([
        'employee_id' => $employeeA->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'status' => 'active',
    ]);

    $employeeB = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'SPN-B-001',
        'name' => 'Sponsor B Crew',
        'status' => 'active',
        'company_visa_type_id' => $sponsorB->id,
    ]);

    EmployeeContract::factory()->create([
        'employee_id' => $employeeB->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'status' => 'active',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', [
            'payrollPeriod' => $period,
            'company_visa_type_id' => $sponsorA->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->has('rows', 1)
            ->where('rows.0.employee.id', $employeeA->id)
            ->where('filters.company_visa_type_id', (string) $sponsorA->id)
            ->has('company_visa_types'));
});

test('payroll show can filter board rows by employee analytics group', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.crew_timesheets.view']);

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
    ]);

    $cashEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'CASH-001',
        'name' => 'Cash Crew',
        'status' => 'active',
        'salary_payment_method' => SalaryPaymentMethod::CashC3,
    ]);

    EmployeeContract::factory()->create([
        'employee_id' => $cashEmployee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'status' => 'active',
    ]);

    $missingBankEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'BANK-001',
        'name' => 'Missing Bank Crew',
        'status' => 'active',
        'salary_payment_method' => SalaryPaymentMethod::BankTransfer,
    ]);

    EmployeeContract::factory()->create([
        'employee_id' => $missingBankEmployee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'status' => 'active',
    ]);

    $thirdPartyEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'TP-001',
        'name' => 'Third Party Crew',
        'status' => 'active',
        'salary_payment_method' => SalaryPaymentMethod::ThirdParty,
    ]);

    EmployeeContract::factory()->create([
        'employee_id' => $thirdPartyEmployee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'status' => 'active',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', ['payrollPeriod' => $period]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->where('employee_stats.cash_payment_count', 2));

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', [
            'payrollPeriod' => $period,
            'employee_group' => 'cash_payment',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->has('rows', 2)
            ->where('filters.employee_group', 'cash_payment')
            ->where('rows.0.employee.id', $cashEmployee->id)
            ->where('rows.1.employee.id', $thirdPartyEmployee->id));

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', [
            'payrollPeriod' => $period,
            'employee_group' => 'missing_bank_account',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->has('rows', 1)
            ->where('rows.0.employee.id', $missingBankEmployee->id)
            ->where('filters.employee_group', 'missing_bank_account'));

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', ['payrollPeriod' => $period]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->has('rows', 3)
            ->where('filters.employee_group', ''));
});

test('payroll show includes employee department parent and position on board rows', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.crew_timesheets.view']);

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
    ]);

    $parentDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Marine',
        'parent_id' => null,
    ]);

    $childDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Deck',
        'parent_id' => $parentDepartment->id,
    ]);

    $position = Position::query()->create([
        'company_id' => $company->id,
        'department_id' => $childDepartment->id,
        'title' => 'Chief Officer',
        'status' => 'active',
    ]);

    $crewEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'CREW-100',
        'name' => 'Assigned Crew',
        'status' => 'active',
        'department_id' => $childDepartment->id,
        'position_id' => $position->id,
    ]);

    EmployeeContract::factory()->create([
        'employee_id' => $crewEmployee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'status' => 'active',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', $period))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->where('rows.0.employee.id', $crewEmployee->id)
            ->where('rows.0.employee.department.id', $childDepartment->id)
            ->where('rows.0.employee.department.name', 'Deck')
            ->where('rows.0.employee.department.parent.id', $parentDepartment->id)
            ->where('rows.0.employee.department.parent.name', 'Marine')
            ->where('rows.0.employee.position.id', $position->id)
            ->where('rows.0.employee.position.title', 'Chief Officer'));
});

test('authorized users can upsert crew timesheets for draft periods', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.view',
        'payroll.crew_timesheets.create',
        'payroll.crew_timesheets.update',
    ]);

    $period = PayrollPeriod::factory()->for($company)->create();

    $crewEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    EmployeeContract::factory()->create([
        'employee_id' => $crewEmployee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'status' => 'active',
    ]);

    $payload = [
        'period_id' => $period->id,
        'employee_id' => $crewEmployee->id,
        'sign_on_standby_from' => '2026-06-01',
        'sign_on_standby_to' => '2026-06-10',
        'onsite_from' => '2026-06-15',
        'onsite_to' => '2026-06-29',
        'overtime_hours' => 250.50,
        'additional_amount' => 100,
        'deduction_amount' => 50,
        'remarks' => 'May payroll',
    ];

    $showUrl = route('payroll.show', [
        'payrollPeriod' => $period,
        'search' => 'CREW-001',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->from($showUrl)
        ->post(route('payroll.timesheets.store', $period), $payload)
        ->assertRedirect($showUrl);

    $this->assertDatabaseHas('crew_timesheets', [
        'company_id' => $company->id,
        'employee_id' => $crewEmployee->id,
        'period_id' => $period->id,
        'sign_on_standby_days' => 10,
        'onsite_days' => 15,
        'overtime_hours' => 250.50,
        'remarks' => 'May payroll',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.store', $period), [
            ...$payload,
            'sign_on_standby_to' => '2026-06-12',
            'remarks' => 'Updated',
        ])
        ->assertRedirect();

    expect(CrewTimesheet::query()->where('period_id', $period->id)->count())->toBe(1);

    $this->assertDatabaseHas('crew_timesheets', [
        'employee_id' => $crewEmployee->id,
        'period_id' => $period->id,
        'sign_on_standby_days' => 12,
        'remarks' => 'Updated',
    ]);
});

test('crew timesheets cannot be saved for non-draft periods', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.view',
        'payroll.crew_timesheets.update',
    ]);

    $period = PayrollPeriod::factory()->for($company)->approved()->create();

    $crewEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    EmployeeContract::factory()->create([
        'employee_id' => $crewEmployee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'status' => 'active',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.store', $period), [
            'period_id' => $period->id,
            'employee_id' => $crewEmployee->id,
            'sign_on_standby_days' => 5,
        ])
        ->assertSessionHasErrors('period_id');
});

test('crew timesheets cannot be saved for office employees', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.view',
        'payroll.crew_timesheets.update',
    ]);

    $period = PayrollPeriod::factory()->for($company)->create();

    $officeEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    EmployeeContract::factory()->create([
        'employee_id' => $officeEmployee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Office,
        'status' => 'active',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.store', $period), [
            'period_id' => $period->id,
            'employee_id' => $officeEmployee->id,
            'sign_on_standby_days' => 5,
        ])
        ->assertSessionHasErrors('employee_id');
});

test('crew timesheet validation rejects invalid date ranges', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.view',
        'payroll.crew_timesheets.update',
    ]);

    $period = PayrollPeriod::factory()->for($company)->create();

    $crewEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    EmployeeContract::factory()->create([
        'employee_id' => $crewEmployee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'status' => 'active',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.store', $period), [
            'period_id' => $period->id,
            'employee_id' => $crewEmployee->id,
            'sign_on_standby_from' => '2026-06-10',
            'sign_on_standby_to' => '2026-06-01',
        ])
        ->assertSessionHasErrors('sign_on_standby_to');
});

test('office pay period lists only office employees', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.view']);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);

    $crewEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    EmployeeContract::factory()->create([
        'employee_id' => $crewEmployee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'status' => 'active',
    ]);

    $officeEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    EmployeeContract::factory()->create([
        'employee_id' => $officeEmployee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Office,
        'status' => 'active',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', $period))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->has('rows', 1)
            ->where('rows.0.employee.id', $officeEmployee->id)
            ->where('period.payroll_category', 'office')
            ->where('period.supports_timesheets', false));
});

test('crew timesheets cannot be saved on office pay periods', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.view',
        'payroll.crew_timesheets.update',
    ]);

    $period = PayrollPeriod::factory()->for($company)->office()->create();
    $crewEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    EmployeeContract::factory()->create([
        'employee_id' => $crewEmployee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'status' => 'active',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.timesheets.store', $period), [
            'period_id' => $period->id,
            'employee_id' => $crewEmployee->id,
            'sign_on_standby_days' => 5,
        ])
        ->assertNotFound();
});

test('legacy crew payroll route redirects to payroll show when period is provided', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.crew_timesheets.view']);

    $period = PayrollPeriod::factory()->for($company)->create();

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('organization.crew-payroll.index', ['period_id' => $period->id]))
        ->assertRedirect(route('payroll.show', $period));
});

test('crew payroll tab paginates daily and monthly records separately', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.view']);

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
        'status' => PayrollPeriodStatus::Processing,
    ]);

    foreach (range(1, 25) as $index) {
        $employee = Employee::factory()->forCompany($company)->create([
            'employee_no' => sprintf('DLY-%03d', $index),
            'status' => 'active',
        ]);

        PayrollRecord::factory()->for($company)->create([
            'employee_id' => $employee->id,
            'period_id' => $period->id,
            'payroll_category' => PayrollCategory::Crew,
            'calculation_breakdown' => ['salary_structure' => 'daily'],
        ]);
    }

    foreach (range(1, 5) as $index) {
        $employee = Employee::factory()->forCompany($company)->create([
            'employee_no' => sprintf('MTH-%03d', $index),
            'status' => 'active',
        ]);

        PayrollRecord::factory()->for($company)->create([
            'employee_id' => $employee->id,
            'period_id' => $period->id,
            'payroll_category' => PayrollCategory::Crew,
            'calculation_breakdown' => ['salary_structure' => 'monthly'],
        ]);
    }

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', [
            'payrollPeriod' => $period,
            'per_page' => 20,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->has('payroll_records', 20)
            ->has('payroll_records_monthly', 5)
            ->where('payroll_records_pagination.total', 25)
            ->where('payroll_records_monthly_pagination.total', 5)
            ->where('payroll_records.0.salary_structure', 'daily')
            ->where('payroll_records_monthly.0.salary_structure', 'monthly')
            ->where('filters.crew_salary_structure', 'daily'));

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', [
            'payrollPeriod' => $period,
            'per_page' => 20,
            'crew_salary_structure' => 'monthly',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('payroll_records_monthly', 5)
            ->where('filters.crew_salary_structure', 'monthly'));
});

test('crew payroll draft board can filter rows by salary structure', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.crew_timesheets.view']);

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
        'status' => PayrollPeriodStatus::Draft,
    ]);

    $dailyEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'DLY-BOARD-01',
        'status' => 'active',
    ]);

    EmployeeContract::factory()->create([
        'employee_id' => $dailyEmployee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'salary_structure' => 'daily',
        'status' => 'active',
    ]);

    $monthlyEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'MTH-BOARD-01',
        'status' => 'active',
    ]);

    EmployeeContract::factory()->create([
        'employee_id' => $monthlyEmployee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'salary_structure' => 'monthly',
        'status' => 'active',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', ['payrollPeriod' => $period]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.employee.id', $dailyEmployee->id)
            ->where('rows.0.salary_structure', 'daily')
            ->where('filters.crew_salary_structure', 'daily'));

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', [
            'payrollPeriod' => $period,
            'crew_salary_structure' => 'monthly',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.employee.id', $monthlyEmployee->id)
            ->where('rows.0.salary_structure', 'monthly')
            ->where('filters.crew_salary_structure', 'monthly')
            ->where('department_tree.0.count', 1));
});
