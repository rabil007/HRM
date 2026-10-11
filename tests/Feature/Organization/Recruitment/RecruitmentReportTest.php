<?php

use App\Enums\Recruitment\CandidateInterviewOutcome;
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
use App\Support\Reports\Recruitment\RecruitmentReportQuery;
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

test('recruitment report renders page with populated client and project filter options without error', function () {
    $user = createReportUser($this->company, [
        'reports.recruitment.view',
    ]);

    $response = $this->actingAs($user)
        ->get(route('organization.recruitment.reports'));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/recruitment/reports/index')
            ->has('filter_options.clients')
            ->has('filter_options.projects')
            ->where('filter_options.clients.0.value', (string) $this->client->id)
            ->where('filter_options.clients.0.label', $this->client->name)
            ->where('filter_options.projects.0.value', (string) $this->project->id)
            ->where('filter_options.projects.0.label', $this->project->title)
        );
});

test('recruitment report counts selected candidates still in interview stage', function () {
    $user = createReportUser($this->company, [
        'reports.recruitment.view',
    ]);

    RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'position_id' => $this->position->id,
        'position_title_snapshot' => 'Field Engineer',
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'name' => 'Selected Interviewee',
        'stage' => CandidateStage::Interview,
        'interview_outcome' => CandidateInterviewOutcome::Selected,
        'lock_version' => 1,
    ]);

    $response = $this->actingAs($user)
        ->get(route('organization.recruitment.reports'));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.selected_count', 1)
            ->where('summary.applications_total', 1)
        );
});

test('candidate stage filters do not distort position line fulfillment metrics', function () {
    $user = createReportUser($this->company, [
        'reports.recruitment.view',
    ]);

    // Headcount required is 3 (from $this->line)
    // Create 3 Joined candidates
    for ($i = 1; $i <= 3; $i++) {
        RecruitmentCandidate::query()->create([
            'company_id' => $this->company->id,
            'recruitment_requirement_id' => $this->requirement->id,
            'recruitment_requirement_line_id' => $this->line->id,
            'position_id' => $this->position->id,
            'position_title_snapshot' => 'Field Engineer',
            'requirement_number_snapshot' => $this->requirement->requirement_number,
            'name' => "Joined Candidate {$i}",
            'stage' => CandidateStage::Joined,
            'actual_joining_date' => '2026-10-01',
            'lock_version' => 1,
        ]);
    }

    // Create 1 Screening candidate
    RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'position_id' => $this->position->id,
        'position_title_snapshot' => 'Field Engineer',
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'name' => 'Screening Candidate',
        'stage' => CandidateStage::Screening,
        'lock_version' => 1,
    ]);

    // Filter report by stage=screening
    $response = $this->actingAs($user)
        ->get(route('organization.recruitment.reports', ['stage' => CandidateStage::Screening->value]));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // Filtered pipeline view: only 1 screening candidate in view, so pipeline joined_count is 0
            ->has('candidates', 1)
            ->where('summary.applications_total', 1)
            ->where('summary.joined_count', 0)
            // Position line fulfillment: derived from confirmed joined on requirement line, unaffected by stage filter
            ->where('summary.headcount_total', 3)
            ->where('summary.headcount_confirmed_joined', 3)
            ->where('summary.headcount_remaining', 0)
            ->where('summary.headcount_overfill', 0)
        );
});

test('fulfillment metrics calculate remaining and overfill per line without cross-line cancellation', function () {
    $user = createReportUser($this->company, [
        'reports.recruitment.view',
    ]);

    // Set line 1 headcount to 1, and add 2 joined candidates (overfill = 1, remaining = 0)
    $this->line->update(['required_headcount' => 1]);

    for ($i = 1; $i <= 2; $i++) {
        RecruitmentCandidate::query()->create([
            'company_id' => $this->company->id,
            'recruitment_requirement_id' => $this->requirement->id,
            'recruitment_requirement_line_id' => $this->line->id,
            'position_id' => $this->position->id,
            'position_title_snapshot' => 'Field Engineer',
            'requirement_number_snapshot' => $this->requirement->requirement_number,
            'name' => "Overfilled Candidate {$i}",
            'stage' => CandidateStage::Joined,
            'actual_joining_date' => '2026-10-01',
            'lock_version' => 1,
        ]);
    }

    // Create line 2 with headcount 1, and 0 joined candidates (overfill = 0, remaining = 1)
    $position2 = Position::query()->create([
        'company_id' => $this->company->id,
        'title' => 'Project Manager',
        'status' => 'active',
    ]);

    RecruitmentRequirementLine::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'position_id' => $position2->id,
        'required_headcount' => 1,
        'status' => RequirementLineStatus::Open,
        'lock_version' => 1,
    ]);

    $response = $this->actingAs($user)
        ->get(route('organization.recruitment.reports'));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.headcount_total', 2)
            ->where('summary.joined_count', 2)
            // One line has shortage (1 remaining), other line has overfill (1 overfill).
            // They must NOT cancel out to 0 remaining!
            ->where('summary.headcount_remaining', 1)
            ->where('summary.headcount_overfill', 1)
        );
});

test('recruitment duration uses actual_joining_date instead of audit confirmation timestamp', function () {
    $user = createReportUser($this->company, [
        'reports.recruitment.view',
    ]);

    $this->requirement->update([
        'approved_at' => '2026-09-15 08:00:00',
    ]);

    // Candidate created Sep 20, joined Oct 1, confirmation recorded Oct 10
    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'position_id' => $this->position->id,
        'position_title_snapshot' => 'Field Engineer',
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'name' => 'October Joiner',
        'stage' => CandidateStage::Joined,
        'actual_joining_date' => '2026-10-01',
        'joined_at' => '2026-10-10 14:00:00',
        'lock_version' => 1,
    ]);

    DB::table('recruitment_candidates')->where('id', $candidate->id)->update([
        'created_at' => '2026-09-20 09:00:00',
    ]);

    $response = $this->actingAs($user)
        ->get(route('organization.recruitment.reports'));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // Candidate to joined: Sep 20 to Oct 01 = 11 days (not 20 days)
            ->where('candidates.0.time_to_hire_days', 11)
            // Approval to joined: Sep 15 to Oct 01 = 16 days (not 25 days)
            ->where('candidates.0.fulfillment_duration_days', 16)
        );
});

test('recruitment duration normalizes timestamps to company timezone', function () {
    $user = createReportUser($this->company, [
        'reports.recruitment.view',
    ]);

    // Created 2026-10-01 22:30:00 UTC -> 2026-10-02 02:30:00 Asia/Dubai
    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'position_id' => $this->position->id,
        'position_title_snapshot' => 'Field Engineer',
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'name' => 'TZ Joiner',
        'stage' => CandidateStage::Joined,
        'actual_joining_date' => '2026-10-05',
        'lock_version' => 1,
    ]);

    DB::table('recruitment_candidates')->where('id', $candidate->id)->update([
        'created_at' => '2026-10-01 22:30:00',
    ]);
    $candidate->refresh();

    $days = RecruitmentReportQuery::calculateCandidateToJoinedDays($candidate, 'Asia/Dubai');
    // In Asia/Dubai, Oct 2 to Oct 5 is 3 days
    expect($days)->toBe(3);
});

test('recruitment duration returns null when dates are missing or created after actual joining date', function () {
    // 1. Missing actual joining date
    $candidateNoDate = RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'position_id' => $this->position->id,
        'position_title_snapshot' => 'Field Engineer',
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'name' => 'No Date Joiner',
        'stage' => CandidateStage::Joined,
        'actual_joining_date' => null,
        'lock_version' => 1,
    ]);

    expect(RecruitmentReportQuery::calculateCandidateToJoinedDays($candidateNoDate, 'Asia/Dubai'))->toBeNull();

    // 2. Created after joining date (backdated entry)
    $candidateBackdated = RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'position_id' => $this->position->id,
        'position_title_snapshot' => 'Field Engineer',
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'name' => 'Backdated Joiner',
        'stage' => CandidateStage::Joined,
        'actual_joining_date' => '2026-09-01',
        'lock_version' => 1,
    ]);

    DB::table('recruitment_candidates')->where('id', $candidateBackdated->id)->update([
        'created_at' => '2026-10-01 09:00:00',
    ]);
    $candidateBackdated->refresh();

    expect(RecruitmentReportQuery::calculateCandidateToJoinedDays($candidateBackdated, 'Asia/Dubai'))->toBeNull();
});
