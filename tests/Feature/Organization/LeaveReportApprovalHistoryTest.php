<?php

use App\Enums\LeaveApprovalMode;
use App\Enums\LeaveRequestApprovalStatus;
use App\Enums\LeaveTypeCategory;
use App\Exports\LeaveReportExport;
use App\Models\Company;
use App\Models\LeaveRequestApproval;
use App\Models\LeaveRequestApprovalReassignment;
use App\Models\LeaveType;
use App\Models\User;
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

test('leave report hides foreign-company approver user fallback names', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'leaveType' => $leaveType] = authorizeLeaveReport();

    $foreignCompany = Company::query()->create([
        'name' => 'Foreign Approver Co',
        'slug' => 'foreign-approver-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $company->country_id,
        'currency_id' => $company->currency_id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
    $foreignUser = User::factory()->create(['name' => 'Foreign Fallback Approver']);
    $foreignEmployee = createAttendanceLeaveEmployee($foreignCompany, ['name' => 'Foreign Emp']);
    $localUser = User::factory()->create(['name' => 'Local Fallback Approver']);
    DB::table('company_user')->insert([
        'company_id' => $company->id,
        'user_id' => $localUser->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $request = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-02',
        'total_days' => 2,
        'status' => 'pending',
    ]);

    LeaveRequestApproval::factory()->create([
        'company_id' => $company->id,
        'leave_request_id' => $request->id,
        'sequence' => 1,
        'approver_employee_id' => $foreignEmployee->id,
        'approver_user_id' => $foreignUser->id,
        'status' => LeaveRequestApprovalStatus::Pending,
        'is_required' => true,
        'policy_step_label' => 'Foreign User Step',
    ]);
    LeaveRequestApproval::factory()->approved()->create([
        'company_id' => $company->id,
        'leave_request_id' => $request->id,
        'sequence' => 2,
        // Corrupt employee FK from another company; same-company user remains as fallback.
        'approver_employee_id' => $foreignEmployee->id,
        'approver_user_id' => $localUser->id,
        'is_required' => true,
        'policy_step_label' => 'Local User Step',
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.leave.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('leave_requests.0.approval_chain', function ($chain) {
                $names = collect($chain)->pluck('approver_name')->all();

                return in_array('Former / unavailable approver', $names, true)
                    && in_array('Local Fallback Approver', $names, true)
                    && ! in_array('Foreign Fallback Approver', $names, true)
                    && ! in_array('Foreign Emp', $names, true);
            }));

    expect($this->actingAs($user)->get(route('organization.reports.leave.index'))->getContent())
        ->not->toContain('Foreign Fallback Approver')
        ->not->toContain('Foreign Emp');
});

test('leave report any required progress reflects first decision wins', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'leaveType' => $leaveType] = authorizeLeaveReport();
    $rima = makeActionableApprover($company, ['name' => 'Rima']);
    $maher = makeActionableApprover($company, ['name' => 'Maher']);
    $adam = makeActionableApprover($company, ['name' => 'Adam']);

    $pending = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-11-01',
        'end_date' => '2026-11-02',
        'total_days' => 2,
        'status' => 'pending',
        'approval_mode' => LeaveApprovalMode::AnyRequired,
    ]);
    LeaveRequestApproval::factory()->create([
        'company_id' => $company->id,
        'leave_request_id' => $pending->id,
        'sequence' => 1,
        'approver_employee_id' => $rima['employee']->id,
        'approver_user_id' => $rima['user']->id,
        'status' => LeaveRequestApprovalStatus::Pending,
        'is_required' => true,
        'policy_step_label' => 'Rima',
    ]);
    LeaveRequestApproval::factory()->create([
        'company_id' => $company->id,
        'leave_request_id' => $pending->id,
        'sequence' => 2,
        'approver_employee_id' => $maher['employee']->id,
        'approver_user_id' => $maher['user']->id,
        'status' => LeaveRequestApprovalStatus::Pending,
        'is_required' => true,
        'policy_step_label' => 'Maher',
    ]);
    LeaveRequestApproval::factory()->create([
        'company_id' => $company->id,
        'leave_request_id' => $pending->id,
        'sequence' => 3,
        'approver_employee_id' => $adam['employee']->id,
        'approver_user_id' => $adam['user']->id,
        'status' => LeaveRequestApprovalStatus::Skipped,
        'is_required' => false,
        'policy_step_label' => 'Adam',
    ]);

    $approved = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-11-05',
        'end_date' => '2026-11-06',
        'total_days' => 2,
        'status' => 'approved',
        'approval_mode' => LeaveApprovalMode::AnyRequired,
    ]);
    LeaveRequestApproval::factory()->approved()->create([
        'company_id' => $company->id,
        'leave_request_id' => $approved->id,
        'sequence' => 1,
        'approver_employee_id' => $rima['employee']->id,
        'approver_user_id' => $rima['user']->id,
        'is_required' => true,
        'policy_step_label' => 'Rima',
    ]);
    LeaveRequestApproval::factory()->create([
        'company_id' => $company->id,
        'leave_request_id' => $approved->id,
        'sequence' => 2,
        'approver_employee_id' => $maher['employee']->id,
        'approver_user_id' => $maher['user']->id,
        'status' => LeaveRequestApprovalStatus::Cancelled,
        'is_required' => true,
        'policy_step_label' => 'Maher',
        'acted_at' => now(),
    ]);

    $rejected = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-11-10',
        'end_date' => '2026-11-11',
        'total_days' => 2,
        'status' => 'rejected',
        'approval_mode' => LeaveApprovalMode::AnyRequired,
    ]);
    LeaveRequestApproval::factory()->create([
        'company_id' => $company->id,
        'leave_request_id' => $rejected->id,
        'sequence' => 1,
        'approver_employee_id' => $rima['employee']->id,
        'approver_user_id' => $rima['user']->id,
        'status' => LeaveRequestApprovalStatus::Cancelled,
        'is_required' => true,
        'policy_step_label' => 'Rima',
        'acted_at' => now(),
    ]);
    LeaveRequestApproval::factory()->create([
        'company_id' => $company->id,
        'leave_request_id' => $rejected->id,
        'sequence' => 2,
        'approver_employee_id' => $maher['employee']->id,
        'approver_user_id' => $maher['user']->id,
        'status' => LeaveRequestApprovalStatus::Rejected,
        'is_required' => true,
        'policy_step_label' => 'Maher',
        'acted_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.leave.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('leave_requests', 3)
            ->where('leave_requests', function ($rows) {
                $byStatus = collect($rows)->keyBy(fn ($row) => $row['status']);

                $pending = $byStatus->get('pending');
                $approved = $byStatus->get('approved');
                $rejected = $byStatus->get('rejected');

                return $pending !== null
                    && $pending['approval_progress']['current_status'] === 'pending'
                    && $pending['approval_progress']['label'] === 'Pending — any 1 of 2 approvers can act'
                    && $pending['approval_progress']['waiting_for'] === null
                    && count($pending['approval_chain']) === 2
                    && $approved !== null
                    && $approved['approval_progress']['current_status'] === 'approved'
                    && $approved['approval_progress']['label'] === 'Approved by Rima'
                    && $approved['approval_progress']['approved_steps'] === 1
                    && count($approved['approval_chain']) === 2
                    && collect($approved['approval_chain'])->pluck('status')->all() === ['approved', 'cancelled']
                    && $rejected !== null
                    && $rejected['approval_progress']['current_status'] === 'rejected'
                    && $rejected['approval_progress']['label'] === 'Rejected by Maher'
                    && collect($rejected['approval_chain'])->pluck('status')->all() === ['cancelled', 'rejected'];
            }));

    $export = LeaveReportExport::forQuery(
        (new LeaveReportQuery($company->id, new LeaveReportFilters, 'Asia/Dubai', $user))->exportQuery(),
        'Asia/Dubai',
    );
    $flat = collect($export->query()->get())
        ->map(fn ($row) => $export->map($row))
        ->flatten()
        ->implode(' | ');

    expect($flat)->toContain('Approved by Rima')
        ->and($flat)->toContain('Rejected by Maher')
        ->and($flat)->toContain('Pending — any 1 of 2 approvers can act')
        ->and($flat)->not->toContain('1 / 2 approved');
});
