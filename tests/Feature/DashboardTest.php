<?php

use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard and receive personal summary and can flags', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->has('personal_summary')
            ->where('personal_summary.user_name', $user->name)
            ->has('can')
            ->where('can.employees_create', false)
            ->where('can.employees_export', false)
            ->where('can.documents_upload', false)
            ->where('can.view_audit', false)
            ->missing('employee_analytics')
            ->missing('document_compliance')
            ->missing('attendance_analytics')
            ->missing('crew_summary')
            ->missing('payroll_summary')
            ->missing('announcements_summary')
        );
});

test('dashboard returns employee analytics and document compliance props when permitted', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, [
        'employees.view',
        'documents.view',
        'employees.create',
    ]);

    Employee::factory()->count(3)->create([
        'company_id' => $company->id,
        'status' => 'active',
    ]);

    Employee::factory()->create([
        'company_id' => $company->id,
        'status' => 'on_leave',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->where('can.employees_create', true)
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
            ->missing('workforce_trends')
            ->missing('employees_by_department')
            ->missing('employees_by_branch')
            ->missing('recent_hires')
            ->has('document_health')
            ->has('organization_snapshot')
            ->has('attendance_analytics')
            ->has('attendance_analytics.check_ins_today')
            ->has('attendance_analytics.recent_records')
            ->loadDeferredProps('secondary', fn ($deferred) => $deferred
                ->has('workforce_trends')
                ->has('employees_by_department')
                ->has('employees_by_branch')
                ->has('recent_hires')
            )
        );
});

test('dashboard returns crew summary prop when user has crew overview permission', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['crew_operations.overview.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->has('crew_summary')
            ->has('crew_summary.on_vessel')
            ->has('crew_summary.in_home')
            ->has('crew_summary.needs_update')
            ->has('crew_summary.total')
            ->missing('employee_analytics')
            ->missing('payroll_summary')
        );
});

test('dashboard returns payroll summary prop when user has payroll overview permission', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['payroll.overview.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->has('payroll_summary')
            ->has('payroll_summary.draft_periods')
            ->has('payroll_summary.processing_periods')
            ->missing('employee_analytics')
            ->missing('crew_summary')
        );
});

test('dashboard returns announcements summary prop when user has announcements view permission', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['announcements.view']);

    Announcement::query()->create([
        'company_id' => $company->id,
        'title' => 'Company All Hands',
        'body_html' => '<p>Monthly meeting</p>',
        'category' => AnnouncementCategory::General,
        'channels' => ['in_app'],
        'status' => AnnouncementStatus::Published,
        'published_at' => now(),
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->has('announcements_summary')
            ->where('announcements_summary.total', 1)
            ->has('announcements_summary.recent', 1)
            ->where('announcements_summary.recent.0.title', 'Company All Hands')
            ->missing('employee_analytics')
        );
});

test('dashboard attendance analytics only includes linked company employees', function () {
    Carbon::setTestNow('2026-06-08 10:00:00', 'Asia/Dubai');

    $user = User::factory()->create();
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

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
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
