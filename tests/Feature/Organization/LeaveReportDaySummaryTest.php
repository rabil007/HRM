<?php

use App\Enums\LeaveTypeCategory;
use App\Enums\LeaveTypePayrollTreatment;
use App\Models\LeaveType;
use Inertia\Testing\AssertableInertia as Assert;

test('leave report day summary uses category and clips only the leave period', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'leaveType' => $annual] = authorizeLeaveReport();
    $annual->update([
        'name' => 'Vacation',
        'code' => 'VAC',
        'category' => LeaveTypeCategory::Annual,
        'payroll_treatment' => LeaveTypePayrollTreatment::Unpaid,
    ]);
    $sick = LeaveType::factory()->for($company)->create([
        'name' => 'Medical rest',
        'code' => 'MED',
        'category' => LeaveTypeCategory::Sick,
        'payroll_treatment' => LeaveTypePayrollTreatment::Paid,
    ]);
    $other = LeaveType::factory()->for($company)->create([
        'name' => 'Annual Leave',
        'code' => 'AL',
        'category' => LeaveTypeCategory::Other,
    ]);

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $annual->id,
        'start_date' => '2026-07-30',
        'end_date' => '2026-08-02',
        'total_days' => 4,
        'status' => 'approved',
        'created_at' => '2026-06-01 09:00:00',
    ]);
    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $sick->id,
        'start_date' => '2026-08-10',
        'end_date' => '2026-08-11',
        'total_days' => 2,
        'status' => 'pending',
    ]);
    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $other->id,
        'start_date' => '2026-08-03',
        'end_date' => '2026-08-03',
        'total_days' => 1,
        'status' => 'rejected',
    ]);
    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $other->id,
        'start_date' => '2026-08-04',
        'end_date' => '2026-08-04',
        'total_days' => 1,
        'status' => 'cancelled',
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.leave.index', [
            'leave_from' => '2026-08-01',
            'leave_to' => '2026-08-31',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.approved_leave_days', 2)
            ->where('summary.pending_leave_days', 2)
            ->where('summary.total_leave_days', 4)
            ->where('summary.annual.approved', 2)
            ->where('summary.annual.total', 2)
            ->where('summary.sick.pending', 2)
            ->where('summary.sick.total', 2)
            ->where('summary.total_requests', 4)
            ->where(
                'summary.leave_types',
                fn ($types) => collect($types)->pluck('request_count', 'code')->all() === [
                    'AL' => 2,
                    'MED' => 1,
                    'VAC' => 1,
                ],
            ));

    $annual->update(['name' => 'Time off', 'code' => 'TO', 'payroll_treatment' => LeaveTypePayrollTreatment::Paid]);

    $this->actingAs($user)
        ->get(route('organization.reports.leave.index', [
            'submitted_from' => '2026-06-01',
            'submitted_to' => '2026-06-30',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('leave_requests', 1)
            ->where('summary.approved_leave_days', 4)
            ->where('summary.annual.total', 4)
            ->where('summary.total_leave_days', 4));
});

test('leave report summaries follow employee visibility and request filters', function () {
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'marineEmployee' => $marine, 'officeEmployee' => $office] = makeEmployeeVisibilityFixtures();
    $user->update(['current_company_id' => $company->id]);
    restrictUserToDepartments($user, $company, [$marineDept->id]);
    grantCompanyPermissions($user, $company, ['reports.leave.view']);

    $leaveType = LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'category' => LeaveTypeCategory::Annual,
    ]);

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
        'end_date' => '2026-09-05',
        'total_days' => 5,
        'status' => 'approved',
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.leave.index', [
            'department_id' => $marineDept->id,
            'employee_id' => $marine->id,
            'leave_type_id' => $leaveType->id,
            'status' => 'approved',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('leave_requests', 1)
            ->where('summary.approved_leave_days', 3)
            ->where('summary.total_leave_days', 3)
            ->where('summary.annual.approved', 3)
            ->where('summary.total_requests', 1)
            ->where(
                'summary.leave_types',
                fn ($types) => collect($types)->sum('request_count') === 1,
            ));
});
