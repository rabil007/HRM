<?php

use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\Position;
use App\Models\User;
use Carbon\Carbon;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard and receive personal summary and can flags without dashboard.view permission', function () {
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
            ->has('personal_dashboard')
            ->has('attention_items')
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

test('employees.view permission does not expose attendance or document data', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->has('employee_analytics')
            ->has('organization_snapshot')
            ->missing('document_compliance')
            ->missing('document_health')
            ->missing('attendance_analytics')
        );
});

test('documents.view permission does not expose workforce or attendance data', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->has('document_compliance')
            ->has('document_health')
            ->missing('employee_analytics')
            ->missing('organization_snapshot')
            ->missing('attendance_analytics')
        );
});

test('attendance.overview.view permission does not expose workforce or document data', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['attendance.overview.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->has('attendance_analytics')
            ->missing('employee_analytics')
            ->missing('organization_snapshot')
            ->missing('document_compliance')
            ->missing('document_health')
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

    grantCompanyPermissions($user, $company, ['attendance.overview.view']);

    Employee::factory()->forCompany($company)->create([
        'employee_no' => 'EMP0099',
        'name' => 'Other Employee',
        'status' => 'active',
    ]);

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

test('new hires use hire_date rather than created_at', function () {
    Carbon::setTestNow('2026-06-15 12:00:00', 'Asia/Dubai');
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $fixtureEmployee] = makeDocumentFixtures();
    $fixtureEmployee->update(['hire_date' => now()->subYears(2)]);
    grantCompanyPermissions($user, $company, ['employees.view']);

    Employee::factory()->forCompany($company)->create([
        'created_at' => now(),
        'hire_date' => now()->subYear(),
        'status' => 'active',
    ]);

    Employee::factory()->forCompany($company)->create([
        'created_at' => now()->subYear(),
        'hire_date' => now()->startOfMonth()->addDay(),
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('employee_analytics.new_hires_this_month', 1)
        );

    Carbon::setTestNow();
});

test('distinct attendance counts for present and check-in metrics', function () {
    Carbon::setTestNow('2026-06-15 12:00:00', 'Asia/Dubai');
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['attendance.overview.view']);

    $employee2 = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
    ]);

    AttendanceRecord::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'date' => '2026-06-15',
        'clock_in' => '2026-06-15 08:00:00',
        'clock_out' => '2026-06-15 12:00:00',
        'status' => AttendanceRecord::STATUS_PRESENT,
        'source' => AttendanceRecord::SOURCE_MANUAL,
    ]);

    AttendanceRecord::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee2->id,
        'date' => '2026-06-15',
        'clock_in' => '2026-06-15 08:30:00',
        'clock_out' => '2026-06-15 17:00:00',
        'status' => AttendanceRecord::STATUS_PRESENT,
        'source' => AttendanceRecord::SOURCE_MANUAL,
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('attendance_analytics.present_today', 2)
            ->where('attendance_analytics.check_ins_today', 2)
            ->where('attendance_analytics.events_today', 2)
        );

    Carbon::setTestNow();
});

test('personal dashboard returns linked employee info and isolates cross user or cross company data', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, []);

    $position = Position::query()->create([
        'company_id' => $company->id,
        'title' => 'Senior Developer',
        'status' => 'active',
    ]);
    $employee->update(['user_id' => $user->id, 'position_id' => $position->id]);

    $otherCompanyFixtures = makeDocumentFixtures();
    $otherCompany = $otherCompanyFixtures['company'];

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('personal_dashboard.has_linked_employee', true)
            ->where('personal_dashboard.is_active_workforce', true)
            ->where('personal_dashboard.employee.id', $employee->id)
            ->where('personal_dashboard.employee.position', 'Senior Developer')
        );

    grantCompanyPermissions($user, $otherCompany, []);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $otherCompany->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('personal_dashboard.has_linked_employee', false)
            ->where('personal_dashboard.employee', null)
        );
});

test('dashboard composes every module section and attention item links for a fully permitted user', function () {
    $user = User::factory()->create();
    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $employee->update(['user_id' => $user->id]);

    grantCompanyPermissions($user, $company, [
        'employees.view',
        'documents.view',
        'attendance.overview.view',
        'attendance.leave-requests.view',
        'attendance.leave-requests.approve',
        'contracts.view',
        'training.view',
        'bank_accounts.view',
        'crew_operations.overview.view',
        'payroll.overview.view',
        'announcements.view',
        'audit.view',
    ]);

    foreach (['expired' => now()->subDay(), 'expiring' => now()->addDays(3)] as $key => $expiry) {
        EmployeeDocument::query()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'document_type_id' => $passportType->id,
            'type' => 'other',
            'document_type' => (string) $passportType->id,
            'file_path' => "docs/{$key}.pdf",
            'original_filename' => "{$key}.pdf",
            'mime_type' => 'application/pdf',
            'status' => 'valid',
            'expiry_date' => $expiry->toDateString(),
        ]);
    }

    $paidPeriod = PayrollPeriod::factory()->paid()->create([
        'company_id' => $company->id,
        'name' => 'Office - May 2026',
    ]);

    PayrollRecord::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $paidPeriod->id,
        'net_salary' => 4200,
    ]);

    PayrollPeriod::factory()->create([
        'company_id' => $company->id,
        'status' => 'processing',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->has('attention_items')
            ->where('payroll_summary.last_paid_period_name', 'Office - May 2026')
            ->where('payroll_summary.last_paid_period_total', 4200)
            ->where('personal_dashboard.my_payslips.0.period_name', 'Office - May 2026')
            ->etc()
        );
});
