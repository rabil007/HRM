<?php

use App\Exports\LeaveReportExport;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Reports\LeaveReportFilters;
use App\Support\Reports\LeaveReportQuery;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{
 *     user: User,
 *     company: Company,
 *     employee: Employee,
 *     leaveType: LeaveType,
 *     branch: Branch
 * }
 */
function authorizeLeaveReport(): array
{
    $user = User::factory()->create();
    $country = Country::query()->create([
        'code' => 'LR'.fake()->unique()->numerify('##'),
        'name' => 'Leave Reportland',
        'dial_code' => '+999',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'LR'.fake()->unique()->numerify('##'),
        'name' => 'Leave Report Currency',
        'symbol' => 'L$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Leave Report Co',
        'slug' => 'leave-report-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    DB::table('company_user')->insert([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $user->update(['current_company_id' => $company->id]);
    grantCompanyPermissions($user, $company, [
        'reports.leave.view',
        'reports.leave.export',
        'employees.view',
    ]);

    $branch = Branch::query()->create([
        'company_id' => $company->id,
        'name' => 'Main Branch',
        'code' => 'MAIN',
        'status' => 'active',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Report Employee',
        'employee_no' => 'LR-001',
        'branch_id' => $branch->id,
    ]);

    $leaveType = LeaveType::factory()->for($company)->create([
        'name' => 'Annual Leave',
        'status' => 'active',
    ]);

    return compact('user', 'company', 'employee', 'leaveType', 'branch');
}

test('leave report requires authentication and view permission', function () {
    $this->get(route('organization.reports.leave.index'))
        ->assertRedirect(route('login'));

    ['user' => $user, 'company' => $company] = authorizeLeaveReport();
    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->actingAs($user)
        ->get(route('organization.reports.leave.index'))
        ->assertForbidden();
});

test('leave report is company scoped', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'leaveType' => $leaveType] = authorizeLeaveReport();

    $visible = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-05',
        'total_days' => 5,
        'status' => 'approved',
    ]);

    $otherCompany = Company::query()->create([
        'name' => 'Other Co',
        'slug' => 'other-co-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $company->country_id,
        'currency_id' => $company->currency_id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
    $foreignEmployee = Employee::factory()->forCompany($otherCompany)->create(['status' => 'active']);
    $foreignType = LeaveType::factory()->for($otherCompany)->create(['status' => 'active']);

    createLeaveRequestRecord([
        'company_id' => $otherCompany->id,
        'employee_id' => $foreignEmployee->id,
        'leave_type_id' => $foreignType->id,
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-05',
        'total_days' => 5,
        'status' => 'approved',
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.leave.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/reports/leave/index')
            ->has('leave_requests', 1)
            ->where('leave_requests.0.id', $visible->id)
            ->where('summary.total', 1)
            ->where('can.export', true));
});

test('leave period overlap includes cross-month leave and excludes non-overlapping leave', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'leaveType' => $leaveType] = authorizeLeaveReport();

    $overlapping = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-08-28',
        'end_date' => '2026-09-05',
        'total_days' => 9,
        'status' => 'approved',
    ]);

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-05',
        'total_days' => 5,
        'status' => 'approved',
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.leave.index', [
            'leave_from' => '2026-09-01',
            'leave_to' => '2026-09-30',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('leave_requests', 1)
            ->where('leave_requests.0.id', $overlapping->id)
            ->where('summary.total', 1));
});

test('leave report filters by search status employee leave type department branch and decision dates', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'leaveType' => $leaveType, 'branch' => $branch] = authorizeLeaveReport();

    $otherBranch = Branch::query()->create([
        'company_id' => $company->id,
        'name' => 'Satellite',
        'code' => 'SAT',
        'status' => 'active',
    ]);

    $otherEmployee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Other Person',
        'employee_no' => 'LR-002',
        'branch_id' => $otherBranch->id,
    ]);

    $otherType = LeaveType::factory()->for($company)->create([
        'name' => 'Sick Leave',
        'status' => 'active',
    ]);

    $target = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-07-10',
        'end_date' => '2026-07-12',
        'total_days' => 3,
        'status' => 'pending',
        'created_at' => '2026-07-01 09:00:00',
    ]);

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $otherEmployee->id,
        'leave_type_id' => $otherType->id,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-03',
        'total_days' => 3,
        'status' => 'approved',
        'approved_by' => $user->id,
        'decided_at' => '2026-08-02 10:00:00',
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.leave.index', [
            'search' => 'LR-001',
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'status' => 'pending',
            'department_id' => $employee->department_id,
            'branch_id' => $branch->id,
            'submitted_from' => '2026-07-01',
            'submitted_to' => '2026-07-31',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('leave_requests', 1)
            ->where('leave_requests.0.id', $target->id));

    $this->actingAs($user)
        ->get(route('organization.reports.leave.index', [
            'decided_from' => '2026-08-01',
            'decided_to' => '2026-08-31',
        ]))
        ->assertInertia(fn (Assert $page) => $page->has('leave_requests', 1));
});

test('leave report summary counts respect filters and visibility scope', function () {
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'officeDept' => $officeDept, 'marineEmployee' => $marine, 'officeEmployee' => $office] = makeEmployeeVisibilityFixtures();

    $user->update(['current_company_id' => $company->id]);
    restrictUserToDepartments($user, $company, [$marineDept->id]);
    grantCompanyPermissions($user, $company, [
        'reports.leave.view',
        'reports.leave.export',
    ]);

    $leaveType = LeaveType::factory()->for($company)->create(['status' => 'active']);

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $marine->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-03',
        'total_days' => 3,
        'status' => 'approved',
    ]);

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $marine->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-09-10',
        'end_date' => '2026-09-11',
        'total_days' => 2,
        'status' => 'pending',
    ]);

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $office->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-05',
        'total_days' => 5,
        'status' => 'approved',
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.leave.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('leave_requests', 2)
            ->where('summary.total', 2)
            ->where('summary.approved', 1)
            ->where('summary.pending', 1)
            ->where('summary.approved_leave_days', 3)
            ->where('summary.employees_taking_leave', 1)
            ->where('filter_options.employees', fn ($options) => collect($options)->pluck('id')->sort()->values()->all() === collect([$marine->id])->sort()->values()->all())
            ->where('filter_options.departments', fn ($options) => collect($options)->pluck('id')->all() === [$marineDept->id]));
});

test('foreign company filter ids cannot expose leave requests', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'leaveType' => $leaveType] = authorizeLeaveReport();

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-05',
        'total_days' => 5,
        'status' => 'approved',
    ]);

    $otherCompany = Company::query()->create([
        'name' => 'Filter Other Co',
        'slug' => 'filter-other-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $company->country_id,
        'currency_id' => $company->currency_id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
    $foreignEmployee = Employee::factory()->forCompany($otherCompany)->create(['status' => 'active']);
    $foreignType = LeaveType::factory()->for($otherCompany)->create(['status' => 'active']);

    $this->actingAs($user)
        ->get(route('organization.reports.leave.index', [
            'employee_id' => $foreignEmployee->id,
            'leave_type_id' => $foreignType->id,
            'department_id' => $foreignEmployee->department_id,
            'branch_id' => $foreignEmployee->branch_id,
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('leave_requests', 0)
            ->where('summary.total', 0));
});

test('soft deleted leave requests are excluded from leave report', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'leaveType' => $leaveType] = authorizeLeaveReport();

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-05',
        'total_days' => 5,
        'status' => 'approved',
    ]);

    $deleted = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-09-10',
        'end_date' => '2026-09-12',
        'total_days' => 3,
        'status' => 'approved',
    ]);
    $deleted->delete();

    $this->actingAs($user)
        ->get(route('organization.reports.leave.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('leave_requests', 1)
            ->where('summary.total', 1));

    expect((new LeaveReportQuery(
        $company->id,
        new LeaveReportFilters,
        $company->timezone,
        $user,
    ))->exportQuery()->count())->toBe(1);
});

test('leave report export headings exclude sensitive fields', function () {
    ['company' => $company, 'employee' => $employee, 'leaveType' => $leaveType] = authorizeLeaveReport();

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-05',
        'total_days' => 5,
        'status' => 'approved',
        'reason' => 'Private medical reason',
        'attachments' => ['secret.pdf'],
    ]);

    $query = new LeaveReportQuery($company->id, new LeaveReportFilters, $company->timezone);
    $export = LeaveReportExport::forQuery($query->exportQuery(), $company->timezone);
    $leaveRequest = $query->exportQuery()->firstOrFail();
    $mapped = $export->map($leaveRequest);

    expect($export->headings())->toContain('Employee Name', 'Leave From', 'Status')
        ->not->toContain('Reason', 'Attachments', 'Rejection Reason')
        ->and($mapped)->not->toContain('Private medical reason', 'secret.pdf');
});
