<?php

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('dashboard returns employee analytics and document compliance props', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeDocumentFixtures();

    Employee::factory()->count(3)->create([
        'company_id' => $company->id,
        'status' => 'active',
    ]);

    Employee::factory()->create([
        'company_id' => $company->id,
        'status' => 'on_leave',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->has('employee_analytics')
            ->has('employee_analytics.total')
            ->has('employee_analytics.active')
            ->has('employee_analytics.on_leave')
            ->has('employee_analytics.inactive')
            ->has('employee_analytics.terminated')
            ->has('employee_analytics.new_hires_this_month')
            ->has('document_compliance')
            ->has('document_compliance.total_documents')
            ->has('document_compliance.expired')
            ->has('document_compliance.expiring_30')
            ->has('document_compliance.expiring_15')
            ->has('document_compliance.expiring_7')
            ->has('document_compliance.uploaded_this_month')
            ->has('document_compliance.compliance_rate')
            ->has('workforce_trends')
            ->has('employees_by_department')
            ->has('employees_by_branch')
            ->has('document_health')
            ->has('organization_snapshot')
            ->has('recent_hires')
            ->has('attendance_analytics')
            ->has('attendance_analytics.check_ins_today')
            ->has('attendance_analytics.recent_records')
        );
});

test('dashboard attendance analytics only includes linked company employees', function () {
    Carbon::setTestNow('2026-06-08 10:00:00', 'Asia/Dubai');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $otherEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'EMP0099',
        'name' => 'Other Employee',
        'status' => 'active',
    ]);

    // Create an AttendanceRecord for the current company employee
    AttendanceRecord::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'date' => '2026-06-08',
        'clock_in' => '2026-06-08 09:00:00',
        'clock_out' => '2026-06-08 17:00:00',
        'hours_worked' => 8.0,
        'status' => AttendanceRecord::STATUS_PRESENT,
        'source' => AttendanceRecord::SOURCE_MANUAL,
    ]);

    // Create an AttendanceRecord for a different company
    $otherFixtures = makeDocumentFixtures();
    $otherCompany = $otherFixtures['company'];
    $otherCompanyEmployee = $otherFixtures['employee'];
    AttendanceRecord::query()->create([
        'company_id' => $otherCompany->id,
        'employee_id' => $otherCompanyEmployee->id,
        'date' => '2026-06-08',
        'clock_in' => '2026-06-08 09:00:00',
        'status' => AttendanceRecord::STATUS_PRESENT,
        'source' => AttendanceRecord::SOURCE_MANUAL,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('attendance_analytics.check_ins_today', 1)
            ->where('attendance_analytics.present_today', 1)
            ->where('attendance_analytics.late_today', 0)
            ->where('attendance_analytics.absent_today', 0)
            ->has('attendance_analytics.recent_records', 1)
            ->where('attendance_analytics.recent_records.0.employee_id', $employee->id)
        );

    Carbon::setTestNow();
});
