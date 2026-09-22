<?php

use App\Enums\LeaveRequestApprovalStatus;
use App\Enums\LeaveTypeCategory;
use App\Exports\LeaveReportExport;
use App\Models\LeaveRequestApproval;
use App\Models\LeaveRequestApprovalReassignment;
use App\Models\LeaveType;
use App\Support\Reports\LeaveReportFilters;
use App\Support\Reports\LeaveReportQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('leave report returns the required approval chain progress and reassignment history', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'leaveType' => $leaveType] = authorizeLeaveReport();
    $mohamed = makeActionableApprover($company, ['name' => 'Mohamed']);
    $rima = makeActionableApprover($company, ['name' => 'Rima']);
    $ahmed = makeActionableApprover($company, ['name' => 'Ahmed']);
    $fyi = makeActionableApprover($company, ['name' => 'Notifier']);

    $leaveType->update(['category' => LeaveTypeCategory::Annual]);

    $request = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-03',
        'total_days' => 3,
        'status' => 'pending',
        'reason' => 'PRIVATE-LEAVE-REASON',
    ]);

    LeaveRequestApproval::factory()->approved()->create([
        'company_id' => $company->id,
        'leave_request_id' => $request->id,
        'sequence' => 1,
        'policy_step_label' => 'Department Manager',
        'approver_employee_id' => $mohamed['employee']->id,
        'approver_user_id' => $mohamed['user']->id,
        'acted_at' => CarbonImmutable::parse('2026-09-20 10:30:00', 'Asia/Dubai'),
        'comments' => 'PRIVATE-COMMENT',
    ]);
    $hrStep = LeaveRequestApproval::factory()->approved()->create([
        'company_id' => $company->id,
        'leave_request_id' => $request->id,
        'sequence' => 2,
        'policy_step_label' => 'HR Manager',
        'approver_employee_id' => $rima['employee']->id,
        'approver_user_id' => $rima['user']->id,
        'acted_at' => CarbonImmutable::parse('2026-09-21 09:15:00', 'Asia/Dubai'),
        'comments' => 'PRIVATE-COMMENT-2',
    ]);
    LeaveRequestApproval::factory()->create([
        'company_id' => $company->id,
        'leave_request_id' => $request->id,
        'sequence' => 3,
        'policy_step_label' => 'General Manager',
        'approver_employee_id' => $ahmed['employee']->id,
        'approver_user_id' => $ahmed['user']->id,
        'status' => LeaveRequestApprovalStatus::Pending,
        'acted_at' => '2026-09-22 01:00:00',
    ]);
    LeaveRequestApproval::factory()->create([
        'company_id' => $company->id,
        'leave_request_id' => $request->id,
        'sequence' => 4,
        'policy_step_label' => 'FYI',
        'approver_employee_id' => $fyi['employee']->id,
        'approver_user_id' => $fyi['user']->id,
        'status' => LeaveRequestApprovalStatus::Skipped,
        'is_required' => false,
    ]);

    LeaveRequestApprovalReassignment::query()->create([
        'company_id' => $company->id,
        'leave_request_id' => $request->id,
        'leave_request_approval_id' => $hrStep->id,
        'sequence' => 2,
        'policy_step_label' => 'HR Manager',
        'from_approver_name' => 'Rima',
        'to_approver_name' => 'Sara',
        'reassigned_by_name' => 'Admin',
        'reason' => 'SECRET-REASON',
        'created_at' => CarbonImmutable::parse('2026-09-21 09:15:00', 'Asia/Dubai'),
        'updated_at' => CarbonImmutable::parse('2026-09-21 09:15:00', 'Asia/Dubai'),
    ]);

    $rejected = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-02',
        'total_days' => 2,
        'status' => 'rejected',
    ]);
    LeaveRequestApproval::factory()->create([
        'company_id' => $company->id,
        'leave_request_id' => $rejected->id,
        'sequence' => 1,
        'policy_step_label' => 'HR Manager',
        'approver_employee_id' => $rima['employee']->id,
        'approver_user_id' => $rima['user']->id,
        'status' => LeaveRequestApprovalStatus::Rejected,
        'acted_at' => CarbonImmutable::parse('2026-09-21 09:15:00', 'Asia/Dubai'),
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $response = $this->actingAs($user)
        ->get(route('organization.reports.leave.index'))
        ->assertOk();

    $approvalQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'leave_request_approvals'))
        ->count();

    expect($approvalQueries)->toBeLessThanOrEqual(3);

    $response->assertInertia(fn (Assert $page) => $page
        ->has('leave_requests', 2)
        ->where('leave_requests.1.approval_progress.required_steps', 3)
        ->where('leave_requests.1.approval_progress.approved_steps', 2)
        ->where('leave_requests.1.approval_progress.label', '2 / 3 approved')
        ->where('leave_requests.1.approval_progress.waiting_for', 'Ahmed')
        ->where('leave_requests.1.approval_chain', fn ($chain) => count($chain) === 3
            && $chain[0]['approver_name'] === 'Mohamed'
            && str_contains((string) $chain[0]['acted_at'], '10:30')
            && $chain[2]['status'] === 'pending'
            && $chain[2]['acted_at'] === null
            && $chain[2]['is_current_action_step'] === true)
        ->where('leave_requests.1.reassignments.0.from_name', 'Rima')
        ->where('leave_requests.1.reassignments.0.to_name', 'Sara')
        ->missing('leave_requests.1.reassignments.0.reason')
        ->missing('leave_requests.1.reason')
        ->where('leave_requests.0.approval_progress.label', 'Rejected by Rima'));

    expect($response->getContent())
        ->not->toContain('PRIVATE-LEAVE-REASON')
        ->not->toContain('PRIVATE-COMMENT')
        ->not->toContain('SECRET-REASON');

    grantCompanyPermissions($user, $company, [
        'reports.leave.view',
        'reports.leave.export',
        'employees.view',
        'audit.view',
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.leave.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('leave_requests.1.reassignments.0.reason', 'SECRET-REASON'));

    $hrStep->delete();

    $this->actingAs($user)
        ->get(route('organization.reports.leave.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('leave_requests.1.reassignments.0.from_name', 'Rima')
            ->where('leave_requests.1.reassignments.0.to_name', 'Sara')
            ->where('leave_requests.1.reassignments.0.reassigned_by', 'Admin'));

    $export = LeaveReportExport::forQuery(
        (new LeaveReportQuery($company->id, new LeaveReportFilters, 'Asia/Dubai', $user))->exportQuery(),
        'Asia/Dubai',
    );
    $mapped = collect($export->query()->get())->map(fn ($row) => $export->map($row));
    $flat = $mapped->flatten()->implode(' | ');

    expect($export->headings())->toContain('Approval Chain', 'Reassignment Summary')
        ->and($flat)->toContain('Department Manager — Mohamed — Approved — 20 Sep 2026 10:30')
        ->and($flat)->toContain('Step 2: Rima → Sara on 21 Sep 2026')
        ->and($flat)->not->toContain('SECRET-REASON')
        ->and($flat)->not->toContain('PRIVATE-COMMENT')
        ->and($flat)->not->toContain('PRIVATE-LEAVE-REASON');
});

test('leave report history and filters omit employees outside the visibility scope', function () {
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'officeDept' => $officeDept, 'marineEmployee' => $marine, 'officeEmployee' => $office] = makeEmployeeVisibilityFixtures();
    $user->update(['current_company_id' => $company->id]);
    restrictUserToDepartments($user, $company, [$marineDept->id]);
    grantCompanyPermissions($user, $company, ['reports.leave.view', 'reports.leave.export']);

    $leaveType = LeaveType::factory()->for($company)->create(['status' => 'active']);
    $approver = makeActionableApprover($company, ['name' => 'Hidden Approver']);

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $marine->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-02',
        'total_days' => 2,
        'status' => 'approved',
    ]);

    $hidden = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $office->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-09-03',
        'end_date' => '2026-09-04',
        'total_days' => 2,
        'status' => 'pending',
    ]);
    LeaveRequestApproval::factory()->create([
        'company_id' => $company->id,
        'leave_request_id' => $hidden->id,
        'approver_employee_id' => $approver['employee']->id,
        'approver_user_id' => $approver['user']->id,
        'policy_step_label' => 'Office only',
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.leave.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('leave_requests', 1)
            ->where('leave_requests.0.employee.id', $marine->id)
            ->where('filter_options.employees', fn ($options) => collect($options)->pluck('id')->all() === [$marine->id])
            ->where('filter_options.departments', fn ($options) => collect($options)->pluck('id')->all() === [$marineDept->id]));

    expect($this->actingAs($user)->get(route('organization.reports.leave.index'))->getContent())
        ->not->toContain('Office Staff')
        ->not->toContain('Hidden Approver')
        ->not->toContain($officeDept->name);
});
