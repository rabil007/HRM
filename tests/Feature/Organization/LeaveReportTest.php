<?php

use App\Exports\LeaveReportExport;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Support\Reports\LeaveReportFilters;
use App\Support\Reports\LeaveReportQuery;
use Inertia\Testing\AssertableInertia as Assert;

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
            ->where('summary.total_leave_days', 5)
            ->where('summary.approved_leave_days', 5)
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
            ->where('summary.approved_leave_days', 5));
});

test('leave report filters by search status employee leave type department and decision dates', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'leaveType' => $leaveType] = authorizeLeaveReport();

    $otherEmployee = createAttendanceLeaveEmployee($company, [
        'status' => 'active',
        'name' => 'Other Person',
        'employee_no' => 'LR-002',
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
            ->where('summary.total_leave_days', 5)
            ->where('summary.approved_leave_days', 3)
            ->where('summary.pending_leave_days', 2)
            ->where('summary.annual.total', 0)
            ->where('summary.sick.total', 0)
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
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('leave_requests', 0)
            ->where('summary.total_leave_days', 0));
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
            ->where('summary.total_leave_days', 5)
            ->where('summary.approved_leave_days', 5));

    expect((new LeaveReportQuery(
        $company->id,
        new LeaveReportFilters,
        $company->timezone,
        $user,
    ))->exportQuery()->count())->toBe(1);
});

test('leave report employee filter includes inactive employees with leave history', function () {
    ['user' => $user, 'company' => $company, 'employee' => $activeEmployee, 'leaveType' => $leaveType] = authorizeLeaveReport();

    $inactiveEmployee = createAttendanceLeaveEmployee($company, [
        'status' => 'inactive',
        'name' => 'Former Employee',
        'employee_no' => 'LR-OLD',
    ]);

    $historical = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $inactiveEmployee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2025-02-01',
        'end_date' => '2025-02-05',
        'total_days' => 5,
        'status' => 'approved',
    ]);

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $activeEmployee->id,
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
            ->where('filter_options.employees', fn ($options) => collect($options)->pluck('id')->sort()->values()->all() === collect([$activeEmployee->id, $inactiveEmployee->id])->sort()->values()->all()));

    $this->actingAs($user)
        ->get(route('organization.reports.leave.index', [
            'employee_id' => $inactiveEmployee->id,
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('leave_requests', 1)
            ->where('leave_requests.0.id', $historical->id));
});

test('leave report employee filter excludes hidden historical employees', function () {
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'officeEmployee' => $office] = makeEmployeeVisibilityFixtures();

    $user->update(['current_company_id' => $company->id]);
    restrictUserToDepartments($user, $company, [$marineDept->id]);
    grantCompanyPermissions($user, $company, ['reports.leave.view']);

    $leaveType = LeaveType::factory()->for($company)->create(['status' => 'active']);

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $office->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2025-01-01',
        'end_date' => '2025-01-03',
        'total_days' => 3,
        'status' => 'approved',
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.leave.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('leave_requests', 0)
            ->where('filter_options.employees', fn ($options) => collect($options)->pluck('id')->all() === []));
});

test('leave report leave type filter includes inactive types used historically', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'leaveType' => $activeType] = authorizeLeaveReport();

    $inactiveType = LeaveType::factory()->for($company)->create([
        'name' => 'Emergency Leave',
        'status' => 'inactive',
    ]);

    $historical = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $inactiveType->id,
        'start_date' => '2024-06-01',
        'end_date' => '2024-06-03',
        'total_days' => 3,
        'status' => 'approved',
    ]);

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $activeType->id,
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-05',
        'total_days' => 5,
        'status' => 'approved',
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.leave.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filter_options.leave_types', fn ($options) => collect($options)->pluck('id')->sort()->values()->all() === collect([$activeType->id, $inactiveType->id])->sort()->values()->all()));

    $this->actingAs($user)
        ->get(route('organization.reports.leave.index', [
            'leave_type_id' => $inactiveType->id,
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('leave_requests', 1)
            ->where('leave_requests.0.id', $historical->id));
});

/**
 * @return list<int>
 */
function leaveReportDepartmentTreeIds(mixed $tree): array
{
    $ids = [];

    foreach (collect($tree) as $node) {
        if (($node['id'] ?? null) !== null) {
            $ids[] = (int) $node['id'];
        }

        if (! empty($node['children'])) {
            $ids = array_merge($ids, leaveReportDepartmentTreeIds($node['children']));
        }
    }

    return $ids;
}

test('leave report department tree hides unauthorized departments', function () {
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'officeDept' => $officeDept, 'marineEmployee' => $marine, 'officeEmployee' => $office] = makeEmployeeVisibilityFixtures();

    $user->update(['current_company_id' => $company->id]);
    restrictUserToDepartments($user, $company, [$marineDept->id]);
    grantCompanyPermissions($user, $company, ['reports.leave.view']);

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
        'employee_id' => $office->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-03',
        'total_days' => 3,
        'status' => 'approved',
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.leave.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('department_tree', function ($tree) use ($marineDept, $officeDept): bool {
                $departmentIds = leaveReportDepartmentTreeIds($tree);

                return in_array($marineDept->id, $departmentIds, true)
                    && ! in_array($officeDept->id, $departmentIds, true);
            }));
});

test('leave report department tree counts include inactive and terminated employees with leave history', function () {
    ['user' => $user, 'company' => $company, 'employee' => $activeEmployee, 'leaveType' => $leaveType] = authorizeLeaveReport();

    $terminatedEmployee = createAttendanceLeaveEmployee($company, [
        'status' => 'terminated',
        'department_id' => $activeEmployee->department_id,
        'name' => 'Former Crew',
        'employee_no' => 'LR-TERM',
    ]);

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $activeEmployee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-03',
        'total_days' => 3,
        'status' => 'approved',
    ]);

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $terminatedEmployee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2025-01-01',
        'end_date' => '2025-01-03',
        'total_days' => 3,
        'status' => 'approved',
    ]);

    $departmentId = (int) $activeEmployee->department_id;

    $this->actingAs($user)
        ->get(route('organization.reports.leave.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('department_tree', function ($tree) use ($departmentId): bool {
                $departmentNode = collect($tree)->firstWhere('id', $departmentId);

                return $departmentNode !== null && $departmentNode['count'] === 2;
            }));
});

test('leave report filter options exclude employees with only soft deleted leave history', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employeeWithValidLeave, 'leaveType' => $leaveType] = authorizeLeaveReport();

    $validDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Valid History Dept',
        'code' => 'VHD',
        'status' => 'active',
    ]);

    $isolatedDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Deleted Only Dept',
        'code' => 'DOD',
        'status' => 'active',
    ]);

    $employeeWithValidLeave->update(['department_id' => $validDepartment->id]);

    $deletedOnlyEmployee = createAttendanceLeaveEmployee($company, [
        'status' => 'active',
        'name' => 'Deleted History Only',
        'employee_no' => 'LR-DEL',
        'department_id' => $isolatedDepartment->id,
    ]);

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employeeWithValidLeave->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-03',
        'total_days' => 3,
        'status' => 'approved',
    ]);

    $deletedRequest = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $deletedOnlyEmployee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-03',
        'total_days' => 3,
        'status' => 'approved',
    ]);
    $deletedRequest->delete();

    $this->actingAs($user)
        ->get(route('organization.reports.leave.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filter_options.employees', fn ($options) => collect($options)->pluck('id')->all() === [$employeeWithValidLeave->id])
            ->where('filter_options.departments', fn ($options) => collect($options)->pluck('id')->all() === [$validDepartment->id])
            ->where('department_tree', function ($tree) use ($isolatedDepartment, $validDepartment): bool {
                $departmentIds = leaveReportDepartmentTreeIds($tree);

                return ! in_array($isolatedDepartment->id, $departmentIds, true)
                    && in_array($validDepartment->id, $departmentIds, true);
            }));
});

test('leave report filter options exclude inactive leave types with only soft deleted leave history', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'leaveType' => $activeType] = authorizeLeaveReport();

    $inactiveType = LeaveType::factory()->for($company)->create([
        'name' => 'Retired Leave',
        'status' => 'inactive',
    ]);

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $activeType->id,
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-03',
        'total_days' => 3,
        'status' => 'approved',
    ]);

    $deletedRequest = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $inactiveType->id,
        'start_date' => '2024-01-01',
        'end_date' => '2024-01-03',
        'total_days' => 3,
        'status' => 'approved',
    ]);
    $deletedRequest->delete();

    $this->actingAs($user)
        ->get(route('organization.reports.leave.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filter_options.leave_types', fn ($options) => collect($options)->pluck('id')->all() === [$activeType->id]));
});

test('leave report department tree shows departments for unrestricted users', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'leaveType' => $leaveType] = authorizeLeaveReport();

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Unrestricted Dept',
        'code' => 'UND',
        'status' => 'active',
    ]);

    $employee->update(['department_id' => $department->id]);

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-03',
        'total_days' => 3,
        'status' => 'approved',
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.leave.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('department_tree', function ($tree) use ($department): bool {
                $departmentIds = leaveReportDepartmentTreeIds($tree);

                return in_array($department->id, $departmentIds, true);
            }));
});

test('leave report export headings exclude sensitive fields', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'leaveType' => $leaveType] = authorizeLeaveReport();

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

    $query = new LeaveReportQuery($company->id, new LeaveReportFilters, $company->timezone, $user);
    $export = LeaveReportExport::forQuery($query->exportQuery(), $company->timezone);
    $leaveRequest = $query->exportQuery()->firstOrFail();
    $mapped = $export->map($leaveRequest);

    expect($export->headings())->toContain('Employee Name', 'Leave From', 'Status')
        ->not->toContain('Reason', 'Attachments', 'Rejection Reason')
        ->and($mapped)->not->toContain('Private medical reason', 'secret.pdf');
});
