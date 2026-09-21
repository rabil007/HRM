<?php

use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Inertia\Testing\AssertableInertia as Assert;

test('restricted user cannot select or view calendar of employee outside role visibility scope', function () {
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'marineEmployee' => $marine, 'officeEmployee' => $office] = makeEmployeeVisibilityFixtures();

    // Link user to Marine employee
    $marine->update(['user_id' => $user->id]);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.view_all',
        'attendance.leave-requests.create',
    ]);
    restrictUserToDepartments($user, $company, [$marineDept->id]);

    $leaveType = LeaveType::query()->create([
        'company_id' => $company->id,
        'name' => 'Annual Leave',
        'code' => 'AL',
        'color' => '#10b981',
        'status' => 'active',
    ]);

    // Create leave request for Office employee (hidden)
    LeaveRequest::forceCreate([
        'company_id' => $company->id,
        'employee_id' => $office->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-05',
        'total_days' => 5,
        'status' => 'approved',
    ]);

    // Create leave request for Marine employee (visible)
    LeaveRequest::forceCreate([
        'company_id' => $company->id,
        'employee_id' => $marine->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-03',
        'total_days' => 3,
        'status' => 'approved',
    ]);

    // Attempt to view hidden Office employee's calendar
    $response = $this->actingAs($user)->get(route('attendance.calendar.index', [
        'year' => 2026,
        'employee_id' => $office->id,
    ]));

    $response->assertOk();

    $response->assertInertia(function (Assert $page) use ($marine, $office) {
        $page->component('attendance/calendar');

        // selected_employee_id must fallback to linked employee (marine), not office
        $page->where('selected_employee_id', $marine->id);

        // employees list must only contain visible employees
        $employeeIds = collect($page->toArray()['props']['employees'])->pluck('id')->all();
        expect($employeeIds)->toContain($marine->id)
            ->and($employeeIds)->not->toContain($office->id);

        // form_employees list must only contain visible employees
        $formEmployeeIds = collect($page->toArray()['props']['form_employees'])->pluck('id')->all();
        expect($formEmployeeIds)->toContain($marine->id)
            ->and($formEmployeeIds)->not->toContain($office->id);
    });
});
