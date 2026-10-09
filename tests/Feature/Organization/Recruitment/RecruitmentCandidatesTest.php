<?php

use App\Enums\Recruitment\CandidateInterviewOutcome;
use App\Enums\Recruitment\CandidateStage;
use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Position;
use App\Models\Project;
use App\Models\RecruitmentCandidate;
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
