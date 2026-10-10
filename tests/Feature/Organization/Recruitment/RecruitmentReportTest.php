<?php

use App\Enums\Recruitment\CandidateStage;
use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Project;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementLine;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Facades\Excel;
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
        'name' => 'Operations',
        'status' => 'active',
    ]);

    $this->position = Position::query()->create([
        'company_id' => $this->company->id,
        'title' => 'Field Engineer',
        'status' => 'active',
    ]);

    $this->client = Client::query()->create([
        'name' => 'Client Prime',
        'is_active' => true,
    ]);

    $this->project = Project::query()->create([
        'title' => 'Offshore Phase 1',
        'is_active' => true,
    ]);

    $this->recruiter = User::factory()->create(['company_id' => $this->company->id]);
    DB::table('company_user')->updateOrInsert(
        ['company_id' => $this->company->id, 'user_id' => $this->recruiter->id],
        ['status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    );

    $this->requirement = RecruitmentRequirement::query()->create([
        'company_id' => $this->company->id,
        'requirement_number' => 'REQ-REP-'.uniqid(),
        'client_id' => $this->client->id,
        'project_id' => $this->project->id,
        'recruiter_id' => $this->recruiter->id,
        'status' => RequirementStatus::Open,
        'lock_version' => 1,
    ]);

    $this->line = RecruitmentRequirementLine::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'position_id' => $this->position->id,
        'required_headcount' => 3,
        'status' => RequirementLineStatus::Open,
        'lock_version' => 1,
    ]);
});

function createReportUser(Company $company, array $permissions): User
{
    $user = User::factory()->create(['company_id' => $company->id]);
    DB::table('company_user')->updateOrInsert(
        ['company_id' => $company->id, 'user_id' => $user->id],
        ['status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    );

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

    $role = Role::query()->create([
        'company_id' => $company->id,
        'name' => 'ReportRole-'.uniqid(),
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

test('recruitment report requires authentication and view permission', function () {
    $this->get(route('organization.recruitment.reports'))
        ->assertRedirect(route('login'));

    $userWithoutPermission = createReportUser($this->company, ['recruitment.candidates.view']);

    $this->actingAs($userWithoutPermission)
        ->get(route('organization.recruitment.reports'))
        ->assertForbidden();
});

test('recruitment report renders inertia page with candidates and summary', function () {
    $user = createReportUser($this->company, [
        'reports.recruitment.view',
        'reports.recruitment.export',
        'recruitment.candidates.view',
        'employees.view',
    ]);

    // Create a joined candidate with employee
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);
    $candidate1 = RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'position_id' => $this->position->id,
        'position_title_snapshot' => 'Field Engineer',
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'name' => 'Candidate One',
        'stage' => CandidateStage::Joined,
        'employee_id' => $employee->id,
        'actual_joining_date' => '2026-10-01',
        'lock_version' => 1,
    ]);

    // Create an applied candidate
    $candidate2 = RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'position_id' => $this->position->id,
        'position_title_snapshot' => 'Field Engineer',
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'name' => 'Candidate Two',
        'stage' => CandidateStage::Applied,
        'lock_version' => 1,
    ]);

    // Foreign company candidate (must not be visible)
    $otherCompany = Company::query()->create([
        'name' => 'Foreign Corp',
        'slug' => 'foreign-corp-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $this->country->id,
        'currency_id' => $this->currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
    RecruitmentCandidate::query()->create([
        'company_id' => $otherCompany->id,
        'position_title_snapshot' => 'Other Title',
        'requirement_number_snapshot' => 'REQ-FOREIGN-1',
        'name' => 'Foreign Candidate',
        'stage' => CandidateStage::Joined,
        'lock_version' => 1,
    ]);

    $response = $this->actingAs($user)
        ->get(route('organization.recruitment.reports'));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/recruitment/reports/index')
            ->has('candidates', 2)
            ->where('summary.applications_total', 2)
            ->where('summary.joined_count', 1)
            ->where('summary.converted_count', 1)
            ->where('summary.headcount_total', 3)
            ->where('summary.headcount_remaining', 2)
            ->where('can.export', true)
        );
});

test('recruitment report filters by stage and conversion status', function () {
    $user = createReportUser($this->company, [
        'reports.recruitment.view',
    ]);

    // Converted candidate
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);
    RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'position_id' => $this->position->id,
        'position_title_snapshot' => 'Field Engineer',
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'name' => 'Converted John',
        'stage' => CandidateStage::Joined,
        'employee_id' => $employee->id,
        'actual_joining_date' => '2026-10-01',
        'lock_version' => 1,
    ]);

    // Joined but pending conversion candidate
    RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'position_id' => $this->position->id,
        'position_title_snapshot' => 'Field Engineer',
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'name' => 'Pending Jane',
        'stage' => CandidateStage::Joined,
        'employee_id' => null,
        'actual_joining_date' => '2026-10-02',
        'lock_version' => 1,
    ]);

    // Unconverted screening candidate
    RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'position_id' => $this->position->id,
        'position_title_snapshot' => 'Field Engineer',
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'name' => 'Screening Bob',
        'stage' => CandidateStage::Screening,
        'employee_id' => null,
        'lock_version' => 1,
    ]);

    // Filter by conversion_status = converted
    $response = $this->actingAs($user)
        ->get(route('organization.recruitment.reports', ['conversion_status' => 'converted']));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('candidates', 1)
            ->where('candidates.0.name', 'Converted John')
        );

    // Filter by conversion_status = pending
    $response = $this->actingAs($user)
        ->get(route('organization.recruitment.reports', ['conversion_status' => 'pending']));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('candidates', 1)
            ->where('candidates.0.name', 'Pending Jane')
        );

    // Filter by stage = screening
    $response = $this->actingAs($user)
        ->get(route('organization.recruitment.reports', ['stage' => CandidateStage::Screening->value]));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('candidates', 1)
            ->where('candidates.0.name', 'Screening Bob')
        );
});

test('recruitment report export requires export permission', function () {
    $userWithoutExport = createReportUser($this->company, [
        'reports.recruitment.view',
    ]);

    $this->actingAs($userWithoutExport)
        ->get(route('organization.recruitment.reports.export'))
        ->assertForbidden();
});

test('recruitment report exports excel and csv with active filters', function () {
    Excel::fake();

    $user = createReportUser($this->company, [
        'reports.recruitment.view',
        'reports.recruitment.export',
    ]);

    RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'position_id' => $this->position->id,
        'position_title_snapshot' => 'Field Engineer',
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'name' => 'Export Candidate',
        'stage' => CandidateStage::Joined,
        'lock_version' => 1,
    ]);

    // Export XLSX
    $this->actingAs($user)
        ->get(route('organization.recruitment.reports.export', [
            'format' => 'xlsx',
            'stage' => CandidateStage::Joined->value,
        ]))
        ->assertOk();

    Excel::assertDownloaded('recruitment-report-'.now()->toDateString().'.xlsx');

    // Export CSV
    $this->actingAs($user)
        ->get(route('organization.recruitment.reports.export', [
            'format' => 'csv',
            'stage' => CandidateStage::Joined->value,
        ]))
        ->assertOk();

    Excel::assertDownloaded('recruitment-report-'.now()->toDateString().'.csv');
});
