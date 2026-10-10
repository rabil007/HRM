<?php

use App\Enums\Recruitment\CandidateOfferStatus;
use App\Enums\Recruitment\CandidateStage;
use App\Enums\Recruitment\CandidateTransitionAction;
use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentCandidateOffer;
use App\Models\RecruitmentCandidateStageTransition;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementLine;
use App\Models\Role;
use App\Models\User;
use App\Support\Recruitment\Candidates\CandidatePresenter;
use App\Support\Recruitment\Candidates\FindCandidateDuplicateEmployees;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    config(['app.timezone' => 'UTC']);
    date_default_timezone_set('UTC');

    $this->country = Country::query()->firstOrCreate(
        ['code' => 'AE'],
        ['name' => 'United Arab Emirates', 'dial_code' => '+971', 'is_active' => true],
    );

    $this->currency = Currency::query()->firstOrCreate(
        ['code' => 'AED'],
        ['name' => 'Dirham', 'symbol' => 'د.إ', 'is_active' => true],
    );

    $this->company = Company::query()->create([
        'name' => 'Gulf Energy Corp',
        'slug' => 'gulf-energy-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $this->country->id,
        'currency_id' => $this->currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $this->department = Department::query()->create([
        'company_id' => $this->company->id,
        'name' => 'Engineering',
        'status' => 'active',
    ]);

    $this->position = Position::query()->create([
        'company_id' => $this->company->id,
        'title' => 'Software Engineer',
        'status' => 'active',
    ]);

    $this->client = Client::query()->create([
        'name' => 'Client A',
        'is_active' => true,
    ]);

    $this->requirement = RecruitmentRequirement::query()->create([
        'company_id' => $this->company->id,
        'requirement_number' => 'REQ-TEST-'.uniqid(),
        'client_id' => $this->client->id,
        'status' => RequirementStatus::Open,
        'lock_version' => 1,
    ]);

    $this->line = RecruitmentRequirementLine::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'position_id' => $this->position->id,
        'required_headcount' => 2,
        'status' => RequirementLineStatus::Open,
        'lock_version' => 1,
    ]);
});

function createConversionUser(Company $company, array $permissions): User
{
    $user = User::factory()->create(['company_id' => $company->id]);
    DB::table('company_user')->updateOrInsert(
        ['company_id' => $company->id, 'user_id' => $user->id],
        ['status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    );

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

    $role = Role::query()->create([
        'company_id' => $company->id,
        'name' => 'Role-'.uniqid(),
        'guard_name' => 'web',
        'employee_visibility_scope' => Role::SCOPE_ALL,
    ]);

    foreach ($permissions as $perm) {
        $p = Permission::query()->firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        $role->givePermissionTo($p);
    }

    $user->assignRole($role);

    return $user;
}

function createConversionCandidate(array $attributes): RecruitmentCandidate
{
    return RecruitmentCandidate::query()->create(array_merge([
        'position_title_snapshot' => 'Software Engineer',
        'requirement_number_snapshot' => 'REQ-TEST-1',
        'lock_version' => 1,
    ], $attributes));
}

test('candidate conversion requires proper permissions', function () {
    $userWithoutConvert = createConversionUser($this->company, [
        'employees.create',
        'employees.view',
        'recruitment.candidates.view',
    ]);

    $candidate = createConversionCandidate([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'position_id' => $this->position->id,
        'name' => 'John Doe',
        'stage' => CandidateStage::Joined,
        'actual_joining_date' => '2026-10-01',
    ]);

    $payload = [
        'candidate_id' => $candidate->id,
        'candidate_lock_version' => 1,
        'employee_no' => 'EMP-001',
        'name' => 'John Doe',
        'department_id' => $this->department->id,
        'position_id' => $this->position->id,
        'start_date' => '2026-10-01',
        'status' => 'active',
    ];

    $this->actingAs($userWithoutConvert)
        ->post(route('organization.employees.store'), $payload)
        ->assertSessionHasErrors(['candidate_id']);
});

test('joined candidate converts to employee and links candidate record atomically', function () {
    $user = createConversionUser($this->company, [
        'employees.create',
        'employees.view',
        'recruitment.candidates.view',
        'recruitment.candidates.convert',
    ]);

    $candidate = createConversionCandidate([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'position_id' => $this->position->id,
        'name' => 'Alice Smith',
        'email' => 'alice@example.com',
        'stage' => CandidateStage::Joined,
        'actual_joining_date' => '2026-10-05',
    ]);

    $payload = [
        'candidate_id' => $candidate->id,
        'candidate_lock_version' => 1,
        'employee_no' => 'EMP-002',
        'name' => 'Alice Smith',
        'department_id' => $this->department->id,
        'position_id' => $this->position->id,
        'start_date' => '2026-10-05',
        'status' => 'active',
    ];

    $response = $this->actingAs($user)
        ->post(route('organization.employees.store'), $payload);

    $response->assertRedirect(route('organization.recruitment.candidates.show', $candidate->id));
    $response->assertSessionHas('success');

    $candidate->refresh();
    expect($candidate->employee_id)->not->toBeNull();

    $employee = Employee::query()->find($candidate->employee_id);
    expect($employee)->not->toBeNull()
        ->and($employee->name)->toBe('Alice Smith')
        ->and($employee->company_id)->toBe($this->company->id);

    // Verify stage transition event
    $transition = RecruitmentCandidateStageTransition::query()
        ->where('recruitment_candidate_id', $candidate->id)
        ->where('action', CandidateTransitionAction::EmployeeConverted)
        ->first();

    expect($transition)->not->toBeNull()
        ->and($transition->from_stage)->toBe(CandidateStage::Joined)
        ->and($transition->to_stage)->toBe(CandidateStage::Joined);
});

test('candidate cannot be converted if not in joined stage', function () {
    $user = createConversionUser($this->company, [
        'employees.create',
        'employees.view',
        'recruitment.candidates.view',
        'recruitment.candidates.convert',
    ]);

    $candidate = createConversionCandidate([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'position_id' => $this->position->id,
        'name' => 'Bob Builder',
        'stage' => CandidateStage::Interview,
    ]);

    $payload = [
        'candidate_id' => $candidate->id,
        'candidate_lock_version' => 1,
        'employee_no' => 'EMP-003',
        'name' => 'Bob Builder',
        'department_id' => $this->department->id,
        'position_id' => $this->position->id,
        'start_date' => '2026-10-05',
        'status' => 'active',
    ];

    $this->actingAs($user)
        ->post(route('organization.employees.store'), $payload)
        ->assertSessionHasErrors(['candidate_id']);
});

test('candidate already converted cannot be converted again', function () {
    $user = createConversionUser($this->company, [
        'employees.create',
        'employees.view',
        'recruitment.candidates.view',
        'recruitment.candidates.convert',
    ]);

    $employee = Employee::factory()->create(['company_id' => $this->company->id]);

    $candidate = createConversionCandidate([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'position_id' => $this->position->id,
        'name' => 'Charlie Day',
        'stage' => CandidateStage::Joined,
        'employee_id' => $employee->id,
    ]);

    $payload = [
        'candidate_id' => $candidate->id,
        'candidate_lock_version' => 1,
        'employee_no' => 'EMP-004',
        'name' => 'Charlie Day',
        'department_id' => $this->department->id,
        'position_id' => $this->position->id,
        'start_date' => '2026-10-05',
        'status' => 'active',
    ];

    $this->actingAs($user)
        ->post(route('organization.employees.store'), $payload)
        ->assertSessionHasErrors(['candidate_id']);
});

test('linking candidate to existing employee requires confirmation and reason', function () {
    $user = createConversionUser($this->company, [
        'recruitment.candidates.view',
        'recruitment.candidates.convert',
        'employees.view',
    ]);

    $employee = Employee::factory()->create(['company_id' => $this->company->id]);

    $candidate = createConversionCandidate([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'position_id' => $this->position->id,
        'name' => 'Dave Miller',
        'stage' => CandidateStage::Joined,
    ]);

    // Missing confirmation
    $this->actingAs($user)
        ->post(route('organization.recruitment.candidates.link-employee', $candidate), [
            'employee_id' => $employee->id,
            'confirmed' => false,
            'reason' => 'Existing company employee returning',
            'lock_version' => 1,
        ])
        ->assertSessionHasErrors(['confirmed']);

    // Missing reason
    $this->actingAs($user)
        ->post(route('organization.recruitment.candidates.link-employee', $candidate), [
            'employee_id' => $employee->id,
            'confirmed' => true,
            'reason' => '',
            'lock_version' => 1,
        ])
        ->assertSessionHasErrors(['reason']);
});

test('linking candidate to existing employee succeeds and records stage transition', function () {
    $user = createConversionUser($this->company, [
        'recruitment.candidates.view',
        'recruitment.candidates.convert',
        'employees.view',
    ]);

    $employee = Employee::factory()->create(['company_id' => $this->company->id]);

    $candidate = createConversionCandidate([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'position_id' => $this->position->id,
        'name' => 'Eve Adams',
        'stage' => CandidateStage::Joined,
    ]);

    $response = $this->actingAs($user)
        ->post(route('organization.recruitment.candidates.link-employee', $candidate), [
            'employee_id' => $employee->id,
            'confirmed' => true,
            'reason' => 'Re-hired former staff member',
            'lock_version' => 1,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $candidate->refresh();
    expect($candidate->employee_id)->toBe($employee->id);

    $transition = RecruitmentCandidateStageTransition::query()
        ->where('recruitment_candidate_id', $candidate->id)
        ->where('action', CandidateTransitionAction::EmployeeLinked)
        ->first();

    expect($transition)->not->toBeNull()
        ->and($transition->from_stage)->toBe(CandidateStage::Joined)
        ->and($transition->to_stage)->toBe(CandidateStage::Joined);
});

test('linking candidate rejects cross-company employee', function () {
    $user = createConversionUser($this->company, [
        'recruitment.candidates.view',
        'recruitment.candidates.convert',
        'employees.view',
    ]);

    $otherCompany = Company::query()->create([
        'name' => 'Other Corp',
        'slug' => 'other-corp-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $this->country->id,
        'currency_id' => $this->currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $foreignEmployee = Employee::factory()->create(['company_id' => $otherCompany->id]);

    $candidate = createConversionCandidate([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'position_id' => $this->position->id,
        'name' => 'Frank Castle',
        'stage' => CandidateStage::Joined,
    ]);

    $this->actingAs($user)
        ->post(route('organization.recruitment.candidates.link-employee', $candidate), [
            'employee_id' => $foreignEmployee->id,
            'confirmed' => true,
            'reason' => 'Cross-company test',
            'lock_version' => 1,
        ])
        ->assertSessionHasErrors(['employee_id']);
});

test('duplicate employee suggestions and linked employee details are masked without employees.view permission', function () {
    $userWithoutView = createConversionUser($this->company, [
        'recruitment.candidates.view',
        'recruitment.candidates.convert',
        'employees.create',
    ]);

    $userWithView = createConversionUser($this->company, [
        'recruitment.candidates.view',
        'recruitment.candidates.convert',
        'employees.create',
        'employees.view',
    ]);

    $existingEmployee = Employee::factory()->create([
        'company_id' => $this->company->id,
        'department_id' => $this->department->id,
        'personal_email' => 'duplicate.match@example.com',
        'phone' => '+971501234567',
    ]);

    $candidate = createConversionCandidate([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'position_id' => $this->position->id,
        'name' => 'Match Candidate',
        'email' => 'duplicate.match@example.com',
        'phone' => '+971501234567',
        'stage' => CandidateStage::Joined,
        'actual_joining_date' => '2026-10-01',
        'employee_id' => $existingEmployee->id,
    ]);

    // 1. Duplicate suggestions
    expect(FindCandidateDuplicateEmployees::find($candidate, $userWithoutView, $this->company->id))->toBeEmpty();
    expect(FindCandidateDuplicateEmployees::find($candidate, $userWithView, $this->company->id))->not->toBeEmpty();

    // 2. Candidate index row masking
    $indexRowWithoutView = CandidatePresenter::toIndexRow($candidate, $userWithoutView, 'Asia/Dubai');
    expect($indexRowWithoutView['employee_id'])->toBeNull()
        ->and($indexRowWithoutView['conversion_status'])->toBe('converted');

    $indexRowWithView = CandidatePresenter::toIndexRow($candidate, $userWithView, 'Asia/Dubai');
    expect($indexRowWithView['employee_id'])->toBe($existingEmployee->id)
        ->and($indexRowWithView['conversion_status'])->toBe('converted');

    // 3. Candidate detail show array masking
    $showWithoutView = CandidatePresenter::toShowArray($candidate, $userWithoutView, 'Asia/Dubai');
    expect($showWithoutView['linked_employee'])->toBe([
        'id' => null,
        'name' => null,
        'employee_no' => null,
        'can_view' => false,
    ]);

    $showWithView = CandidatePresenter::toShowArray($candidate, $userWithView, 'Asia/Dubai');
    expect($showWithView['linked_employee'])->toBe([
        'id' => (int) $existingEmployee->id,
        'name' => (string) $existingEmployee->name,
        'employee_no' => (string) $existingEmployee->employee_no,
        'can_view' => true,
    ]);
});

test('duplicate employee suggestions and linked employee details are masked when employee is in a restricted department', function () {
    $otherDepartment = Department::query()->create([
        'company_id' => $this->company->id,
        'name' => 'Finance',
        'status' => 'active',
    ]);

    // Create user with department-restricted role (only Engineering)
    $restrictedUser = User::factory()->create(['company_id' => $this->company->id]);
    DB::table('company_user')->updateOrInsert(
        ['company_id' => $this->company->id, 'user_id' => $restrictedUser->id],
        ['status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    );
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);

    $role = Role::query()->create([
        'company_id' => $this->company->id,
        'name' => 'RestrictedRole-'.uniqid(),
        'guard_name' => 'web',
        'employee_visibility_scope' => Role::SCOPE_SELECTED_DEPARTMENTS,
    ]);
    $role->employeeVisibilityDepartments()->attach($this->department->id, ['company_id' => $this->company->id]);

    foreach (['employees.view', 'recruitment.candidates.view', 'recruitment.candidates.convert'] as $perm) {
        $p = Permission::query()->firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        $role->givePermissionTo($p);
    }
    $restrictedUser->assignRole($role);

    $financeEmployee = Employee::factory()->create([
        'company_id' => $this->company->id,
        'department_id' => $otherDepartment->id,
        'personal_email' => 'finance.staff@example.com',
    ]);

    $candidate = createConversionCandidate([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'position_id' => $this->position->id,
        'name' => 'Finance Staff',
        'email' => 'finance.staff@example.com',
        'stage' => CandidateStage::Joined,
        'actual_joining_date' => '2026-10-01',
        'employee_id' => $financeEmployee->id,
    ]);

    expect(FindCandidateDuplicateEmployees::find($candidate, $restrictedUser, $this->company->id))->toBeEmpty();

    $show = CandidatePresenter::toShowArray($candidate, $restrictedUser, 'Asia/Dubai');
    expect($show['linked_employee'])->toBe([
        'id' => null,
        'name' => null,
        'employee_no' => null,
        'can_view' => false,
    ]);
});

test('duplicate employee suggestions do not include cross-company employees', function () {
    $user = createConversionUser($this->company, [
        'employees.view',
        'recruitment.candidates.view',
    ]);

    $otherCompany = Company::query()->create([
        'name' => 'Other Corp 2',
        'slug' => 'other-corp-2-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $this->country->id,
        'currency_id' => $this->currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    Employee::factory()->create([
        'company_id' => $otherCompany->id,
        'personal_email' => 'cross.company@example.com',
    ]);

    $candidate = createConversionCandidate([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'position_id' => $this->position->id,
        'name' => 'Cross Company Candidate',
        'email' => 'cross.company@example.com',
        'stage' => CandidateStage::Joined,
    ]);

    expect(FindCandidateDuplicateEmployees::find($candidate, $user, $this->company->id))->toBeEmpty();
});

test('opening employee create form with candidate_id preserves context and creates no employee', function () {
    $user = createConversionUser($this->company, [
        'employees.create',
        'employees.view',
        'recruitment.candidates.view',
        'recruitment.candidates.convert',
    ]);

    $candidate = createConversionCandidate([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'position_id' => $this->position->id,
        'name' => 'Context Candidate',
        'email' => 'context@example.com',
        'stage' => CandidateStage::Joined,
        'actual_joining_date' => '2026-10-01',
    ]);

    $initialEmployeeCount = Employee::count();

    $response = $this->actingAs($user)
        ->get(route('organization.employees.create', ['candidate_id' => $candidate->id]));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employee')
            ->where('candidate_context.candidate_id', $candidate->id)
            ->where('candidate_context.lock_version', $candidate->lock_version)
            ->where('candidate_context.name', 'Context Candidate')
            ->where('candidate_context.actual_joining_date', '2026-10-01')
        );

    // Visiting form must not create an employee
    expect(Employee::count())->toBe($initialEmployeeCount);
});

test('conversion requires candidate_lock_version and rejects stale lock versions', function () {
    $user = createConversionUser($this->company, [
        'employees.create',
        'employees.view',
        'recruitment.candidates.view',
        'recruitment.candidates.convert',
    ]);

    $candidate = createConversionCandidate([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'position_id' => $this->position->id,
        'name' => 'Lock Candidate',
        'stage' => CandidateStage::Joined,
        'lock_version' => 2,
    ]);

    // 1. Missing lock version
    $this->actingAs($user)
        ->post(route('organization.employees.store'), [
            'candidate_id' => $candidate->id,
            'employee_no' => 'EMP-LOCK-1',
            'name' => 'Lock Candidate',
            'department_id' => $this->department->id,
            'position_id' => $this->position->id,
            'start_date' => '2026-10-01',
            'status' => 'active',
        ])
        ->assertSessionHasErrors(['candidate_lock_version']);

    // 2. Stale lock version (sent version 1, candidate is version 2)
    $this->actingAs($user)
        ->post(route('organization.employees.store'), [
            'candidate_id' => $candidate->id,
            'candidate_lock_version' => 1,
            'employee_no' => 'EMP-LOCK-1',
            'name' => 'Lock Candidate',
            'department_id' => $this->department->id,
            'position_id' => $this->position->id,
            'start_date' => '2026-10-01',
            'status' => 'active',
        ])
        ->assertSessionHasErrors(['candidate_lock_version']);
});

test('validation errors during conversion retain candidate context and create no employee', function () {
    $user = createConversionUser($this->company, [
        'employees.create',
        'employees.view',
        'recruitment.candidates.view',
        'recruitment.candidates.convert',
    ]);

    $candidate = createConversionCandidate([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'position_id' => $this->position->id,
        'name' => 'Validation Candidate',
        'stage' => CandidateStage::Joined,
        'lock_version' => 1,
    ]);

    $initialCount = Employee::count();

    // Missing employee_no
    $response = $this->actingAs($user)
        ->post(route('organization.employees.store'), [
            'candidate_id' => $candidate->id,
            'candidate_lock_version' => 1,
            'name' => 'Validation Candidate',
            'department_id' => $this->department->id,
            'position_id' => $this->position->id,
            'start_date' => '2026-10-01',
            'status' => 'active',
        ]);

    $response->assertSessionHasErrors(['employee_no']);
    expect(Employee::count())->toBe($initialCount);

    $candidate->refresh();
    expect($candidate->employee_id)->toBeNull();
});

test('template changes followed by saving creates exactly one employee and links atomically', function () {
    $user = createConversionUser($this->company, [
        'employees.create',
        'employees.view',
        'recruitment.candidates.view',
        'recruitment.candidates.convert',
    ]);

    $candidate = createConversionCandidate([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'position_id' => $this->position->id,
        'name' => 'Template Candidate',
        'stage' => CandidateStage::Joined,
        'actual_joining_date' => '2026-10-01',
        'lock_version' => 1,
    ]);

    $initialCount = Employee::count();

    // 1. Visit create form with candidate_id
    $this->actingAs($user)
        ->get(route('organization.employees.create', ['candidate_id' => $candidate->id]))
        ->assertOk();

    // No employee created
    expect(Employee::count())->toBe($initialCount);

    // 2. Simulate template change by visiting create form with template_id
    $this->actingAs($user)
        ->get(route('organization.employees.create', ['candidate_id' => $candidate->id, 'template_id' => 999]))
        ->assertOk();

    // Still no employee created
    expect(Employee::count())->toBe($initialCount);

    // 3. Submit store conversion request
    $response = $this->actingAs($user)
        ->post(route('organization.employees.store'), [
            'candidate_id' => $candidate->id,
            'candidate_lock_version' => 1,
            'employee_no' => 'EMP-TEMPL-1',
            'name' => 'Template Candidate',
            'department_id' => $this->department->id,
            'position_id' => $this->position->id,
            'start_date' => '2026-10-01',
            'status' => 'active',
        ]);

    $response->assertRedirect(route('organization.recruitment.candidates.show', $candidate->id));

    // Exactly one employee created
    expect(Employee::count())->toBe($initialCount + 1);

    $candidate->refresh();
    expect($candidate->employee_id)->not->toBeNull();
    $employee = Employee::find($candidate->employee_id);
    expect($employee)->not->toBeNull()
        ->and($employee->employee_no)->toBe('EMP-TEMPL-1');
});

test('accepted non-AED offer displays proposed total salary and currency without invented breakdown or AED fallback', function () {
    $user = createConversionUser($this->company, [
        'employees.create',
        'employees.view',
        'recruitment.candidates.view',
        'recruitment.candidates.convert',
    ]);

    $candidate = createConversionCandidate([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'position_id' => $this->position->id,
        'name' => 'USD Candidate',
        'stage' => CandidateStage::Joined,
        'actual_joining_date' => '2026-10-01',
        'lock_version' => 1,
    ]);

    RecruitmentCandidateOffer::query()->create([
        'company_id' => $this->company->id,
        'recruitment_candidate_id' => $candidate->id,
        'revision_number' => 1,
        'is_current' => true,
        'status' => CandidateOfferStatus::Accepted,
        'salary_amount' => 7500.00,
        'salary_currency_code' => 'USD',
        'proposed_joining_date' => '2026-10-01',
        'offer_date' => '2026-10-01',
        'lock_version' => 1,
    ]);

    $response = $this->actingAs($user)
        ->get(route('organization.employees.create', ['candidate_id' => $candidate->id]));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employee')
            ->where('candidate_context.proposed_offer.salary_amount', '7500.00')
            ->where('candidate_context.proposed_offer.currency', 'USD')
        );
});

test('candidate conversion create page rejects combination with employee_id query parameter', function () {
    $user = createConversionUser($this->company, [
        'employees.create',
        'employees.view',
        'recruitment.candidates.view',
        'recruitment.candidates.convert',
    ]);

    $candidate = createConversionCandidate([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'position_id' => $this->position->id,
        'name' => 'Combined Draft Candidate',
        'stage' => CandidateStage::Joined,
        'actual_joining_date' => '2026-10-01',
        'lock_version' => 1,
    ]);

    $this->actingAs($user)
        ->get(route('organization.employees.create', [
            'candidate_id' => $candidate->id,
            'employee_id' => 999,
        ]))
        ->assertStatus(422);
});

test('ensure endpoint prohibits candidate_id and rejects provisional draft creation in conversion mode', function () {
    $user = createConversionUser($this->company, [
        'employees.create',
        'employees.view',
        'recruitment.candidates.view',
        'recruitment.candidates.convert',
    ]);

    $candidate = createConversionCandidate([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'position_id' => $this->position->id,
        'name' => 'Ensure Candidate',
        'stage' => CandidateStage::Joined,
        'actual_joining_date' => '2026-10-01',
        'lock_version' => 1,
    ]);

    $this->actingAs($user)
        ->postJson(route('organization.employees.ensure'), [
            'candidate_id' => $candidate->id,
            'name' => 'Provisional Draft Attempt',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['candidate_id']);
});

test('candidate conversion rejects combined employee_id in store request', function () {
    $user = createConversionUser($this->company, [
        'employees.create',
        'employees.view',
        'recruitment.candidates.view',
        'recruitment.candidates.convert',
    ]);

    $candidate = createConversionCandidate([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'position_id' => $this->position->id,
        'name' => 'Combined Store Candidate',
        'stage' => CandidateStage::Joined,
        'actual_joining_date' => '2026-10-01',
        'lock_version' => 1,
    ]);

    $initialCount = Employee::count();

    $this->actingAs($user)
        ->post(route('organization.employees.store'), [
            'candidate_id' => $candidate->id,
            'candidate_lock_version' => 1,
            'employee_id' => 12345,
            'employee_no' => 'EMP-COMBINED-1',
            'name' => 'Combined Candidate',
            'department_id' => $this->department->id,
            'position_id' => $this->position->id,
            'start_date' => '2026-10-01',
            'status' => 'active',
        ])
        ->assertSessionHasErrors(['employee_id']);

    expect(Employee::count())->toBe($initialCount);
});

test('normal employee creation continues to support provisional drafts and ensure endpoint', function () {
    $user = createConversionUser($this->company, [
        'employees.create',
        'employees.view',
    ]);

    // Calling ensure without candidate_id works
    $response = $this->actingAs($user)
        ->postJson(route('organization.employees.ensure'), [
            'name' => 'Normal Provisional Draft',
        ])
        ->assertOk()
        ->assertJsonStructure([
            'employee' => ['id', 'name', 'employee_no'],
        ]);

    $employeeId = $response->json('employee.id');
    expect($employeeId)->toBeGreaterThan(0);

    // Resuming creation with employee_id works
    $this->actingAs($user)
        ->get(route('organization.employees.create', [
            'employee_id' => $employeeId,
        ]))
        ->assertOk();
});
