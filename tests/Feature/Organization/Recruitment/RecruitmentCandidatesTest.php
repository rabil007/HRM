<?php

use App\Enums\Recruitment\CandidateInterviewOutcome;
use App\Enums\Recruitment\CandidateOfferStatus;
use App\Enums\Recruitment\CandidateStage;
use App\Enums\Recruitment\CandidateTransitionAction;
use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Position;
use App\Models\Project;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentCandidateOffer;
use App\Models\RecruitmentCandidateStageTransition;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementLine;
use App\Models\User;
use App\Support\MasterData\MasterDataUsage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function createCandidateTestCompany(string $name, string $code): Company
{
    $country = Country::query()->create([
        'code' => $code,
        'name' => "{$name} Country",
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->firstOrCreate(
        ['code' => 'AED'],
        ['name' => 'Dirham', 'symbol' => 'د.إ', 'is_active' => true],
    );

    return Company::query()->create([
        'name' => $name,
        'slug' => strtolower(str_replace(' ', '-', $name)).'-'.strtolower($code),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
}

function createCandidateTestUser(Company $company, array $permissions = []): User
{
    $user = User::factory()->create([
        'company_id' => $company->id,
    ]);

    DB::table('company_user')->updateOrInsert(
        ['company_id' => $company->id, 'user_id' => $user->id],
        ['status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    );

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

    foreach ($permissions as $permName) {
        $permission = Permission::query()->firstOrCreate([
            'name' => $permName,
            'guard_name' => 'web',
        ]);
        $user->givePermissionTo($permission);
    }

    return $user;
}

/**
 * @return array{requirement: RecruitmentRequirement, line: RecruitmentRequirementLine}
 */
function createOpenRequirementWithLine(
    Company $company,
    User $requester,
    User $recruiter,
    Position $position,
    string $number = 'REQ-CAND-0001',
): array {
    $client = Client::query()->create(['name' => 'Client '.$number, 'is_active' => true]);
    $project = Project::query()->create(['title' => 'Project '.$number, 'is_active' => true]);
    $project->clients()->sync([$client->id]);

    $requirement = RecruitmentRequirement::query()->create([
        'company_id' => $company->id,
        'requirement_number' => $number,
        'client_id' => $client->id,
        'project_id' => $project->id,
        'request_received_date' => now()->subDays(2),
        'required_by_date' => now()->addDays(10),
        'priority' => 'normal',
        'status' => RequirementStatus::Open,
        'assigned_to' => $recruiter->id,
        'created_by' => $requester->id,
        'approved_at' => now()->subDay(),
        'approved_by' => $recruiter->id,
        'opened_at' => now()->subDay(),
        'updated_by' => $recruiter->id,
    ]);

    $line = RecruitmentRequirementLine::query()->create([
        'company_id' => $company->id,
        'recruitment_requirement_id' => $requirement->id,
        'position_id' => $position->id,
        'required_headcount' => 2,
        'status' => RequirementLineStatus::Open,
        'salary_min' => 4000,
        'salary_max' => 6000,
        'salary_currency_code' => 'AED',
    ]);

    return ['requirement' => $requirement, 'line' => $line];
}

beforeEach(function () {
    Storage::fake('local');

    $this->companyA = createCandidateTestCompany('Cand Alpha', 'CA1');
    $this->companyB = createCandidateTestCompany('Cand Beta', 'CB1');

    $candidatePerms = [
        'recruitment.candidates.view',
        'recruitment.candidates.create',
        'recruitment.candidates.update',
        'recruitment.candidates.move',
        'recruitment.candidates.cv.download',
    ];

    $this->recruiterA = createCandidateTestUser($this->companyA, $candidatePerms);
    $this->requesterA = createCandidateTestUser($this->companyA, ['recruitment.candidates.view']);
    $this->managerA = createCandidateTestUser($this->companyA, array_merge($candidatePerms, [
        'recruitment.candidates.manage',
    ]));
    $this->viewerOnly = createCandidateTestUser($this->companyA, ['recruitment.candidates.view']);
    $this->recruiterB = createCandidateTestUser($this->companyB, $candidatePerms);

    $this->positionA = Position::query()->create([
        'company_id' => $this->companyA->id,
        'title' => 'Chief Officer',
        'status' => 'active',
    ]);
    $this->positionB = Position::query()->create([
        'company_id' => $this->companyB->id,
        'title' => 'Able Seaman',
        'status' => 'active',
    ]);

    $open = createOpenRequirementWithLine(
        $this->companyA,
        $this->requesterA,
        $this->recruiterA,
        $this->positionA,
    );
    $this->requirement = $open['requirement'];
    $this->line = $open['line'];
});

test('users without candidate view permission receive 403', function () {
    $user = createCandidateTestUser($this->companyA, []);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get('/organization/recruitment/candidates')
        ->assertForbidden();
});

test('company isolation hides other company candidates', function () {
    $openB = createOpenRequirementWithLine(
        $this->companyB,
        $this->recruiterB,
        $this->recruiterB,
        $this->positionB,
        'REQ-CAND-B001',
    );

    RecruitmentCandidate::query()->create([
        'company_id' => $this->companyB->id,
        'recruitment_requirement_id' => $openB['requirement']->id,
        'recruitment_requirement_line_id' => $openB['line']->id,
        'requirement_number_snapshot' => 'REQ-CAND-B001',
        'position_title_snapshot' => 'Able Seaman',
        'name' => 'Beta Person',
        'email' => 'beta@example.com',
        'email_normalized' => 'beta@example.com',
        'stage' => CandidateStage::Applied,
        'created_by' => $this->recruiterB->id,
        'updated_by' => $this->recruiterB->id,
    ]);

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get('/organization/recruitment/candidates')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/recruitment/candidates/index')
            ->where('candidates.total', 0)
        );
});

test('assigned recruiter can create candidate and snapshots requirement data', function () {
    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post('/organization/recruitment/candidates', [
            'recruitment_requirement_id' => $this->requirement->id,
            'recruitment_requirement_line_id' => $this->line->id,
            'name' => 'Jane Applicant',
            'email' => 'jane@example.com',
            'phone' => '',
            'ignore_duplicate_warning' => true,
        ])
        ->assertRedirect();

    $candidate = RecruitmentCandidate::query()->first();
    expect($candidate)->not->toBeNull()
        ->and($candidate->name)->toBe('Jane Applicant')
        ->and($candidate->stage)->toBe(CandidateStage::Applied)
        ->and($candidate->requirement_number_snapshot)->toBe($this->requirement->requirement_number)
        ->and($candidate->position_title_snapshot)->toBe('Chief Officer');

    expect(RecruitmentCandidateStageTransition::query()->where('recruitment_candidate_id', $candidate->id)->count())
        ->toBe(1);
});

test('non-owner without manage cannot create candidates', function () {
    $otherRecruiter = createCandidateTestUser($this->companyA, [
        'recruitment.candidates.view',
        'recruitment.candidates.create',
    ]);

    $this->actingAs($otherRecruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->from('/organization/recruitment/candidates')
        ->post('/organization/recruitment/candidates', [
            'recruitment_requirement_id' => $this->requirement->id,
            'recruitment_requirement_line_id' => $this->line->id,
            'name' => 'Blocked',
            'email' => 'blocked@example.com',
            'ignore_duplicate_warning' => true,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('candidate');
});

test('manage override allows create without being assigned recruiter', function () {
    $this->actingAs($this->managerA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post('/organization/recruitment/candidates', [
            'recruitment_requirement_id' => $this->requirement->id,
            'recruitment_requirement_line_id' => $this->line->id,
            'name' => 'Managed Hire',
            'phone' => '+971500000001',
            'ignore_duplicate_warning' => true,
        ])
        ->assertRedirect();

    expect(RecruitmentCandidate::query()->where('name', 'Managed Hire')->exists())->toBeTrue();
});

test('mismatched requirement line is rejected', function () {
    $other = createOpenRequirementWithLine(
        $this->companyA,
        $this->requesterA,
        $this->recruiterA,
        $this->positionA,
        'REQ-CAND-0002',
    );

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->from('/organization/recruitment/candidates')
        ->post('/organization/recruitment/candidates', [
            'recruitment_requirement_id' => $this->requirement->id,
            'recruitment_requirement_line_id' => $other['line']->id,
            'name' => 'Mismatch',
            'email' => 'mismatch@example.com',
            'ignore_duplicate_warning' => true,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('recruitment_requirement_line_id');
});

test('ownership follows recruiter reassignment', function () {
    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'Owned',
        'email' => 'owned@example.com',
        'email_normalized' => 'owned@example.com',
        'stage' => CandidateStage::Applied,
        'created_by' => $this->recruiterA->id,
        'updated_by' => $this->recruiterA->id,
    ]);

    $newRecruiter = createCandidateTestUser($this->companyA, [
        'recruitment.candidates.view',
        'recruitment.candidates.move',
    ]);

    $this->requirement->update(['assigned_to' => $newRecruiter->id]);

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/candidates/{$candidate->id}/move", [
            'lock_version' => 0,
            'expected_stage' => 'applied',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('candidate');

    $this->actingAs($newRecruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/candidates/{$candidate->id}/move", [
            'lock_version' => 0,
            'expected_stage' => 'applied',
        ])
        ->assertRedirect();

    expect($candidate->fresh()->stage)->toBe(CandidateStage::Screening);
});

test('stage transitions and selection rejection history are recorded', function () {
    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'Flow',
        'email' => 'flow@example.com',
        'email_normalized' => 'flow@example.com',
        'stage' => CandidateStage::Applied,
        'lock_version' => 0,
        'created_by' => $this->recruiterA->id,
        'updated_by' => $this->recruiterA->id,
    ]);

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id]);

    $this->post("/organization/recruitment/candidates/{$candidate->id}/move", [
        'lock_version' => 0,
        'expected_stage' => 'applied',
    ])->assertRedirect();

    $candidate->refresh();
    $this->post("/organization/recruitment/candidates/{$candidate->id}/move", [
        'lock_version' => $candidate->lock_version,
        'expected_stage' => 'screening',
    ])->assertRedirect();

    $candidate->refresh();
    $this->post("/organization/recruitment/candidates/{$candidate->id}/select", [
        'lock_version' => $candidate->lock_version,
        'expected_stage' => 'interview',
    ])->assertRedirect();

    $candidate->refresh();
    expect($candidate->stage)->toBe(CandidateStage::Interview)
        ->and($candidate->interview_outcome)->toBe(CandidateInterviewOutcome::Selected);

    $this->post("/organization/recruitment/candidates/{$candidate->id}/undo-select", [
        'lock_version' => $candidate->lock_version,
        'expected_stage' => 'interview',
        'expected_outcome' => 'selected',
    ])->assertRedirect();

    $candidate->refresh();
    expect($candidate->interview_outcome)->toBeNull();

    $this->post("/organization/recruitment/candidates/{$candidate->id}/reject", [
        'reason' => 'Not a fit',
        'lock_version' => $candidate->lock_version,
        'expected_stage' => 'interview',
    ])->assertRedirect();

    $candidate->refresh();
    expect($candidate->stage)->toBe(CandidateStage::Rejected)
        ->and($candidate->interview_outcome)->toBe(CandidateInterviewOutcome::NotSelected)
        ->and($candidate->pre_rejection_stage)->toBe('interview');

    $this->actingAs($this->managerA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/candidates/{$candidate->id}/reopen", [
            'reason' => 'Reconsider',
            'lock_version' => $candidate->lock_version,
            'expected_stage' => 'rejected',
        ])
        ->assertRedirect();

    $candidate->refresh();
    expect($candidate->stage)->toBe(CandidateStage::Interview)
        ->and($candidate->interview_outcome)->toBeNull();

    expect(RecruitmentCandidateStageTransition::query()->where('recruitment_candidate_id', $candidate->id)->count())
        ->toBeGreaterThanOrEqual(5);
});

test('duplicate contact warning does not block when ignored', function () {
    RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'First',
        'email' => 'dup@example.com',
        'email_normalized' => 'dup@example.com',
        'stage' => CandidateStage::Applied,
        'created_by' => $this->recruiterA->id,
        'updated_by' => $this->recruiterA->id,
    ]);

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->from('/organization/recruitment/candidates')
        ->post('/organization/recruitment/candidates', [
            'recruitment_requirement_id' => $this->requirement->id,
            'recruitment_requirement_line_id' => $this->line->id,
            'name' => 'Second',
            'email' => 'DUP@example.com',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('duplicate_warning');

    $this->post('/organization/recruitment/candidates', [
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'name' => 'Second',
        'email' => 'DUP@example.com',
        'ignore_duplicate_warning' => true,
    ])->assertRedirect();

    expect(RecruitmentCandidate::query()->where('email_normalized', 'dup@example.com')->count())->toBe(2);
});

test('private cv download requires permission and company scope', function () {
    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'CV Person',
        'email' => 'cv@example.com',
        'email_normalized' => 'cv@example.com',
        'stage' => CandidateStage::Applied,
        'created_by' => $this->recruiterA->id,
        'updated_by' => $this->recruiterA->id,
    ]);

    $path = "recruitment/candidates/{$this->companyA->id}/{$candidate->id}/cv.pdf";
    Storage::disk('local')->put($path, 'pdf-bytes');
    $candidate->update([
        'cv_path' => $path,
        'cv_original_file_name' => 'cv.pdf',
        'cv_mime_type' => 'application/pdf',
        'cv_file_size_bytes' => 9,
        'cv_file_checksum' => hash('sha256', 'pdf-bytes'),
    ]);

    $this->actingAs($this->viewerOnly)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get("/organization/recruitment/candidates/{$candidate->id}/cv")
        ->assertForbidden();

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get("/organization/recruitment/candidates/{$candidate->id}/cv")
        ->assertOk();

    $this->actingAs($this->recruiterB)
        ->withSession(['current_company_id' => $this->companyB->id])
        ->get("/organization/recruitment/candidates/{$candidate->id}/cv")
        ->assertNotFound();
});

test('stale lock version is rejected for moves', function () {
    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'Stale',
        'email' => 'stale@example.com',
        'email_normalized' => 'stale@example.com',
        'stage' => CandidateStage::Applied,
        'lock_version' => 3,
        'created_by' => $this->recruiterA->id,
        'updated_by' => $this->recruiterA->id,
    ]);

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->from("/organization/recruitment/candidates/{$candidate->id}")
        ->post("/organization/recruitment/candidates/{$candidate->id}/move", [
            'lock_version' => 1,
            'expected_stage' => 'applied',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('lock_version');
});

test('master data usage includes recruitment_candidates nationality', function () {
    $country = Country::query()->where('code', 'CA1')->firstOrFail();

    RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'National',
        'email' => 'national@example.com',
        'email_normalized' => 'national@example.com',
        'nationality_id' => $country->id,
        'stage' => CandidateStage::Applied,
        'created_by' => $this->recruiterA->id,
        'updated_by' => $this->recruiterA->id,
    ]);

    $sources = MasterDataUsage::sourcesFor(Country::class);
    $tableNames = collect($sources)->map(fn ($s) => $s->table ?? null)->filter()->values()->all();

    expect($tableNames)->toContain('recruitment_candidates');
});

test('line hard delete is blocked when candidates exist', function () {
    RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'Keep Line',
        'email' => 'keepline@example.com',
        'email_normalized' => 'keepline@example.com',
        'stage' => CandidateStage::Applied,
        'created_by' => $this->recruiterA->id,
        'updated_by' => $this->recruiterA->id,
    ]);

    expect(fn () => $this->line->delete())->toThrow(ValidationException::class);
});

test('kanban view returns independent stage column totals', function () {
    foreach ([CandidateStage::Applied, CandidateStage::Screening, CandidateStage::Interview] as $index => $stage) {
        RecruitmentCandidate::query()->create([
            'company_id' => $this->companyA->id,
            'recruitment_requirement_id' => $this->requirement->id,
            'recruitment_requirement_line_id' => $this->line->id,
            'requirement_number_snapshot' => $this->requirement->requirement_number,
            'position_title_snapshot' => 'Chief Officer',
            'name' => "Kanban {$index}",
            'email' => "kanban{$index}@example.com",
            'email_normalized' => "kanban{$index}@example.com",
            'stage' => $stage,
            'created_by' => $this->recruiterA->id,
            'updated_by' => $this->recruiterA->id,
        ]);
    }

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get('/organization/recruitment/candidates?view=kanban')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/recruitment/candidates/index')
            ->where('filters.view', 'kanban')
            ->where('stage_totals.applied', 1)
            ->where('stage_totals.screening', 1)
            ->where('stage_totals.interview', 1)
            ->has('kanban.applied.data', 1)
            ->where('kanban.applied.data.0.days_in_stage', 0)
            ->where('filters.attention', false)
            ->has('kanban.rejected')
        );
});

test('needs attention filter returns blockers and pending conversion candidates', function () {
    $blocked = RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'Blocked Candidate',
        'email' => 'blocked-attention@example.com',
        'email_normalized' => 'blocked-attention@example.com',
        'stage' => CandidateStage::OfferJol,
        'interview_outcome' => CandidateInterviewOutcome::Selected,
        'joining_blocker_notes' => 'Visa pending',
        'created_by' => $this->recruiterA->id,
        'updated_by' => $this->recruiterA->id,
    ]);

    $pendingConversion = RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'Pending Conversion',
        'email' => 'pending-conversion@example.com',
        'email_normalized' => 'pending-conversion@example.com',
        'stage' => CandidateStage::Joined,
        'interview_outcome' => CandidateInterviewOutcome::Selected,
        'actual_joining_date' => now()->toDateString(),
        'created_by' => $this->recruiterA->id,
        'updated_by' => $this->recruiterA->id,
    ]);

    RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'Quiet Candidate',
        'email' => 'quiet-attention@example.com',
        'email_normalized' => 'quiet-attention@example.com',
        'stage' => CandidateStage::Applied,
        'created_by' => $this->recruiterA->id,
        'updated_by' => $this->recruiterA->id,
    ]);

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get('/organization/recruitment/candidates?view=table&attention=1')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/recruitment/candidates/index')
            ->where('filters.attention', true)
            ->where('candidates.total', 2)
            ->has('candidates.data', 2)
            ->where('candidates.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === collect([$blocked->id, $pendingConversion->id])->sort()->values()->all())
        );
});

test('recruitment landing redirects candidates-only users to candidates index', function () {
    $this->actingAs($this->viewerOnly)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get('/organization/recruitment')
        ->assertRedirect(route('organization.recruitment.candidates.index'));
});

test('movement history is omitted without audit.view', function () {
    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'Audit Gate',
        'email' => 'audit-gate@example.com',
        'email_normalized' => 'audit-gate@example.com',
        'stage' => CandidateStage::Applied,
        'created_by' => $this->recruiterA->id,
        'updated_by' => $this->recruiterA->id,
    ]);

    RecruitmentCandidateStageTransition::query()->create([
        'recruitment_candidate_id' => $candidate->id,
        'company_id' => $this->companyA->id,
        'action' => 'created',
        'from_stage' => null,
        'to_stage' => 'applied',
        'from_outcome' => null,
        'to_outcome' => null,
        'reason' => 'secret reason',
        'context' => ['note' => 'hidden'],
        'performed_by' => $this->recruiterA->id,
    ]);

    $this->actingAs($this->viewerOnly)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get("/organization/recruitment/candidates/{$candidate->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/recruitment/candidates/show')
            ->where('can_view_audit', false)
            ->where('candidate.movement_history', [])
            ->missing('candidate.movement_history.0')
        );

    $auditor = createCandidateTestUser($this->companyA, [
        'recruitment.candidates.view',
        'audit.view',
    ]);

    $this->actingAs($auditor)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get("/organization/recruitment/candidates/{$candidate->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can_view_audit', true)
            ->has('candidate.movement_history', 1)
            ->where('candidate.movement_history.0.reason', 'secret reason')
            ->where('candidate.movement_history.0.performed_by_name', $this->recruiterA->name)
        );
});

test('successful cv replacement stores new file then deletes the old after commit', function () {
    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'CV Replace',
        'email' => 'cv-replace@example.com',
        'email_normalized' => 'cv-replace@example.com',
        'stage' => CandidateStage::Applied,
        'lock_version' => 0,
        'created_by' => $this->recruiterA->id,
        'updated_by' => $this->recruiterA->id,
    ]);

    $oldPath = "recruitment/candidates/{$this->companyA->id}/{$candidate->id}/old.pdf";
    Storage::disk('local')->put($oldPath, 'old-bytes');
    $candidate->update([
        'cv_path' => $oldPath,
        'cv_original_file_name' => 'old.pdf',
        'cv_mime_type' => 'application/pdf',
        'cv_file_size_bytes' => 9,
        'cv_file_checksum' => hash('sha256', 'old-bytes'),
    ]);

    $newCv = UploadedFile::fake()->create('new.pdf', 120, 'application/pdf');

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/candidates/{$candidate->id}", [
            '_method' => 'put',
            'name' => 'CV Replace',
            'email' => 'cv-replace@example.com',
            'lock_version' => 0,
            'ignore_duplicate_warning' => true,
            'cv' => $newCv,
        ])
        ->assertRedirect();

    $candidate->refresh();
    expect($candidate->cv_path)->not->toBe($oldPath)
        ->and($candidate->cv_original_file_name)->toBe('new.pdf');
    Storage::disk('local')->assertMissing($oldPath);
    Storage::disk('local')->assertExists($candidate->cv_path);
});

test('failed cv replacement preserves the original file and reference', function () {
    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'CV Keep',
        'email' => 'cv-keep@example.com',
        'email_normalized' => 'cv-keep@example.com',
        'stage' => CandidateStage::Applied,
        'lock_version' => 2,
        'created_by' => $this->recruiterA->id,
        'updated_by' => $this->recruiterA->id,
    ]);

    $oldPath = "recruitment/candidates/{$this->companyA->id}/{$candidate->id}/keep.pdf";
    Storage::disk('local')->put($oldPath, 'keep-bytes');
    $candidate->update([
        'cv_path' => $oldPath,
        'cv_original_file_name' => 'keep.pdf',
        'cv_mime_type' => 'application/pdf',
        'cv_file_size_bytes' => 10,
        'cv_file_checksum' => hash('sha256', 'keep-bytes'),
    ]);

    $newCv = UploadedFile::fake()->create('attempt.pdf', 80, 'application/pdf');

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->from("/organization/recruitment/candidates/{$candidate->id}")
        ->post("/organization/recruitment/candidates/{$candidate->id}", [
            '_method' => 'put',
            'name' => 'CV Keep',
            'email' => 'cv-keep@example.com',
            'lock_version' => 1,
            'ignore_duplicate_warning' => true,
            'cv' => $newCv,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('lock_version');

    $candidate->refresh();
    expect($candidate->cv_path)->toBe($oldPath)
        ->and($candidate->cv_original_file_name)->toBe('keep.pdf')
        ->and($candidate->lock_version)->toBe(2);
    Storage::disk('local')->assertExists($oldPath);
});

test('create candidate cleans up cv when post-create work fails', function () {
    Schema::drop('recruitment_candidate_stage_transitions');

    $cv = UploadedFile::fake()->create('orphan.pdf', 90, 'application/pdf');

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post('/organization/recruitment/candidates', [
            'recruitment_requirement_id' => $this->requirement->id,
            'recruitment_requirement_line_id' => $this->line->id,
            'name' => 'Orphan CV',
            'email' => 'orphan-cv@example.com',
            'ignore_duplicate_warning' => true,
            'cv' => $cv,
        ])
        ->assertStatus(500);

    expect(RecruitmentCandidate::query()->count())->toBe(0);
    expect(Storage::disk('local')->allFiles('recruitment/candidates'))->toBe([]);
});

test('kanban and table apply stage filter consistently', function () {
    foreach ([CandidateStage::Applied, CandidateStage::Screening] as $index => $stage) {
        RecruitmentCandidate::query()->create([
            'company_id' => $this->companyA->id,
            'recruitment_requirement_id' => $this->requirement->id,
            'recruitment_requirement_line_id' => $this->line->id,
            'requirement_number_snapshot' => $this->requirement->requirement_number,
            'position_title_snapshot' => 'Chief Officer',
            'name' => "Stage {$index}",
            'email' => "stage{$index}@example.com",
            'email_normalized' => "stage{$index}@example.com",
            'stage' => $stage,
            'created_by' => $this->recruiterA->id,
            'updated_by' => $this->recruiterA->id,
        ]);
    }

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get('/organization/recruitment/candidates?view=table&stage=screening')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.stage', 'screening')
            ->where('candidates.total', 1)
            ->where('candidates.data.0.stage', 'screening')
        );

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get('/organization/recruitment/candidates?view=kanban&stage=screening')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.stage', 'screening')
            ->has('kanban.screening')
            ->missing('kanban.applied')
            ->has('kanban.screening.data', 1)
        );
});

test('browse options include closed and non-owned requirements without broadening create options', function () {
    $otherRecruiter = createCandidateTestUser($this->companyA, [
        'recruitment.candidates.view',
        'recruitment.candidates.create',
    ]);

    $closed = createOpenRequirementWithLine(
        $this->companyA,
        $this->requesterA,
        $otherRecruiter,
        $this->positionA,
        'REQ-CAND-CLOSED',
    );
    $closed['requirement']->update(['status' => RequirementStatus::Completed]);
    $closed['line']->update(['status' => RequirementLineStatus::Filled]);

    RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $closed['requirement']->id,
        'recruitment_requirement_line_id' => $closed['line']->id,
        'requirement_number_snapshot' => 'REQ-CAND-CLOSED',
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'Historical',
        'email' => 'historical@example.com',
        'email_normalized' => 'historical@example.com',
        'stage' => CandidateStage::Interview,
        'created_by' => $otherRecruiter->id,
        'updated_by' => $otherRecruiter->id,
    ]);

    $this->actingAs($this->viewerOnly)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get('/organization/recruitment/candidates')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('browse_options.requirements', fn ($requirements) => collect($requirements)
                ->contains(fn ($requirement) => $requirement['requirement_number'] === 'REQ-CAND-CLOSED'))
            ->where('options.requirements', fn ($requirements) => collect($requirements)
                ->doesntContain(fn ($requirement) => $requirement['requirement_number'] === 'REQ-CAND-CLOSED'))
        );
});

test('missing lock version is rejected and duplicate moves do not advance twice', function () {
    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'Lock Guard',
        'email' => 'lock-guard@example.com',
        'email_normalized' => 'lock-guard@example.com',
        'stage' => CandidateStage::Applied,
        'lock_version' => 0,
        'created_by' => $this->recruiterA->id,
        'updated_by' => $this->recruiterA->id,
    ]);

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->from("/organization/recruitment/candidates/{$candidate->id}")
        ->post("/organization/recruitment/candidates/{$candidate->id}/move", [
            'expected_stage' => 'applied',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('lock_version');

    expect($candidate->fresh()->stage)->toBe(CandidateStage::Applied);

    $this->post("/organization/recruitment/candidates/{$candidate->id}/move", [
        'lock_version' => 0,
        'expected_stage' => 'applied',
    ])->assertRedirect();

    expect($candidate->fresh()->stage)->toBe(CandidateStage::Screening)
        ->and($candidate->fresh()->lock_version)->toBe(1);

    $this->from("/organization/recruitment/candidates/{$candidate->id}")
        ->post("/organization/recruitment/candidates/{$candidate->id}/move", [
            'lock_version' => 0,
            'expected_stage' => 'applied',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('lock_version');

    expect($candidate->fresh()->stage)->toBe(CandidateStage::Screening)
        ->and(RecruitmentCandidateStageTransition::query()
            ->where('recruitment_candidate_id', $candidate->id)
            ->where('to_stage', 'screening')
            ->count())->toBe(1);
});

test('closed parents and changed ownership are rejected inside the locked transaction', function () {
    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'Parent Guard',
        'email' => 'parent-guard@example.com',
        'email_normalized' => 'parent-guard@example.com',
        'stage' => CandidateStage::Applied,
        'lock_version' => 0,
        'created_by' => $this->recruiterA->id,
        'updated_by' => $this->recruiterA->id,
    ]);

    $this->requirement->update(['status' => RequirementStatus::OnHold]);

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->from("/organization/recruitment/candidates/{$candidate->id}")
        ->post("/organization/recruitment/candidates/{$candidate->id}/move", [
            'lock_version' => 0,
            'expected_stage' => 'applied',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('candidate');

    expect($candidate->fresh()->stage)->toBe(CandidateStage::Applied);

    $this->requirement->update([
        'status' => RequirementStatus::Open,
        'assigned_to' => $this->viewerOnly->id,
    ]);

    $this->from("/organization/recruitment/candidates/{$candidate->id}")
        ->post("/organization/recruitment/candidates/{$candidate->id}/move", [
            'lock_version' => 0,
            'expected_stage' => 'applied',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('candidate')
        ->assertSessionHasInput('expected_stage', 'applied');
});

test('selected candidates can be rejected without undo and validation preserves reject reason', function () {
    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'Selected Reject',
        'email' => 'selected-reject@example.com',
        'email_normalized' => 'selected-reject@example.com',
        'stage' => CandidateStage::Interview,
        'interview_outcome' => CandidateInterviewOutcome::Selected,
        'lock_version' => 0,
        'created_by' => $this->recruiterA->id,
        'updated_by' => $this->recruiterA->id,
    ]);

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->from("/organization/recruitment/candidates/{$candidate->id}")
        ->post("/organization/recruitment/candidates/{$candidate->id}/reject", [
            'reason' => 'Client declined',
            'lock_version' => 99,
            'expected_stage' => 'interview',
            'expected_outcome' => 'selected',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('lock_version')
        ->assertSessionHasInput('reason', 'Client declined');

    expect($candidate->fresh()->stage)->toBe(CandidateStage::Interview)
        ->and($candidate->fresh()->interview_outcome)->toBe(CandidateInterviewOutcome::Selected);

    $this->post("/organization/recruitment/candidates/{$candidate->id}/reject", [
        'reason' => 'Client declined',
        'lock_version' => 0,
        'expected_stage' => 'interview',
        'expected_outcome' => 'selected',
    ])->assertRedirect();

    $candidate->refresh();
    expect($candidate->stage)->toBe(CandidateStage::Rejected)
        ->and($candidate->interview_outcome)->toBe(CandidateInterviewOutcome::NotSelected)
        ->and($candidate->rejection_reason)->toBe('Client declined');
});

test('kanban through_page returns previously loaded page ranges authoritatively after a move', function () {
    $candidates = [];

    foreach (range(1, 31) as $index) {
        $candidates[$index] = RecruitmentCandidate::query()->create([
            'company_id' => $this->companyA->id,
            'recruitment_requirement_id' => $this->requirement->id,
            'recruitment_requirement_line_id' => $this->line->id,
            'requirement_number_snapshot' => $this->requirement->requirement_number,
            'position_title_snapshot' => 'Chief Officer',
            'name' => "Applied {$index}",
            'email' => "applied{$index}@example.com",
            'email_normalized' => "applied{$index}@example.com",
            'stage' => CandidateStage::Applied,
            'lock_version' => 0,
            'created_by' => $this->recruiterA->id,
            'updated_by' => $this->recruiterA->id,
        ]);
        $candidates[$index]->forceFill([
            'updated_at' => now()->subMinutes(40 - $index),
        ])->saveQuietly();
    }

    // Newest-first pagination: page 1 = 31..17, page 2 = 16..2, remainder page 3 = 1.
    $moved = $candidates[16];

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get('/organization/recruitment/candidates?view=kanban&per_page=15&page_applied=2')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('kanban.applied.data', 15)
            ->where('kanban.applied.current_page', 2)
            ->where('kanban.applied.total', 31)
            ->where('kanban.applied.data.0.id', $moved->id)
        );

    $this->from('/organization/recruitment/candidates?view=kanban&per_page=15&page_applied=2')
        ->post("/organization/recruitment/candidates/{$moved->id}/move", [
            'lock_version' => 0,
            'expected_stage' => 'applied',
        ])
        ->assertRedirect();

    expect($moved->fresh()->stage)->toBe(CandidateStage::Screening);

    $this->get('/organization/recruitment/candidates?view=kanban&per_page=15&through_page_applied=2')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('kanban.applied.data', 30)
            ->where('kanban.applied.current_page', 2)
            ->where('kanban.applied.last_page', 2)
            ->where('kanban.applied.total', 30)
            ->where('kanban.applied.from', 1)
            ->where('kanban.applied.to', 30)
            ->where('stage_totals.applied', 30)
            ->where('stage_totals.screening', 1)
            ->has('kanban.screening.data', 1)
            ->where('kanban.screening.data.0.id', $moved->id)
            ->where('kanban.applied.data', fn ($rows) => collect($rows)->every(
                fn ($row) => (int) $row['id'] !== (int) $moved->id,
            ))
        );

    // Single-page fetch still returns only page 2 (no duplicates when the client appends).
    $this->get('/organization/recruitment/candidates?view=kanban&per_page=15&page_applied=2')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('kanban.applied.data', 15)
            ->where('kanban.applied.current_page', 2)
            ->where('kanban.applied.data', fn ($rows) => collect($rows)->every(
                fn ($row) => (int) $row['id'] !== (int) $moved->id,
            ))
        );
});

test('interview update validates field lengths and preserves input without success flash', function () {
    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'Interview Fields',
        'email' => 'interview-fields@example.com',
        'email_normalized' => 'interview-fields@example.com',
        'stage' => CandidateStage::Interview,
        'lock_version' => 0,
        'created_by' => $this->recruiterA->id,
        'updated_by' => $this->recruiterA->id,
    ]);

    $tooLongName = str_repeat('n', 201);
    $tooLongLocation = str_repeat('l', 301);
    $tooLongFeedback = str_repeat('f', 10001);

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->from("/organization/recruitment/candidates/{$candidate->id}")
        ->put("/organization/recruitment/candidates/{$candidate->id}/interview", [
            'external_interviewer_name' => $tooLongName,
            'interview_location' => $tooLongLocation,
            'interview_feedback' => $tooLongFeedback,
            'lock_version' => 0,
            'expected_stage' => 'interview',
        ])
        ->assertRedirect("/organization/recruitment/candidates/{$candidate->id}")
        ->assertSessionHasErrors([
            'external_interviewer_name',
            'interview_location',
            'interview_feedback',
        ])
        ->assertSessionMissing('success')
        ->assertSessionHasInput('external_interviewer_name', $tooLongName)
        ->assertSessionHasInput('interview_location', $tooLongLocation)
        ->assertSessionHasInput('interview_feedback', $tooLongFeedback);

    $this->put("/organization/recruitment/candidates/{$candidate->id}/interview", [
        'external_interviewer_name' => 'Alex External',
        'interview_location' => 'Teams link',
        'interview_feedback' => 'Strong technical interview.',
        'lock_version' => 0,
        'expected_stage' => 'interview',
    ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Interview information updated.');

    $candidate->refresh();
    expect($candidate->external_interviewer_name)->toBe('Alex External')
        ->and($candidate->interview_location)->toBe('Teams link')
        ->and($candidate->interview_feedback)->toBe('Strong technical interview.');
});

test('screening can be corrected back to applied with audited reason', function () {
    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'Correct Screen',
        'email' => 'correct-screen@example.com',
        'email_normalized' => 'correct-screen@example.com',
        'stage' => CandidateStage::Screening,
        'lock_version' => 2,
        'created_by' => $this->recruiterA->id,
        'updated_by' => $this->recruiterA->id,
    ]);

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/candidates/{$candidate->id}/correct-stage", [
            'to_stage' => 'applied',
            'reason' => 'Wrong column drop',
            'lock_version' => 2,
            'expected_stage' => 'screening',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $candidate->refresh();
    expect($candidate->stage)->toBe(CandidateStage::Applied)
        ->and($candidate->lock_version)->toBe(3);

    $transition = RecruitmentCandidateStageTransition::query()
        ->where('recruitment_candidate_id', $candidate->id)
        ->latest('id')
        ->first();

    expect($transition)->not->toBeNull()
        ->and($transition->action->value)->toBe('stage_corrected')
        ->and($transition->from_stage)->toBe(CandidateStage::Screening)
        ->and($transition->to_stage)->toBe(CandidateStage::Applied)
        ->and($transition->reason)->toBe('Wrong column drop')
        ->and($transition->performed_by)->toBe($this->recruiterA->id);
});

test('interview correction to screening snapshots cycle and clears active interview fields', function () {
    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'Correct Interview',
        'email' => 'correct-interview@example.com',
        'email_normalized' => 'correct-interview@example.com',
        'stage' => CandidateStage::Interview,
        'interview_outcome' => CandidateInterviewOutcome::Selected,
        'interview_notes' => 'Prep for panel A',
        'interview_feedback' => 'Kept feedback',
        'external_interviewer_name' => 'Panel Lead',
        'interview_location' => 'Room 2',
        'lock_version' => 1,
        'created_by' => $this->recruiterA->id,
        'updated_by' => $this->recruiterA->id,
    ]);

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/candidates/{$candidate->id}/correct-stage", [
            'to_stage' => 'screening',
            'reason' => 'Selection was premature',
            'lock_version' => 1,
            'expected_stage' => 'interview',
            'expected_outcome' => 'selected',
        ])
        ->assertRedirect();

    $candidate->refresh();
    expect($candidate->stage)->toBe(CandidateStage::Screening)
        ->and($candidate->interview_outcome)->toBeNull()
        ->and($candidate->interview_feedback)->toBeNull()
        ->and($candidate->interview_notes)->toBeNull()
        ->and($candidate->external_interviewer_name)->toBeNull()
        ->and($candidate->interview_location)->toBeNull();

    $transition = RecruitmentCandidateStageTransition::query()
        ->where('recruitment_candidate_id', $candidate->id)
        ->latest('id')
        ->first();

    expect($transition->context['cleared_selection'] ?? false)->toBeTrue()
        ->and($transition->context['cleared_interview_outcome'] ?? null)->toBe('selected')
        ->and($transition->context['interview_cycle_snapshot']['interview_feedback'] ?? null)->toBe('Kept feedback')
        ->and($transition->context['interview_cycle_snapshot']['interview_notes'] ?? null)->toBe('Prep for panel A')
        ->and($transition->context['interview_cycle_snapshot']['external_interviewer_name'] ?? null)->toBe('Panel Lead');
});

test('interview can correct directly to applied with mandatory reason', function () {
    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'Jump Back',
        'email' => 'jump-back@example.com',
        'email_normalized' => 'jump-back@example.com',
        'stage' => CandidateStage::Interview,
        'lock_version' => 0,
        'created_by' => $this->recruiterA->id,
        'updated_by' => $this->recruiterA->id,
    ]);

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/candidates/{$candidate->id}/correct-stage", [
            'to_stage' => 'applied',
            'reason' => 'ab',
            'lock_version' => 0,
            'expected_stage' => 'interview',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('reason');

    $this->post("/organization/recruitment/candidates/{$candidate->id}/correct-stage", [
        'to_stage' => 'applied',
        'reason' => 'Misrouted into interview',
        'lock_version' => 0,
        'expected_stage' => 'interview',
    ])->assertRedirect();

    expect($candidate->fresh()->stage)->toBe(CandidateStage::Applied);
});

test('stage correction rejects missing permissions cross-company stale locks and offer conflicts', function () {
    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'Guard Correct',
        'email' => 'guard-correct@example.com',
        'email_normalized' => 'guard-correct@example.com',
        'stage' => CandidateStage::Screening,
        'lock_version' => 4,
        'created_by' => $this->recruiterA->id,
        'updated_by' => $this->recruiterA->id,
    ]);

    $this->actingAs($this->viewerOnly)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/candidates/{$candidate->id}/correct-stage", [
            'to_stage' => 'applied',
            'reason' => 'Should fail',
            'lock_version' => 4,
            'expected_stage' => 'screening',
        ])
        ->assertForbidden();

    $this->actingAs($this->recruiterB)
        ->withSession(['current_company_id' => $this->companyB->id])
        ->post("/organization/recruitment/candidates/{$candidate->id}/correct-stage", [
            'to_stage' => 'applied',
            'reason' => 'Cross company',
            'lock_version' => 4,
            'expected_stage' => 'screening',
        ])
        ->assertNotFound();

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/candidates/{$candidate->id}/correct-stage", [
            'to_stage' => 'applied',
            'reason' => 'Stale lock',
            'lock_version' => 1,
            'expected_stage' => 'screening',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('lock_version');

    $this->post("/organization/recruitment/candidates/{$candidate->id}/correct-stage", [
        'to_stage' => 'interview',
        'reason' => 'Illegal skip forward',
        'lock_version' => 4,
        'expected_stage' => 'screening',
    ])
        ->assertRedirect()
        ->assertSessionHasErrors('to_stage');

    expect($candidate->fresh()->stage)->toBe(CandidateStage::Screening);

    $offerCandidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'Offer Block',
        'email' => 'offer-block@example.com',
        'email_normalized' => 'offer-block@example.com',
        'stage' => CandidateStage::Interview,
        'interview_outcome' => CandidateInterviewOutcome::Selected,
        'lock_version' => 0,
        'created_by' => $this->recruiterA->id,
        'updated_by' => $this->recruiterA->id,
    ]);

    RecruitmentCandidateOffer::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_candidate_id' => $offerCandidate->id,
        'revision_number' => 1,
        'is_current' => true,
        'status' => CandidateOfferStatus::Draft,
        'salary_amount' => '5000',
        'salary_currency_code' => 'AED',
        'offer_date' => now()->toDateString(),
        'proposed_joining_date' => now()->addDays(20)->toDateString(),
        'lock_version' => 0,
    ]);

    $this->post("/organization/recruitment/candidates/{$offerCandidate->id}/correct-stage", [
        'to_stage' => 'screening',
        'reason' => 'Should stay blocked by offer',
        'lock_version' => 0,
        'expected_stage' => 'interview',
    ])
        ->assertRedirect()
        ->assertSessionHasErrors('to_stage');

    expect($offerCandidate->fresh()->stage)->toBe(CandidateStage::Interview);
});

test('screening to interview move can set interview details in one transaction', function () {
    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'Move Interview Pack',
        'email' => 'move-interview-pack@example.com',
        'email_normalized' => 'move-interview-pack@example.com',
        'stage' => CandidateStage::Screening,
        'lock_version' => 0,
        'created_by' => $this->recruiterA->id,
        'updated_by' => $this->recruiterA->id,
    ]);

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/candidates/{$candidate->id}/move", [
            'lock_version' => 0,
            'expected_stage' => 'screening',
            'reason' => 'Ready for panel',
            'interview_scheduled_at' => now()->addDays(2)->format('Y-m-d\TH:i'),
            'interview_mode' => 'video',
            'external_interviewer_name' => 'External Panel',
            'interview_location' => 'Zoom room A',
            'interview_notes' => 'Pre-brief notes',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $candidate->refresh();
    expect($candidate->stage)->toBe(CandidateStage::Interview)
        ->and($candidate->interview_mode?->value)->toBe('video')
        ->and($candidate->external_interviewer_name)->toBe('External Panel')
        ->and($candidate->interview_location)->toBe('Zoom room A')
        ->and($candidate->interview_notes)->toBe('Pre-brief notes')
        ->and($candidate->interview_feedback)->toBeNull()
        ->and($candidate->interview_scheduled_at)->not->toBeNull();

    $actions = RecruitmentCandidateStageTransition::query()
        ->where('recruitment_candidate_id', $candidate->id)
        ->orderBy('id')
        ->pluck('action')
        ->map(fn ($action) => $action->value)
        ->all();

    expect($actions)->toContain('moved_to_interview')
        ->and($actions)->toContain('interview_updated');
});

test('kanban index exposes server move destinations for drag and menu parity', function () {
    $screening = RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'Dest Screening',
        'email' => 'dest-screening@example.com',
        'email_normalized' => 'dest-screening@example.com',
        'stage' => CandidateStage::Screening,
        'lock_version' => 0,
        'created_by' => $this->recruiterA->id,
        'updated_by' => $this->recruiterA->id,
    ]);

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get('/organization/recruitment/candidates?view=kanban')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('kanban.screening.data', 1)
            ->where('kanban.screening.data.0.id', $screening->id)
            ->where('kanban.screening.data.0.can_correct_stage', true)
            ->where('kanban.screening.data.0.move_destinations', function ($destinations) {
                $kinds = collect($destinations)->pluck('kind')->all();
                $stages = collect($destinations)->pluck('stage')->all();

                return in_array('forward', $kinds, true)
                    && in_array('backward', $kinds, true)
                    && in_array('interview', $stages, true)
                    && in_array('applied', $stages, true);
            })
        );
});

test('candidate detail exposes move destinations and accepts interviewer on screening to interview move', function () {
    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'Detail Move',
        'email' => 'detail-move@example.com',
        'email_normalized' => 'detail-move@example.com',
        'stage' => CandidateStage::Screening,
        'lock_version' => 0,
        'created_by' => $this->recruiterA->id,
        'updated_by' => $this->recruiterA->id,
    ]);

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get("/organization/recruitment/candidates/{$candidate->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('candidate.id', $candidate->id)
            ->where('candidate.can_move_forward', true)
            ->where('candidate.move_destinations', function ($destinations) {
                $kinds = collect($destinations)->pluck('kind')->all();

                return in_array('forward', $kinds, true)
                    && in_array('backward', $kinds, true);
            })
            ->has('options.interviewers')
            ->has('options.interview_modes')
        );

    $this->from("/organization/recruitment/candidates/{$candidate->id}")
        ->post("/organization/recruitment/candidates/{$candidate->id}/move", [
            'lock_version' => 0,
            'expected_stage' => 'screening',
            'interview_mode' => 'video',
            'interviewer_user_id' => $this->recruiterA->id,
            'interview_location' => 'Teams',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $candidate->refresh();
    expect($candidate->stage)->toBe(CandidateStage::Interview)
        ->and($candidate->interview_mode?->value)->toBe('video')
        ->and($candidate->interviewer_user_id)->toBe($this->recruiterA->id)
        ->and($candidate->interview_location)->toBe('Teams');

    $this->from("/organization/recruitment/candidates/{$candidate->id}")
        ->post("/organization/recruitment/candidates/{$candidate->id}/correct-stage", [
            'to_stage' => 'screening',
            'reason' => 'Detail page correction',
            'lock_version' => $candidate->lock_version,
            'expected_stage' => 'interview',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $candidate->refresh();
    expect($candidate->stage)->toBe(CandidateStage::Screening)
        ->and($candidate->interview_mode)->toBeNull()
        ->and($candidate->interviewer_user_id)->toBeNull()
        ->and($candidate->interview_location)->toBeNull();
});

test('screening to interview re-entry starts a clean cycle without stale prior fields', function () {
    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'Reentry Cycle',
        'email' => 'reentry-cycle@example.com',
        'email_normalized' => 'reentry-cycle@example.com',
        'stage' => CandidateStage::Interview,
        'interview_mode' => 'in_person',
        'external_interviewer_name' => 'First Panel',
        'interview_location' => 'HQ Room 1',
        'interview_notes' => 'First cycle prep',
        'interview_feedback' => 'First cycle feedback',
        'lock_version' => 2,
        'created_by' => $this->recruiterA->id,
        'updated_by' => $this->recruiterA->id,
    ]);

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/candidates/{$candidate->id}/correct-stage", [
            'to_stage' => 'screening',
            'reason' => 'Wrong interview schedule',
            'lock_version' => 2,
            'expected_stage' => 'interview',
        ])
        ->assertRedirect();

    $candidate->refresh();
    expect($candidate->stage)->toBe(CandidateStage::Screening)
        ->and($candidate->interview_notes)->toBeNull()
        ->and($candidate->interview_feedback)->toBeNull();

    $this->post("/organization/recruitment/candidates/{$candidate->id}/move", [
        'lock_version' => $candidate->lock_version,
        'expected_stage' => 'screening',
        'interview_mode' => 'video',
        'external_interviewer_name' => 'Second Panel',
        'interview_location' => 'Zoom B',
        'interview_notes' => 'Second cycle prep only',
    ])->assertRedirect()->assertSessionHas('success');

    $candidate->refresh();
    expect($candidate->stage)->toBe(CandidateStage::Interview)
        ->and($candidate->interview_mode?->value)->toBe('video')
        ->and($candidate->external_interviewer_name)->toBe('Second Panel')
        ->and($candidate->interview_location)->toBe('Zoom B')
        ->and($candidate->interview_notes)->toBe('Second cycle prep only')
        ->and($candidate->interview_feedback)->toBeNull();

    $correction = RecruitmentCandidateStageTransition::query()
        ->where('recruitment_candidate_id', $candidate->id)
        ->where('action', CandidateTransitionAction::StageCorrected)
        ->latest('id')
        ->first();

    expect($correction->context['interview_cycle_snapshot']['interview_feedback'] ?? null)->toBe('First cycle feedback')
        ->and($correction->context['interview_cycle_snapshot']['external_interviewer_name'] ?? null)->toBe('First Panel');
});

test('interview re-entry with omitted optional fields does not revive prior cycle values', function () {
    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'Omit Reentry',
        'email' => 'omit-reentry@example.com',
        'email_normalized' => 'omit-reentry@example.com',
        'stage' => CandidateStage::Screening,
        'lock_version' => 0,
        'created_by' => $this->recruiterA->id,
        'updated_by' => $this->recruiterA->id,
    ]);

    // Simulate residual stale active fields that should not merge on re-entry.
    $candidate->forceFill([
        'interview_location' => 'Stale location',
        'interview_notes' => 'Stale notes',
        'interview_feedback' => 'Stale feedback',
        'external_interviewer_name' => 'Stale interviewer',
    ])->save();

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/candidates/{$candidate->id}/move", [
            'lock_version' => 0,
            'expected_stage' => 'screening',
            'interview_mode' => 'phone',
        ])
        ->assertRedirect();

    $candidate->refresh();
    expect($candidate->stage)->toBe(CandidateStage::Interview)
        ->and($candidate->interview_mode?->value)->toBe('phone')
        ->and($candidate->interview_location)->toBeNull()
        ->and($candidate->interview_notes)->toBeNull()
        ->and($candidate->interview_feedback)->toBeNull()
        ->and($candidate->external_interviewer_name)->toBeNull();
});

test('selected interview cannot be updated through the routine interview endpoint', function () {
    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'Locked Selected',
        'email' => 'locked-selected@example.com',
        'email_normalized' => 'locked-selected@example.com',
        'stage' => CandidateStage::Interview,
        'interview_outcome' => CandidateInterviewOutcome::Selected,
        'interview_notes' => 'Original prep',
        'interview_feedback' => 'Original feedback',
        'lock_version' => 3,
        'created_by' => $this->recruiterA->id,
        'updated_by' => $this->recruiterA->id,
    ]);

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->from("/organization/recruitment/candidates/{$candidate->id}")
        ->put("/organization/recruitment/candidates/{$candidate->id}/interview", [
            'interview_notes' => 'Hacked notes',
            'interview_feedback' => 'Hacked feedback',
            'lock_version' => 3,
            'expected_stage' => 'interview',
        ])
        ->assertRedirect("/organization/recruitment/candidates/{$candidate->id}")
        ->assertSessionHasErrors('interview');

    $candidate->refresh();
    expect($candidate->interview_notes)->toBe('Original prep')
        ->and($candidate->interview_feedback)->toBe('Original feedback')
        ->and($candidate->lock_version)->toBe(3);
});

test('interview notes and feedback persist separately on update', function () {
    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'Notes Feedback Split',
        'email' => 'notes-feedback@example.com',
        'email_normalized' => 'notes-feedback@example.com',
        'stage' => CandidateStage::Interview,
        'lock_version' => 0,
        'created_by' => $this->recruiterA->id,
        'updated_by' => $this->recruiterA->id,
    ]);

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->put("/organization/recruitment/candidates/{$candidate->id}/interview", [
            'interview_notes' => 'Discuss salary expectations',
            'interview_feedback' => 'Strong culture fit',
            'interview_mode' => 'video',
            'lock_version' => 0,
            'expected_stage' => 'interview',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $candidate->refresh();
    expect($candidate->interview_notes)->toBe('Discuss salary expectations')
        ->and($candidate->interview_feedback)->toBe('Strong culture fit')
        ->and($candidate->interview_mode?->value)->toBe('video');
});

test('move rejects interviewer outside the active company membership', function () {
    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'Bad Interviewer',
        'email' => 'bad-interviewer@example.com',
        'email_normalized' => 'bad-interviewer@example.com',
        'stage' => CandidateStage::Screening,
        'lock_version' => 0,
        'created_by' => $this->recruiterA->id,
        'updated_by' => $this->recruiterA->id,
    ]);

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/candidates/{$candidate->id}/move", [
            'lock_version' => 0,
            'expected_stage' => 'screening',
            'interview_mode' => 'phone',
            'interviewer_user_id' => $this->recruiterB->id,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('interviewer_user_id');

    expect($candidate->fresh()->stage)->toBe(CandidateStage::Screening);
});

test('select candidate accepts optional interview feedback in one transaction', function () {
    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'Select With Feedback',
        'email' => 'select-feedback@example.com',
        'email_normalized' => 'select-feedback@example.com',
        'stage' => CandidateStage::Interview,
        'lock_version' => 1,
        'created_by' => $this->recruiterA->id,
        'updated_by' => $this->recruiterA->id,
    ]);

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/candidates/{$candidate->id}/select", [
            'lock_version' => 1,
            'expected_stage' => 'interview',
            'interview_feedback' => 'Strong final panel recommendation.',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $candidate->refresh();
    expect($candidate->interview_outcome)->toBe(CandidateInterviewOutcome::Selected)
        ->and($candidate->interview_feedback)->toBe('Strong final panel recommendation.')
        ->and($candidate->lock_version)->toBe(2);

    $transition = RecruitmentCandidateStageTransition::query()
        ->where('recruitment_candidate_id', $candidate->id)
        ->where('action', CandidateTransitionAction::Selected)
        ->latest('id')
        ->first();

    expect($transition)->not->toBeNull()
        ->and($transition->context['interview_feedback']['from'] ?? null)->toBeNull()
        ->and($transition->context['interview_feedback']['to'] ?? null)->toBe('Strong final panel recommendation.');
});

test('select candidate without feedback leaves existing feedback intact', function () {
    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'Select Preserve Feedback',
        'email' => 'select-preserve@example.com',
        'email_normalized' => 'select-preserve@example.com',
        'stage' => CandidateStage::Interview,
        'interview_feedback' => 'Earlier panel notes',
        'lock_version' => 0,
        'created_by' => $this->recruiterA->id,
        'updated_by' => $this->recruiterA->id,
    ]);

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/candidates/{$candidate->id}/select", [
            'lock_version' => 0,
            'expected_stage' => 'interview',
            'interview_feedback' => '',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $candidate->refresh();
    expect($candidate->interview_outcome)->toBe(CandidateInterviewOutcome::Selected)
        ->and($candidate->interview_feedback)->toBe('Earlier panel notes');

    $transition = RecruitmentCandidateStageTransition::query()
        ->where('recruitment_candidate_id', $candidate->id)
        ->where('action', CandidateTransitionAction::Selected)
        ->latest('id')
        ->first();

    expect($transition->context)->toBeNull();
});

test('select candidate without optional feedback field still selects', function () {
    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'Select No Feedback Field',
        'email' => 'select-none@example.com',
        'email_normalized' => 'select-none@example.com',
        'stage' => CandidateStage::Interview,
        'lock_version' => 0,
        'created_by' => $this->recruiterA->id,
        'updated_by' => $this->recruiterA->id,
    ]);

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/candidates/{$candidate->id}/select", [
            'lock_version' => 0,
            'expected_stage' => 'interview',
        ])
        ->assertRedirect();

    $candidate->refresh();
    expect($candidate->interview_outcome)->toBe(CandidateInterviewOutcome::Selected)
        ->and($candidate->interview_feedback)->toBeNull();
});

test('unauthorized user cannot select candidate', function () {
    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'Select Forbidden',
        'email' => 'select-forbidden@example.com',
        'email_normalized' => 'select-forbidden@example.com',
        'stage' => CandidateStage::Interview,
        'lock_version' => 0,
        'created_by' => $this->recruiterA->id,
        'updated_by' => $this->recruiterA->id,
    ]);

    $this->actingAs($this->viewerOnly)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/candidates/{$candidate->id}/select", [
            'lock_version' => 0,
            'expected_stage' => 'interview',
        ])
        ->assertForbidden();

    $this->actingAs($this->recruiterB)
        ->withSession(['current_company_id' => $this->companyB->id])
        ->post("/organization/recruitment/candidates/{$candidate->id}/select", [
            'lock_version' => 0,
            'expected_stage' => 'interview',
        ])
        ->assertNotFound();

    expect($candidate->fresh()->interview_outcome)->toBeNull();
});

test('select candidate rejects stale lock versions', function () {
    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'Select Stale',
        'email' => 'select-stale@example.com',
        'email_normalized' => 'select-stale@example.com',
        'stage' => CandidateStage::Interview,
        'lock_version' => 4,
        'created_by' => $this->recruiterA->id,
        'updated_by' => $this->recruiterA->id,
    ]);

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/candidates/{$candidate->id}/select", [
            'lock_version' => 2,
            'expected_stage' => 'interview',
            'interview_feedback' => 'Should not save',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('lock_version');

    $candidate->refresh();
    expect($candidate->interview_outcome)->toBeNull()
        ->and($candidate->interview_feedback)->toBeNull()
        ->and($candidate->lock_version)->toBe(4);
});

test('movement history presents sanitized interview cycle snapshots', function () {
    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'History Snapshot',
        'email' => 'history-snapshot@example.com',
        'email_normalized' => 'history-snapshot@example.com',
        'stage' => CandidateStage::Interview,
        'interview_outcome' => CandidateInterviewOutcome::Selected,
        'interview_notes' => 'Prep notes',
        'interview_feedback' => 'Cycle feedback',
        'interviewer_user_id' => $this->recruiterA->id,
        'external_interviewer_name' => 'Panel Lead',
        'interview_mode' => 'video',
        'lock_version' => 1,
        'created_by' => $this->recruiterA->id,
        'updated_by' => $this->recruiterA->id,
    ]);

    $this->actingAs($this->managerA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/candidates/{$candidate->id}/correct-stage", [
            'to_stage' => 'screening',
            'reason' => 'Restart interview cycle',
            'lock_version' => 1,
            'expected_stage' => 'interview',
            'expected_outcome' => 'selected',
        ])
        ->assertRedirect();

    $stored = RecruitmentCandidateStageTransition::query()
        ->where('recruitment_candidate_id', $candidate->id)
        ->where('action', CandidateTransitionAction::StageCorrected)
        ->latest('id')
        ->first();

    expect($stored->context['interview_cycle_snapshot']['interviewer_user_id'] ?? null)->toBe($this->recruiterA->id)
        ->and($stored->context['interview_cycle_snapshot']['interviewer_user_name'] ?? null)->toBe($this->recruiterA->name);

    $auditor = createCandidateTestUser($this->companyA, [
        'recruitment.candidates.view',
        'audit.view',
    ]);

    $this->actingAs($auditor)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get("/organization/recruitment/candidates/{$candidate->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/recruitment/candidates/show')
            ->has('candidate.movement_history')
            ->where('candidate.movement_history', function ($history) {
                $events = collect($history);
                $correction = $events->firstWhere('action', 'stage_corrected');

                if ($correction === null) {
                    return false;
                }

                $snapshot = $correction['context']['interview_cycle_snapshot'] ?? null;

                return is_array($snapshot)
                    && ($snapshot['interview_feedback'] ?? null) === 'Cycle feedback'
                    && ($snapshot['interview_notes'] ?? null) === 'Prep notes'
                    && ($snapshot['external_interviewer_name'] ?? null) === 'Panel Lead'
                    && ($snapshot['interviewer_user_name'] ?? null) === $this->recruiterA->name
                    && ($snapshot['interview_mode_label'] ?? null) === 'Video'
                    && ! array_key_exists('secret_token', $snapshot);
            })
        );
});

test('movement history resolves legacy interviewer ids company-scoped without leaking foreign users', function () {
    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'requirement_number_snapshot' => $this->requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'Legacy Snapshot',
        'email' => 'legacy-snapshot@example.com',
        'email_normalized' => 'legacy-snapshot@example.com',
        'stage' => CandidateStage::Screening,
        'lock_version' => 2,
        'created_by' => $this->recruiterA->id,
        'updated_by' => $this->recruiterA->id,
    ]);

    RecruitmentCandidateStageTransition::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_candidate_id' => $candidate->id,
        'action' => CandidateTransitionAction::StageCorrected,
        'from_stage' => CandidateStage::Interview,
        'to_stage' => CandidateStage::Screening,
        'from_outcome' => CandidateInterviewOutcome::Selected,
        'to_outcome' => null,
        'reason' => 'Legacy correction',
        'context' => [
            'correction' => true,
            'interview_cycle_snapshot' => [
                'interview_scheduled_at' => null,
                'interviewer_user_id' => $this->recruiterA->id,
                // Intentionally omit interviewer_user_name to simulate older rows.
                'external_interviewer_name' => null,
                'interview_mode' => 'phone',
                'interview_location' => null,
                'interview_notes' => null,
                'interview_feedback' => 'Legacy feedback',
                'interview_outcome' => 'selected',
            ],
            'prior_interview_cycle_snapshot' => [
                'interviewer_user_id' => $this->recruiterB->id,
                'interview_feedback' => 'Foreign company interviewer',
            ],
        ],
        'performed_by' => $this->recruiterA->id,
    ]);

    $auditor = createCandidateTestUser($this->companyA, [
        'recruitment.candidates.view',
        'audit.view',
    ]);

    $this->actingAs($auditor)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get("/organization/recruitment/candidates/{$candidate->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('candidate.movement_history', function ($history) {
                $events = collect($history);
                $correction = $events->firstWhere('action', 'stage_corrected');
                $snapshot = $correction['context']['interview_cycle_snapshot'] ?? null;
                $prior = $correction['context']['prior_interview_cycle_snapshot'] ?? null;

                return is_array($snapshot)
                    && ($snapshot['interviewer_user_name'] ?? null) === $this->recruiterA->name
                    && is_array($prior)
                    && ($prior['interviewer_user_id'] ?? null) === $this->recruiterB->id
                    && ($prior['interviewer_user_name'] ?? null) === null;
            })
        );
});
