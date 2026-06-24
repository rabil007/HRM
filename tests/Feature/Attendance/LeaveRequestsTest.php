<?php

use App\Mail\LeaveRequestSubmittedMail;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\LeaveBalanceManager;
use Database\Seeders\EmailTemplatesSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{user: User, company: Company}
 */
function makeLeaveRequestsFixtures(): array
{
    $user = User::factory()->create();
    $country = Country::query()->create([
        'code' => 'LR'.fake()->unique()->numerify('##'),
        'name' => 'Leave Requestland',
        'dial_code' => '+999',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'LR'.fake()->unique()->numerify('##'),
        'name' => 'Leave Currency',
        'symbol' => 'L$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Leave Co',
        'slug' => 'leave-'.fake()->unique()->numerify('####'),
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

    return ['user' => $user, 'company' => $company];
}

/**
 * @return array{employee: Employee, leaveType: LeaveType}
 */
function makeLeaveRequestActors(Company $company, int $year = 2026): array
{
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $leaveType = LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => 30,
    ]);

    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $employee->id, $year);

    return ['employee' => $employee, 'leaveType' => $leaveType];
}

/**
 * @return array<string, mixed>
 */
function validLeaveRequestPayload(Employee $employee, LeaveType $leaveType, array $overrides = []): array
{
    return array_merge([
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'reason' => 'Family trip',
    ], $overrides);
}

test('guests cannot access leave requests page', function () {
    $this->get('/attendance/leave-requests')->assertRedirect(route('login'));
});

test('authorized users can view create update and delete leave requests', function () {
    ['user' => $user, 'company' => $company] = makeLeaveRequestsFixtures();
    ['employee' => $employee, 'leaveType' => $leaveType] = makeLeaveRequestActors($company);
    $employee->update(['user_id' => $user->id]);
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.create',
        'attendance.leave-requests.update',
        'attendance.leave-requests.delete',
    ]);

    $this->get('/attendance/leave-requests')->assertOk();

    $this->post('/attendance/leave-requests', validLeaveRequestPayload($employee, $leaveType))
        ->assertRedirect(route('attendance.leave-requests.index'));

    $leaveRequest = LeaveRequest::query()->where('employee_id', $employee->id)->first();
    expect($leaveRequest)->not->toBeNull()
        ->and((float) $leaveRequest->total_days)->toBe(3.0)
        ->and($leaveRequest->status)->toBe('pending');

    $this->put("/attendance/leave-requests/{$leaveRequest->id}", validLeaveRequestPayload($employee, $leaveType, [
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-11',
        'reason' => 'Updated reason',
    ]))->assertRedirect(route('attendance.leave-requests.index'));

    expect((float) $leaveRequest->fresh()->total_days)->toBe(2.0)
        ->and($leaveRequest->fresh()->reason)->toBe('Updated reason');

    $this->delete("/attendance/leave-requests/{$leaveRequest->id}")
        ->assertRedirect(route('attendance.leave-requests.index'));

    $this->assertSoftDeleted('leave_requests', ['id' => $leaveRequest->id]);
});

test('leave requests can be approved rejected and cancelled', function () {
    ['user' => $user, 'company' => $company] = makeLeaveRequestsFixtures();
    ['employee' => $employee, 'leaveType' => $leaveType] = makeLeaveRequestActors($company);
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.create',
        'attendance.leave-requests.update',
        'attendance.leave-requests.approve',
    ]);

    $this->post('/attendance/leave-requests', validLeaveRequestPayload($employee, $leaveType));

    $leaveRequest = LeaveRequest::query()->where('employee_id', $employee->id)->firstOrFail();

    $this->put("/attendance/leave-requests/{$leaveRequest->id}/approve")
        ->assertRedirect(route('attendance.leave-requests.index'));

    expect($leaveRequest->fresh()->status)->toBe('approved')
        ->and($leaveRequest->fresh()->approved_by)->toBe($user->id)
        ->and($leaveRequest->fresh()->decided_at)->not->toBeNull();

    $this->post('/attendance/leave-requests', validLeaveRequestPayload($employee, $leaveType, [
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-02',
    ]));

    $rejectable = LeaveRequest::query()->where('status', 'pending')->latest('id')->firstOrFail();

    $this->from('/attendance/leave-requests')
        ->put("/attendance/leave-requests/{$rejectable->id}/reject", [
            'rejection_reason' => 'Insufficient staffing',
        ])
        ->assertRedirect(route('attendance.leave-requests.index'));

    expect($rejectable->fresh()->status)->toBe('rejected')
        ->and($rejectable->fresh()->rejection_reason)->toBe('Insufficient staffing');

    $this->post('/attendance/leave-requests', validLeaveRequestPayload($employee, $leaveType, [
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-02',
    ]));

    $cancellable = LeaveRequest::query()->where('status', 'pending')->latest('id')->firstOrFail();

    $this->from('/attendance/leave-requests')
        ->put("/attendance/leave-requests/{$cancellable->id}/cancel", [
            'cancellation_reason' => 'Plans changed',
        ])
        ->assertRedirect(route('attendance.leave-requests.index'));

    expect($cancellable->fresh()->status)->toBe('cancelled')
        ->and($cancellable->fresh()->cancellation_reason)->toBe('Plans changed');
});

test('reject and cancel require a reason', function () {
    ['user' => $user, 'company' => $company] = makeLeaveRequestsFixtures();
    ['employee' => $employee, 'leaveType' => $leaveType] = makeLeaveRequestActors($company);
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.create',
        'attendance.leave-requests.update',
        'attendance.leave-requests.approve',
    ]);

    $this->post('/attendance/leave-requests', validLeaveRequestPayload($employee, $leaveType));

    $leaveRequest = LeaveRequest::query()->where('employee_id', $employee->id)->firstOrFail();

    $this->from('/attendance/leave-requests')
        ->put("/attendance/leave-requests/{$leaveRequest->id}/reject", [
            'rejection_reason' => '',
        ])
        ->assertSessionHasErrors('rejection_reason');

    $this->from('/attendance/leave-requests')
        ->put("/attendance/leave-requests/{$leaveRequest->id}/cancel", [
            'cancellation_reason' => '   ',
        ])
        ->assertSessionHasErrors('cancellation_reason');
});

test('approved leave requests cannot be updated', function () {
    ['user' => $user, 'company' => $company] = makeLeaveRequestsFixtures();
    ['employee' => $employee, 'leaveType' => $leaveType] = makeLeaveRequestActors($company);
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.update',
        'attendance.leave-requests.approve',
    ]);

    $leaveRequest = LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'total_days' => 3,
        'status' => 'approved',
        'approved_by' => $user->id,
        'decided_at' => now(),
    ]);

    $this->from('/attendance/leave-requests')
        ->put("/attendance/leave-requests/{$leaveRequest->id}", validLeaveRequestPayload($employee, $leaveType))
        ->assertRedirect(route('attendance.leave-requests.index'))
        ->assertSessionHasErrors('leave_request');
});

test('users cannot update leave requests from another company', function () {
    ['user' => $user, 'company' => $company] = makeLeaveRequestsFixtures();
    $otherCompany = Company::query()->create([
        'name' => 'Other Co',
        'slug' => 'other-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $company->country_id,
        'currency_id' => $company->currency_id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    ['employee' => $otherEmployee, 'leaveType' => $otherLeaveType] = makeLeaveRequestActors($otherCompany);
    ['employee' => $employee, 'leaveType' => $leaveType] = makeLeaveRequestActors($company);

    $leaveRequest = LeaveRequest::query()->create([
        'company_id' => $otherCompany->id,
        'employee_id' => $otherEmployee->id,
        'leave_type_id' => $otherLeaveType->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'total_days' => 3,
        'status' => 'pending',
    ]);

    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['attendance.leave-requests.update']);

    $this->put("/attendance/leave-requests/{$leaveRequest->id}", validLeaveRequestPayload($employee, $leaveType))
        ->assertNotFound();
});

test('leave request employee and leave type must belong to current company', function () {
    ['user' => $user, 'company' => $company] = makeLeaveRequestsFixtures();
    $otherCompany = Company::query()->create([
        'name' => 'Foreign Co',
        'slug' => 'foreign-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $company->country_id,
        'currency_id' => $company->currency_id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    ['employee' => $foreignEmployee, 'leaveType' => $foreignLeaveType] = makeLeaveRequestActors($otherCompany);
    ['employee' => $employee, 'leaveType' => $leaveType] = makeLeaveRequestActors($company);

    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['attendance.leave-requests.create']);

    $this->from('/attendance/leave-requests')
        ->post('/attendance/leave-requests', validLeaveRequestPayload($foreignEmployee, $leaveType))
        ->assertSessionHasErrors('employee_id');

    $this->from('/attendance/leave-requests')
        ->post('/attendance/leave-requests', validLeaveRequestPayload($employee, $foreignLeaveType))
        ->assertSessionHasErrors('leave_type_id');
});

test('leave request employee and leave type are required', function () {
    ['user' => $user, 'company' => $company] = makeLeaveRequestsFixtures();
    ['employee' => $employee, 'leaveType' => $leaveType] = makeLeaveRequestActors($company);
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['attendance.leave-requests.create']);

    $this->from('/attendance/leave-requests')
        ->post('/attendance/leave-requests', validLeaveRequestPayload($employee, $leaveType, [
            'employee_id' => '',
            'leave_type_id' => '',
        ]))
        ->assertSessionHasErrors(['employee_id', 'leave_type_id']);
});

test('leave requests can store download and remove optional attachments', function () {
    Storage::fake('local');

    ['user' => $user, 'company' => $company] = makeLeaveRequestsFixtures();
    ['employee' => $employee, 'leaveType' => $leaveType] = makeLeaveRequestActors($company);
    $employee->update(['user_id' => $user->id]);
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.create',
        'attendance.leave-requests.update',
    ]);

    $file = UploadedFile::fake()->create('medical-note.pdf', 20, 'application/pdf');

    $this->post('/attendance/leave-requests', array_merge(
        validLeaveRequestPayload($employee, $leaveType),
        ['attachment' => $file],
    ))->assertRedirect(route('attendance.leave-requests.index'));

    $leaveRequest = LeaveRequest::query()->where('employee_id', $employee->id)->firstOrFail();
    $storedPath = $leaveRequest->attachments[0]['path'] ?? null;

    expect($storedPath)->toBeString()
        ->and($leaveRequest->attachments[0]['name'] ?? null)->toBe('medical-note.pdf')
        ->and(Storage::disk('local')->exists($storedPath))->toBeTrue();

    $this->get(route('attendance.leave-requests.attachment', $leaveRequest))
        ->assertSuccessful()
        ->assertDownload('medical-note.pdf');

    $this->put("/attendance/leave-requests/{$leaveRequest->id}", array_merge(
        validLeaveRequestPayload($employee, $leaveType),
        ['remove_attachment' => true],
    ))->assertRedirect(route('attendance.leave-requests.index'));

    expect($leaveRequest->fresh()->attachments)->toBeNull()
        ->and(Storage::disk('local')->exists($storedPath))->toBeFalse();
});

test('users without approve permission only see their own leave requests', function () {
    ['user' => $user, 'company' => $company] = makeLeaveRequestsFixtures();
    ['employee' => $ownEmployee, 'leaveType' => $leaveType] = makeLeaveRequestActors($company);
    ['employee' => $otherEmployee] = makeLeaveRequestActors($company);

    $ownEmployee->update(['user_id' => $user->id]);

    LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $ownEmployee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'total_days' => 3,
        'status' => 'pending',
    ]);

    LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $otherEmployee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-02',
        'total_days' => 2,
        'status' => 'pending',
    ]);

    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['attendance.leave-requests.view']);

    $this->get('/attendance/leave-requests')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('leave_requests', 1)
            ->where('leave_requests.0.employee.id', $ownEmployee->id)
            ->where('linked_employee_id', $ownEmployee->id));
});

test('users with approve permission see all leave requests', function () {
    ['user' => $user, 'company' => $company] = makeLeaveRequestsFixtures();
    ['employee' => $ownEmployee, 'leaveType' => $leaveType] = makeLeaveRequestActors($company);
    ['employee' => $otherEmployee] = makeLeaveRequestActors($company);

    $ownEmployee->update(['user_id' => $user->id]);

    LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $ownEmployee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'total_days' => 3,
        'status' => 'pending',
    ]);

    LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $otherEmployee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-02',
        'total_days' => 2,
        'status' => 'pending',
    ]);

    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.approve',
    ]);

    $this->get('/attendance/leave-requests')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('leave_requests', 2));
});

test('authorized users can view leave request detail page', function () {
    ['user' => $user, 'company' => $company] = makeLeaveRequestsFixtures();
    ['employee' => $employee, 'leaveType' => $leaveType] = makeLeaveRequestActors($company);
    $employee->update(['user_id' => $user->id]);
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['attendance.leave-requests.view']);

    $leaveRequest = LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'total_days' => 3,
        'status' => 'pending',
    ]);

    $this->get(route('attendance.leave-requests.show', $leaveRequest))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('attendance/leave-request')
            ->where('leave_request.id', $leaveRequest->id)
            ->where('leave_request.employee.id', $employee->id));
});

test('users without approve permission cannot view other employees leave request detail page', function () {
    ['user' => $user, 'company' => $company] = makeLeaveRequestsFixtures();
    ['employee' => $ownEmployee, 'leaveType' => $leaveType] = makeLeaveRequestActors($company);
    ['employee' => $otherEmployee] = makeLeaveRequestActors($company);

    $ownEmployee->update(['user_id' => $user->id]);

    $otherLeaveRequest = LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $otherEmployee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-02',
        'total_days' => 2,
        'status' => 'pending',
    ]);

    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['attendance.leave-requests.view']);

    $this->get(route('attendance.leave-requests.show', $otherLeaveRequest))->assertNotFound();
});

test('users with approve permission can view any leave request detail page', function () {
    ['user' => $user, 'company' => $company] = makeLeaveRequestsFixtures();
    ['employee' => $employee, 'leaveType' => $leaveType] = makeLeaveRequestActors($company);

    $leaveRequest = LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'total_days' => 3,
        'status' => 'pending',
    ]);

    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.approve',
    ]);

    $this->get(route('attendance.leave-requests.show', $leaveRequest))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('leave_request.id', $leaveRequest->id));
});

test('leave request show page hides recent activity without audit permission', function () {
    ['user' => $user, 'company' => $company] = makeLeaveRequestsFixtures();
    ['employee' => $employee, 'leaveType' => $leaveType] = makeLeaveRequestActors($company);
    $employee->update(['user_id' => $user->id]);
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['attendance.leave-requests.view']);

    $leaveRequest = LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'total_days' => 3,
        'status' => 'pending',
    ]);

    $this->get(route('attendance.leave-requests.show', $leaveRequest))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can_view_audit', false)
            ->where('recent_activity', []));
});

test('leave request show page exposes recent activity with audit permission', function () {
    ['user' => $user, 'company' => $company] = makeLeaveRequestsFixtures();
    ['employee' => $employee, 'leaveType' => $leaveType] = makeLeaveRequestActors($company);
    $employee->update(['user_id' => $user->id]);
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
        'audit.view',
    ]);

    $leaveRequest = LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'total_days' => 3,
        'status' => 'pending',
    ]);

    $this->get(route('attendance.leave-requests.show', $leaveRequest))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can_view_audit', true)
            ->has('recent_activity', 1)
            ->where('recent_activity.0.event', 'created'));
});

test('leave request form only exposes linked employee without approve permission', function () {
    ['user' => $user, 'company' => $company] = makeLeaveRequestsFixtures();
    ['employee' => $ownEmployee, 'leaveType' => $leaveType] = makeLeaveRequestActors($company);
    ['employee' => $otherEmployee] = makeLeaveRequestActors($company);

    $ownEmployee->update(['user_id' => $user->id]);
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.create',
    ]);

    $this->get('/attendance/leave-requests')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('linked_employee_id', $ownEmployee->id)
            ->has('employees', 1)
            ->where('employees.0.id', $ownEmployee->id)
            ->where('can.approve', false));
});

test('leave request form exposes all employees with approve permission', function () {
    ['user' => $user, 'company' => $company] = makeLeaveRequestsFixtures();
    makeLeaveRequestActors($company);
    makeLeaveRequestActors($company);
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.approve',
    ]);

    $this->get('/attendance/leave-requests')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.approve', true)
            ->has('employees', 2));
});

test('leave requests cannot overlap pending or approved dates for the same employee', function () {
    ['user' => $user, 'company' => $company] = makeLeaveRequestsFixtures();
    ['employee' => $employee, 'leaveType' => $leaveType] = makeLeaveRequestActors($company);
    $otherLeaveType = LeaveType::factory()->for($company)->create(['status' => 'active']);
    $employee->update(['user_id' => $user->id]);
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.create',
        'attendance.leave-requests.update',
    ]);

    LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-13',
        'end_date' => '2026-06-13',
        'total_days' => 1,
        'status' => 'approved',
        'approved_by' => $user->id,
        'decided_at' => now(),
    ]);

    $response = $this->from('/attendance/leave-requests')
        ->post('/attendance/leave-requests', validLeaveRequestPayload($employee, $otherLeaveType, [
            'start_date' => '2026-06-13',
            'end_date' => '2026-06-13',
        ]));

    $response->assertSessionHasErrors('start_date');

    $pendingRequest = LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $otherLeaveType->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-05',
        'total_days' => 5,
        'status' => 'pending',
    ]);

    $this->from('/attendance/leave-requests')
        ->put("/attendance/leave-requests/{$pendingRequest->id}", validLeaveRequestPayload($employee, $otherLeaveType, [
            'start_date' => '2026-06-12',
            'end_date' => '2026-06-14',
        ]))
        ->assertSessionHasErrors('start_date');
});

test('leave requests may reuse dates when prior requests are rejected or cancelled', function () {
    ['user' => $user, 'company' => $company] = makeLeaveRequestsFixtures();
    ['employee' => $employee, 'leaveType' => $leaveType] = makeLeaveRequestActors($company);
    $employee->update(['user_id' => $user->id]);
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['attendance.leave-requests.create']);

    LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-13',
        'end_date' => '2026-06-13',
        'total_days' => 1,
        'status' => 'cancelled',
        'cancellation_reason' => 'Plans changed',
    ]);

    $this->from('/attendance/leave-requests')
        ->post('/attendance/leave-requests', validLeaveRequestPayload($employee, $leaveType, [
            'start_date' => '2026-06-13',
            'end_date' => '2026-06-13',
        ]))
        ->assertRedirect(route('attendance.leave-requests.index'));

    expect(LeaveRequest::query()->where('employee_id', $employee->id)->where('status', 'pending')->count())->toBe(1);
});

test('users without approve permission cannot manage other employees leave requests', function () {
    ['user' => $user, 'company' => $company] = makeLeaveRequestsFixtures();
    ['employee' => $ownEmployee, 'leaveType' => $leaveType] = makeLeaveRequestActors($company);
    ['employee' => $otherEmployee] = makeLeaveRequestActors($company);

    $ownEmployee->update(['user_id' => $user->id]);

    $otherLeaveRequest = LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $otherEmployee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-02',
        'total_days' => 2,
        'status' => 'pending',
    ]);

    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.create',
        'attendance.leave-requests.update',
    ]);

    $this->from('/attendance/leave-requests')
        ->post('/attendance/leave-requests', validLeaveRequestPayload($otherEmployee, $leaveType))
        ->assertSessionHasErrors('employee_id');

    $this->put("/attendance/leave-requests/{$otherLeaveRequest->id}", validLeaveRequestPayload($ownEmployee, $leaveType))
        ->assertNotFound();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function configureLeaveRequestSubmittedTemplate(array $overrides = []): void
{
    EmailTemplatesSeeder::seedLeaveRequestSubmittedTemplate();

    if ($overrides !== []) {
        EmailTemplate::query()
            ->where('slug', 'leave_request_submitted')
            ->update($overrides);
    }
}

test('leave request creation queues submitted email when template is enabled', function () {
    Mail::fake();

    ['user' => $user, 'company' => $company] = makeLeaveRequestsFixtures();
    ['employee' => $employee, 'leaveType' => $leaveType] = makeLeaveRequestActors($company);
    $employee->update(['user_id' => $user->id, 'name' => 'Alice Crew']);

    configureLeaveRequestSubmittedTemplate([
        'to_preset' => 'hr@example.com',
        'enabled' => true,
    ]);

    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.create',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post('/attendance/leave-requests', validLeaveRequestPayload($employee, $leaveType))
        ->assertRedirect(route('attendance.leave-requests.index'));

    Mail::assertQueued(LeaveRequestSubmittedMail::class, function (LeaveRequestSubmittedMail $mail) use ($leaveType) {
        return $mail->hasTo('hr@example.com')
            && str_contains($mail->subjectLine, 'Alice Crew')
            && str_contains($mail->subjectLine, $leaveType->name)
            && $mail->employeeName === 'Alice Crew'
            && $mail->leaveType === $leaveType->name;
    });
});

test('leave request submitted email includes department manager address', function () {
    Mail::fake();

    ['user' => $user, 'company' => $company] = makeLeaveRequestsFixtures();
    ['employee' => $employee, 'leaveType' => $leaveType] = makeLeaveRequestActors($company);

    $manager = Employee::factory()->forCompany($company)->create([
        'work_email' => 'manager@example.com',
        'name' => 'Line Manager',
    ]);

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Engineering',
        'code' => 'ENG',
        'manager_id' => $manager->id,
        'status' => 'active',
    ]);

    $employee->update([
        'department_id' => $department->id,
        'user_id' => $user->id,
    ]);

    configureLeaveRequestSubmittedTemplate([
        'to_preset' => 'hr@example.com',
        'enabled' => true,
    ]);

    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.create',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post('/attendance/leave-requests', validLeaveRequestPayload($employee, $leaveType))
        ->assertRedirect(route('attendance.leave-requests.index'));

    Mail::assertQueued(LeaveRequestSubmittedMail::class, function (LeaveRequestSubmittedMail $mail) {
        return $mail->hasTo('hr@example.com')
            && $mail->hasCc('manager@example.com');
    });
});

test('leave request submitted email is not queued when template is disabled', function () {
    Mail::fake();

    ['user' => $user, 'company' => $company] = makeLeaveRequestsFixtures();
    ['employee' => $employee, 'leaveType' => $leaveType] = makeLeaveRequestActors($company);
    $employee->update(['user_id' => $user->id]);

    configureLeaveRequestSubmittedTemplate([
        'to_preset' => 'hr@example.com',
        'enabled' => false,
    ]);

    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.create',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post('/attendance/leave-requests', validLeaveRequestPayload($employee, $leaveType))
        ->assertRedirect(route('attendance.leave-requests.index'));

    Mail::assertNothingQueued();
});

test('leave request submitted email is not queued when no recipients are available', function () {
    Mail::fake();

    ['user' => $user, 'company' => $company] = makeLeaveRequestsFixtures();
    ['employee' => $employee, 'leaveType' => $leaveType] = makeLeaveRequestActors($company);
    $employee->update([
        'user_id' => $user->id,
        'department_id' => null,
        'work_email' => null,
        'personal_email' => null,
    ]);

    configureLeaveRequestSubmittedTemplate([
        'to_preset' => null,
        'cc_preset' => null,
        'enabled' => true,
    ]);

    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.create',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post('/attendance/leave-requests', validLeaveRequestPayload($employee, $leaveType))
        ->assertRedirect(route('attendance.leave-requests.index'));

    Mail::assertNothingQueued();
});
