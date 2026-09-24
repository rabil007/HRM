<?php

use App\Enums\LeaveTypeCategory;
use App\Exports\LeaveBalanceReportExport;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Support\Reports\LeaveBalanceReportFilters;
use App\Support\Reports\LeaveBalanceReportQuery;
use App\Support\Settings\CompanyTimezone;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Facades\Excel;

function makeBalance(Employee $employee, LeaveType $leaveType, int $year, array $days): LeaveBalance
{
    return LeaveBalance::factory()->forEmployee($employee)->forLeaveType($leaveType)->create(array_merge([
        'year' => $year,
        'entitled_days' => 30,
        'carried_days' => 2,
        'used_days' => 4,
        'pending_days' => 1,
    ], $days));
}

test('leave balance report requires view permission and does not provision balances', function () {
    ['user' => $user, 'company' => $company] = authorizeLeaveReport();
    grantCompanyPermissions($user, $company, ['employees.view']);

    $before = LeaveBalance::query()->count();

    $this->actingAs($user)
        ->get(route('organization.reports.leave-balances.index'))
        ->assertForbidden();

    expect(LeaveBalance::query()->count())->toBe($before);

    grantCompanyPermissions($user, $company, ['reports.leave_balance.view']);

    $this->actingAs($user)
        ->get(route('organization.reports.leave-balances.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/reports/leave-balances/index')
            ->has('balances', 0)
            ->where('can.export', false)
            ->where('can.update_opening', false));

    expect(LeaveBalance::query()->count())->toBe($before);

    $this->actingAs($user)
        ->get(route('organization.reports.leave-balances.export'))
        ->assertForbidden();
});

test('leave balance report reads persisted snapshots with filters visibility and export', function () {
    $this->travelTo('2025-12-31 20:00:00');

    ['user' => $user, 'company' => $company, 'employee' => $active, 'leaveType' => $annual] = authorizeLeaveReport();
    grantCompanyPermissions($user, $company, ['reports.leave_balance.view', 'employees.view']);
    $company->update(['timezone' => 'Pacific/Kiritimati']);
    $user->update(['current_company_id' => $company->id]);

    $annual->update(['name' => 'Annual', 'category' => LeaveTypeCategory::Annual]);
    $sick = LeaveType::factory()->for($company)->create([
        'name' => 'Sick',
        'category' => LeaveTypeCategory::Sick,
        'status' => 'inactive',
    ]);
    $deletedType = LeaveType::factory()->for($company)->create([
        'name' => 'Archived Type',
        'category' => LeaveTypeCategory::Other,
    ]);

    $inactive = createAttendanceLeaveEmployee($company, [
        'status' => 'inactive',
        'name' => 'Inactive Person',
        'employee_no' => 'BAL-OFF',
        'department_id' => $active->department_id,
    ]);
    $terminated = createAttendanceLeaveEmployee($company, [
        'status' => 'terminated',
        'name' => 'Former Person',
        'employee_no' => 'BAL-END',
        'department_id' => $active->department_id,
    ]);

    $businessYear = (int) now(CompanyTimezone::forCompanyId($company->id))->year;
    expect($businessYear)->toBe(2026);

    $activeBalance = makeBalance($active, $annual, 2026, []);
    makeBalance($inactive, $sick, 2026, ['entitled_days' => 10, 'carried_days' => 0, 'used_days' => 1, 'pending_days' => 0]);
    makeBalance($terminated, $deletedType, 2025, ['entitled_days' => 5, 'carried_days' => 1, 'used_days' => 2, 'pending_days' => 1]);
    $deletedType->delete();

    $other = Company::query()->create([
        'name' => 'Balance Other',
        'slug' => 'balance-other-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $company->country_id,
        'currency_id' => $company->currency_id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
    $foreignEmployee = createAttendanceLeaveEmployee($other, ['status' => 'active', 'name' => 'Foreign Balance']);
    $foreignType = LeaveType::factory()->for($other)->create();
    makeBalance($foreignEmployee, $foreignType, 2026, []);

    $before = LeaveBalance::withTrashed()->count();

    $this->actingAs($user)
        ->get(route('organization.reports.leave-balances.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.year', '2026')
            ->has('balances', 2)
            ->where('balances', function ($balances): bool {
                $row = collect($balances)->firstWhere('leave_type.name', 'Annual');

                return $row !== null
                    && (float) $row['base_entitlement'] === 30.0
                    && (float) $row['carried_days'] === 2.0
                    && (float) $row['total_available'] === 32.0
                    && (float) $row['opening_used_days'] === 0.0
                    && (float) $row['used_days'] === 4.0
                    && (float) $row['pending_days'] === 1.0
                    && (float) $row['remaining_days'] === 27.0;
            })
            ->where('summary.balance_rows', 2)
            ->where('filter_options.years', fn ($years) => collect($years)->contains(2026) && collect($years)->contains(2025))
            ->has('department_tree')
            ->where('department_tree_selected_id', null));

    expect(LeaveBalance::withTrashed()->count())->toBe($before);

    $this->actingAs($user)
        ->get(route('organization.reports.leave-balances.index', ['year' => 2025]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('balances', 1)
            ->where('balances.0.employee.name', 'Former Person')
            ->where('balances.0.leave_type.name', 'Archived Type'));

    $this->actingAs($user)
        ->get(route('organization.reports.leave-balances.index', [
            'year' => 2026,
            'employee_id' => $inactive->id,
            'leave_type_id' => $sick->id,
            'category' => 'sick',
            'employee_status' => 'inactive',
            'department_id' => $inactive->department_id,
            'search' => 'BAL-OFF',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('balances', 1)
            ->where('balances.0.employee.name', 'Inactive Person')
            ->where('department_tree_selected_id', $inactive->department_id));

    expect($this->actingAs($user)->get(route('organization.reports.leave-balances.index'))->getContent())
        ->not->toContain('Foreign Balance');

    grantCompanyPermissions($user, $company, ['reports.leave_balance.export']);
    Excel::fake();

    $this->actingAs($user)
        ->get(route('organization.reports.leave-balances.export', ['format' => 'xlsx', 'year' => 2026]))
        ->assertOk();

    Excel::assertDownloaded('leave-balance-report-'.now()->toDateString().'.xlsx', function (LeaveBalanceReportExport $export): bool {
        return $export->query()->count() === 2
            && $export->headings()[0] === 'Employee No';
    });

    $this->actingAs($user)
        ->get(route('organization.reports.leave-balances.export', ['format' => 'csv', 'year' => 2026]))
        ->assertOk();

    $query = new LeaveBalanceReportQuery(
        $company->id,
        new LeaveBalanceReportFilters(year: '2026'),
        $user,
    );
    $mapped = $query->exportQuery()->get()->map(fn (LeaveBalance $balance) => LeaveBalanceReportExport::forQuery($query->exportQuery())->map($balance));

    expect($mapped->flatten()->implode('|'))->toContain('Annual')
        ->and($activeBalance->fresh()->entitled_days)->toEqual(30);
});

test('leave balance report filter options hide employees outside department visibility', function () {
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'marineEmployee' => $marine, 'officeEmployee' => $office] = makeEmployeeVisibilityFixtures();
    $user->update(['current_company_id' => $company->id]);
    restrictUserToDepartments($user, $company, [$marineDept->id]);
    grantCompanyPermissions($user, $company, ['reports.leave_balance.view', 'reports.leave_balance.export']);

    $leaveType = LeaveType::factory()->for($company)->create(['category' => LeaveTypeCategory::Annual]);
    makeBalance($marine, $leaveType, 2026, []);
    makeBalance($office, $leaveType, 2026, []);

    $this->actingAs($user)
        ->get(route('organization.reports.leave-balances.index', ['year' => 2026]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('balances', 1)
            ->where('balances.0.employee.id', $marine->id)
            ->where('summary.balance_rows', 1)
            ->where('filter_options.employees', fn ($options) => collect($options)->pluck('id')->all() === [$marine->id]));

    expect($this->actingAs($user)->get(route('organization.reports.leave-balances.index', ['year' => 2026]))->getContent())
        ->not->toContain('Office Staff');
});

test('leave balance report includes employee photo when the viewer can access the employee', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'leaveType' => $leaveType] = authorizeLeaveReport();
    grantCompanyPermissions($user, $company, ['reports.leave_balance.view', 'employees.view']);
    $user->update(['current_company_id' => $company->id]);

    $employee->update([
        'name' => 'Photo Person',
        'image' => 'employees/photos/photo-person.jpg',
    ]);
    makeBalance($employee, $leaveType, 2026, []);

    $this->actingAs($user)
        ->get(route('organization.reports.leave-balances.index', ['year' => 2026]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('balances', 1)
            ->where('balances.0.employee.name', 'Photo Person')
            ->where('balances.0.employee.image', 'employees/photos/photo-person.jpg')
            ->where('balances.0.employee.can_view', true));
});

test('leave balance report hides employee photo without employees.view', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'leaveType' => $leaveType] = authorizeLeaveReport();
    grantCompanyPermissions($user, $company, ['reports.leave_balance.view']);
    $user->update(['current_company_id' => $company->id]);

    $employee->update(['image' => 'employees/photos/hidden.jpg']);
    makeBalance($employee, $leaveType, 2026, []);

    $this->actingAs($user)
        ->get(route('organization.reports.leave-balances.index', ['year' => 2026]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('balances', 1)
            ->where('balances.0.employee.image', null)
            ->where('balances.0.employee.can_view', false));
});
