<?php

use App\Exports\LeaveReportExport;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Support\Reports\LeaveReportFilters;
use App\Support\Reports\LeaveReportQuery;
use Maatwebsite\Excel\Facades\Excel;

function makeLeaveReportExportFixture(): array
{
    $fixtures = authorizeLeaveReport();

    $fixtures['approved'] = createLeaveRequestRecord([
        'company_id' => $fixtures['company']->id,
        'employee_id' => $fixtures['employee']->id,
        'leave_type_id' => $fixtures['leaveType']->id,
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-05',
        'total_days' => 5,
        'status' => 'approved',
        'approved_by' => $fixtures['user']->id,
        'decided_at' => '2026-09-02 10:00:00',
    ]);

    $fixtures['pending'] = createLeaveRequestRecord([
        'company_id' => $fixtures['company']->id,
        'employee_id' => $fixtures['employee']->id,
        'leave_type_id' => $fixtures['leaveType']->id,
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-03',
        'total_days' => 3,
        'status' => 'pending',
    ]);

    return $fixtures;
}

test('leave report export requires export permission', function () {
    ['user' => $user, 'company' => $company] = authorizeLeaveReport();
    grantCompanyPermissions($user, $company, ['reports.leave.view']);

    $this->actingAs($user)
        ->get(route('organization.reports.leave.export'))
        ->assertForbidden();
});

test('leave report exports excel and csv with active filters', function () {
    Excel::fake();
    ['user' => $user] = makeLeaveReportExportFixture();

    $this->actingAs($user)
        ->get(route('organization.reports.leave.export', [
            'format' => 'xlsx',
            'status' => 'approved',
        ]))
        ->assertOk();

    Excel::assertDownloaded(
        'leave-report-'.now()->toDateString().'.xlsx',
        fn (LeaveReportExport $export): bool => $export->query()->count() === 1
            && $export->query()->first()?->status === 'approved',
    );

    $this->actingAs($user)
        ->get(route('organization.reports.leave.export', [
            'format' => 'csv',
            'status' => 'pending',
        ]))
        ->assertOk();

    Excel::assertDownloaded(
        'leave-report-'.now()->toDateString().'.csv',
        fn (LeaveReportExport $export): bool => $export->query()->count() === 1
            && $export->query()->first()?->status === 'pending',
    );
});

test('leave report export returns all filtered rows regardless of pagination', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'leaveType' => $leaveType] = makeLeaveReportExportFixture();

    foreach (range(1, 30) as $index) {
        createLeaveRequestRecord([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-11-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'end_date' => '2026-11-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'total_days' => 1,
            'status' => 'approved',
        ]);
    }

    $query = new LeaveReportQuery($company->id, new LeaveReportFilters(status: 'approved'), $company->timezone, $user);

    expect($query->paginate(25)->total())->toBe(31)
        ->and($query->exportQuery()->count())->toBe(31);
});

test('leave report export excludes employees outside visibility scope', function () {
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'officeDept' => $officeDept, 'marineEmployee' => $marine, 'officeEmployee' => $office] = makeEmployeeVisibilityFixtures();

    $user->update(['current_company_id' => $company->id]);
    restrictUserToDepartments($user, $company, [$marineDept->id]);
    grantCompanyPermissions($user, $company, ['reports.leave.view', 'reports.leave.export']);

    $leaveType = LeaveType::factory()->for($company)->create(['status' => 'active']);

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $marine->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-12-01',
        'end_date' => '2026-12-02',
        'total_days' => 2,
        'status' => 'approved',
    ]);

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $office->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-12-01',
        'end_date' => '2026-12-02',
        'total_days' => 2,
        'status' => 'approved',
    ]);

    $query = new LeaveReportQuery($company->id, new LeaveReportFilters, $company->timezone, $user);
    $employeeIds = $query->exportQuery()->pluck('employee_id')->all();

    expect($employeeIds)->toContain($marine->id)
        ->not->toContain($office->id);
});

test('leave report export excludes other company records', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'leaveType' => $leaveType] = makeLeaveReportExportFixture();

    $otherEmployee = Employee::factory()->create(['status' => 'active']);
    $otherType = LeaveType::factory()->for($otherEmployee->company)->create(['status' => 'active']);

    createLeaveRequestRecord([
        'company_id' => $otherEmployee->company_id,
        'employee_id' => $otherEmployee->id,
        'leave_type_id' => $otherType->id,
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-05',
        'total_days' => 5,
        'status' => 'approved',
    ]);

    $query = new LeaveReportQuery($company->id, new LeaveReportFilters, $company->timezone, $user);
    $employeeIds = $query->exportQuery()->pluck('employee_id')->unique()->values()->all();

    expect($employeeIds)->toBe([$employee->id]);
});

test('leave report export includes historical inactive employee records', function () {
    Excel::fake();
    ['user' => $user, 'company' => $company, 'leaveType' => $leaveType] = makeLeaveReportExportFixture();

    $inactiveEmployee = Employee::factory()->forCompany($company)->create([
        'status' => 'terminated',
        'name' => 'Former Crew',
        'employee_no' => 'LR-TERM',
    ]);

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $inactiveEmployee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2025-03-01',
        'end_date' => '2025-03-05',
        'total_days' => 5,
        'status' => 'approved',
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.leave.export', [
            'format' => 'xlsx',
            'employee_id' => $inactiveEmployee->id,
        ]))
        ->assertOk();

    Excel::assertDownloaded(
        'leave-report-'.now()->toDateString().'.xlsx',
        fn (LeaveReportExport $export): bool => $export->query()->count() === 1
            && $export->query()->first()?->employee_id === $inactiveEmployee->id,
    );
});
