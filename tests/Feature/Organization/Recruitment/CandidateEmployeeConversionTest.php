<?php

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
use App\Models\RecruitmentCandidateStageTransition;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementLine;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
