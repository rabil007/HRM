<?php

use App\Enums\Recruitment\CandidateInterviewOutcome;
use App\Enums\Recruitment\CandidateOfferStatus;
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
use App\Models\RecruitmentCandidateOffer;
use App\Models\RecruitmentCandidateStageTransition;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementLine;
use App\Models\User;
use App\Support\Recruitment\Candidates\Actions\AcceptCandidateOffer;
use App\Support\Recruitment\Candidates\Actions\PrepareCandidateOffer;
use App\Support\Recruitment\Candidates\Actions\SendCandidateOffer;
use App\Support\Recruitment\Candidates\Actions\UpdateCandidateOffer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function createOfferTestCompany(string $name, string $code): Company
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

function createOfferTestUser(Company $company, array $permissions = []): User
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
function createOfferOpenRequirement(
    Company $company,
    User $requester,
    User $recruiter,
    Position $position,
): array {
    $client = Client::query()->create(['name' => 'Offer Client', 'is_active' => true]);
    $project = Project::query()->create(['title' => 'Offer Project', 'is_active' => true]);
    $project->clients()->sync([$client->id]);

    $requirement = RecruitmentRequirement::query()->create([
        'company_id' => $company->id,
        'requirement_number' => 'REQ-OFFER-0001',
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

function createSelectedInterviewCandidate(
    Company $company,
    RecruitmentRequirement $requirement,
    RecruitmentRequirementLine $line,
    User $actor,
): RecruitmentCandidate {
    return RecruitmentCandidate::query()->create([
        'company_id' => $company->id,
        'recruitment_requirement_id' => $requirement->id,
        'recruitment_requirement_line_id' => $line->id,
        'requirement_number_snapshot' => $requirement->requirement_number,
        'position_title_snapshot' => 'Chief Officer',
        'name' => 'Offer Candidate',
        'email' => 'offer-candidate@example.com',
        'email_normalized' => 'offer-candidate@example.com',
        'stage' => CandidateStage::Interview,
        'interview_outcome' => CandidateInterviewOutcome::Selected,
        'lock_version' => 0,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ]);
}

beforeEach(function () {
    Storage::fake('local');

    $this->companyA = createOfferTestCompany('Offer Alpha', 'OA1');
    $this->companyB = createOfferTestCompany('Offer Beta', 'OB1');

    $base = [
        'recruitment.candidates.view',
        'recruitment.candidates.create',
        'recruitment.candidates.update',
        'recruitment.candidates.move',
        'recruitment.candidates.cv.download',
        'recruitment.candidates.offer.prepare',
        'recruitment.candidates.offer.update',
        'recruitment.candidates.offer.send',
        'recruitment.candidates.offer.decide',
        'recruitment.candidates.offer.download',
    ];

    $this->recruiterA = createOfferTestUser($this->companyA, $base);
    $this->requesterA = createOfferTestUser($this->companyA, ['recruitment.candidates.view']);
    $this->managerA = createOfferTestUser($this->companyA, array_merge($base, [
        'recruitment.candidates.manage',
        'recruitment.candidates.offer.revise',
    ]));
    $this->viewerOnly = createOfferTestUser($this->companyA, ['recruitment.candidates.view']);
    $this->recruiterB = createOfferTestUser($this->companyB, $base);

    $this->positionA = Position::query()->create([
        'company_id' => $this->companyA->id,
        'title' => 'Chief Officer',
        'status' => 'active',
    ]);

    $open = createOfferOpenRequirement(
        $this->companyA,
        $this->requesterA,
        $this->recruiterA,
        $this->positionA,
    );
    $this->requirement = $open['requirement'];
    $this->line = $open['line'];
});

test('selected interview candidate can prepare draft offer and move to offer_jol', function () {
    $candidate = createSelectedInterviewCandidate(
        $this->companyA,
        $this->requirement,
        $this->line,
        $this->recruiterA,
    );

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->from("/organization/recruitment/candidates/{$candidate->id}")
        ->post("/organization/recruitment/candidates/{$candidate->id}/offers", [
            'salary_amount' => '5500.00',
            'salary_currency_code' => 'AED',
            'proposed_joining_date' => now()->addDays(30)->toDateString(),
            'offer_date' => now()->toDateString(),
            'expiry_date' => now()->addDays(14)->toDateString(),
            'notes' => 'Initial package',
            'lock_version' => 0,
            'expected_stage' => 'interview',
            'expected_outcome' => 'selected',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $candidate->refresh();
    $offer = RecruitmentCandidateOffer::query()->where('recruitment_candidate_id', $candidate->id)->first();

    expect($candidate->stage)->toBe(CandidateStage::OfferJol)
        ->and($candidate->interview_outcome)->toBe(CandidateInterviewOutcome::Selected)
        ->and($offer)->not->toBeNull()
        ->and($offer->status)->toBe(CandidateOfferStatus::Draft)
        ->and($offer->is_current)->toBeTrue()
        ->and((string) $offer->salary_amount)->toBe('5500.00')
        ->and($this->line->fresh()->salary_min)->toBe('4000.00');
});

test('full offer flow draft to sent to accepted moves candidate to joining without email side effects', function () {
    $candidate = createSelectedInterviewCandidate(
        $this->companyA,
        $this->requirement,
        $this->line,
        $this->recruiterA,
    );

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/candidates/{$candidate->id}/offers", [
            'salary_amount' => '5200',
            'salary_currency_code' => 'AED',
            'proposed_joining_date' => now()->addMonth()->toDateString(),
            'offer_date' => now()->toDateString(),
            'lock_version' => 0,
            'expected_stage' => 'interview',
            'expected_outcome' => 'selected',
        ])
        ->assertRedirect();

    $offer = RecruitmentCandidateOffer::query()->firstOrFail();
    $candidate->refresh();

    $this->post("/organization/recruitment/candidates/{$candidate->id}/offers/{$offer->id}/send", [
        'lock_version' => $candidate->lock_version,
        'offer_lock_version' => $offer->lock_version,
        'expected_stage' => 'offer_jol',
        'expected_offer_status' => 'draft',
    ])->assertRedirect()->assertSessionHas('success');

    $offer->refresh();
    $candidate->refresh();
    expect($offer->status)->toBe(CandidateOfferStatus::Sent)
        ->and($offer->sent_by)->toBe($this->recruiterA->id)
        ->and($candidate->stage)->toBe(CandidateStage::OfferJol);

    $this->post("/organization/recruitment/candidates/{$candidate->id}/offers/{$offer->id}/accept", [
        'lock_version' => $candidate->lock_version,
        'offer_lock_version' => $offer->lock_version,
        'expected_stage' => 'offer_jol',
        'expected_offer_status' => 'sent',
    ])->assertRedirect();

    $offer->refresh();
    $candidate->refresh();
    expect($offer->status)->toBe(CandidateOfferStatus::Accepted)
        ->and($candidate->stage)->toBe(CandidateStage::Joining)
        ->and($candidate->interview_outcome)->toBe(CandidateInterviewOutcome::Selected);
});

test('offer rejection moves candidate to rejected without setting interview not_selected', function () {
    $candidate = createSelectedInterviewCandidate(
        $this->companyA,
        $this->requirement,
        $this->line,
        $this->recruiterA,
    );

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/candidates/{$candidate->id}/offers", [
            'salary_amount' => '5000',
            'salary_currency_code' => 'AED',
            'proposed_joining_date' => now()->addMonth()->toDateString(),
            'offer_date' => now()->toDateString(),
            'lock_version' => 0,
            'expected_stage' => 'interview',
            'expected_outcome' => 'selected',
        ]);

    $offer = RecruitmentCandidateOffer::query()->firstOrFail();
    $candidate->refresh();

    $this->post("/organization/recruitment/candidates/{$candidate->id}/offers/{$offer->id}/send", [
        'lock_version' => $candidate->lock_version,
        'offer_lock_version' => $offer->lock_version,
        'expected_stage' => 'offer_jol',
        'expected_offer_status' => 'draft',
    ]);

    $offer->refresh();
    $candidate->refresh();

    $this->post("/organization/recruitment/candidates/{$candidate->id}/offers/{$offer->id}/reject", [
        'reason' => 'Candidate declined package',
        'rejected_at' => now()->toDateString(),
        'lock_version' => $candidate->lock_version,
        'offer_lock_version' => $offer->lock_version,
        'expected_stage' => 'offer_jol',
        'expected_offer_status' => 'sent',
    ])->assertRedirect();

    $offer->refresh();
    $candidate->refresh();

    expect($offer->status)->toBe(CandidateOfferStatus::Rejected)
        ->and($offer->rejection_reason)->toBe('Candidate declined package')
        ->and($candidate->stage)->toBe(CandidateStage::Rejected)
        ->and($candidate->pre_rejection_stage)->toBe('offer_jol')
        ->and($candidate->interview_outcome)->toBe(CandidateInterviewOutcome::Selected);
});

test('sent offers cannot be silently overwritten and revise creates audited draft', function () {
    $candidate = createSelectedInterviewCandidate(
        $this->companyA,
        $this->requirement,
        $this->line,
        $this->recruiterA,
    );

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/candidates/{$candidate->id}/offers", [
            'salary_amount' => '5000',
            'salary_currency_code' => 'AED',
            'proposed_joining_date' => now()->addMonth()->toDateString(),
            'offer_date' => now()->toDateString(),
            'lock_version' => 0,
            'expected_stage' => 'interview',
            'expected_outcome' => 'selected',
        ]);

    $offer = RecruitmentCandidateOffer::query()->firstOrFail();
    $candidate->refresh();

    $this->post("/organization/recruitment/candidates/{$candidate->id}/offers/{$offer->id}/send", [
        'lock_version' => $candidate->lock_version,
        'offer_lock_version' => $offer->lock_version,
        'expected_stage' => 'offer_jol',
        'expected_offer_status' => 'draft',
    ]);

    $offer->refresh();
    $candidate->refresh();

    $this->post("/organization/recruitment/candidates/{$candidate->id}/offers/{$offer->id}", [
        'salary_amount' => '7000',
        'salary_currency_code' => 'AED',
        'proposed_joining_date' => now()->addMonth()->toDateString(),
        'offer_date' => now()->toDateString(),
        'lock_version' => $candidate->lock_version,
        'offer_lock_version' => $offer->lock_version,
        'expected_stage' => 'offer_jol',
        'expected_offer_status' => 'sent',
    ])->assertRedirect()->assertSessionHasErrors('offer_status');

    $this->actingAs($this->managerA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/candidates/{$candidate->id}/offers/{$offer->id}/revise", [
            'reason' => 'Correct salary after client change',
            'salary_amount' => '7000',
            'salary_currency_code' => 'AED',
            'proposed_joining_date' => now()->addMonth()->toDateString(),
            'offer_date' => now()->toDateString(),
            'lock_version' => $candidate->fresh()->lock_version,
            'offer_lock_version' => $offer->fresh()->lock_version,
            'expected_stage' => 'offer_jol',
            'expected_offer_status' => 'sent',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $offer->refresh();
    $newOffer = RecruitmentCandidateOffer::query()->where('is_current', true)->first();

    expect($offer->is_current)->toBeFalse()
        ->and($offer->status)->toBe(CandidateOfferStatus::Sent)
        ->and($newOffer)->not->toBeNull()
        ->and($newOffer->status)->toBe(CandidateOfferStatus::Draft)
        ->and($newOffer->revision_number)->toBe(2)
        ->and($newOffer->supersedes_offer_id)->toBe($offer->id)
        ->and((string) $newOffer->salary_amount)->toBe('7000.00');
});

test('undo selected is blocked after offer preparation and reopen restores offer_jol with selected outcome', function () {
    $candidate = createSelectedInterviewCandidate(
        $this->companyA,
        $this->requirement,
        $this->line,
        $this->recruiterA,
    );

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/candidates/{$candidate->id}/offers", [
            'salary_amount' => '5100',
            'salary_currency_code' => 'AED',
            'proposed_joining_date' => now()->addMonth()->toDateString(),
            'offer_date' => now()->toDateString(),
            'lock_version' => 0,
            'expected_stage' => 'interview',
            'expected_outcome' => 'selected',
        ]);

    $candidate->refresh();
    $this->post("/organization/recruitment/candidates/{$candidate->id}/undo-select", [
        'lock_version' => $candidate->lock_version,
        'expected_stage' => 'offer_jol',
        'expected_outcome' => 'selected',
    ])->assertRedirect()->assertSessionHasErrors();

    $offer = RecruitmentCandidateOffer::query()->firstOrFail();
    $this->post("/organization/recruitment/candidates/{$candidate->id}/offers/{$offer->id}/send", [
        'lock_version' => $candidate->fresh()->lock_version,
        'offer_lock_version' => $offer->lock_version,
        'expected_stage' => 'offer_jol',
        'expected_offer_status' => 'draft',
    ]);
    $candidate->refresh();
    $offer->refresh();
    $this->post("/organization/recruitment/candidates/{$candidate->id}/offers/{$offer->id}/reject", [
        'reason' => 'Declined',
        'lock_version' => $candidate->lock_version,
        'offer_lock_version' => $offer->lock_version,
        'expected_stage' => 'offer_jol',
        'expected_offer_status' => 'sent',
    ]);

    $candidate->refresh();
    $this->actingAs($this->managerA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/candidates/{$candidate->id}/reopen", [
            'reason' => 'Reopen after offer decline',
            'lock_version' => $candidate->lock_version,
            'expected_stage' => 'rejected',
        ])
        ->assertRedirect();

    $candidate->refresh();
    $currentOffer = RecruitmentCandidateOffer::query()->where('is_current', true)->first();

    expect($candidate->stage)->toBe(CandidateStage::OfferJol)
        ->and($candidate->interview_outcome)->toBe(CandidateInterviewOutcome::Selected)
        ->and($currentOffer)->not->toBeNull()
        ->and($currentOffer->status)->toBe(CandidateOfferStatus::Rejected);
});

test('offer actions require permissions ownership open parents and company scope', function () {
    $candidate = createSelectedInterviewCandidate(
        $this->companyA,
        $this->requirement,
        $this->line,
        $this->recruiterA,
    );

    $this->actingAs($this->viewerOnly)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/candidates/{$candidate->id}/offers", [
            'salary_amount' => '5000',
            'salary_currency_code' => 'AED',
            'proposed_joining_date' => now()->addMonth()->toDateString(),
            'offer_date' => now()->toDateString(),
            'lock_version' => 0,
            'expected_stage' => 'interview',
            'expected_outcome' => 'selected',
        ])
        ->assertForbidden();

    $this->actingAs($this->recruiterB)
        ->withSession(['current_company_id' => $this->companyB->id])
        ->post("/organization/recruitment/candidates/{$candidate->id}/offers", [
            'salary_amount' => '5000',
            'salary_currency_code' => 'AED',
            'proposed_joining_date' => now()->addMonth()->toDateString(),
            'offer_date' => now()->toDateString(),
            'lock_version' => 0,
            'expected_stage' => 'interview',
            'expected_outcome' => 'selected',
        ])
        ->assertNotFound();

    $this->requirement->update(['assigned_to' => $this->viewerOnly->id]);

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/candidates/{$candidate->id}/offers", [
            'salary_amount' => '5000',
            'salary_currency_code' => 'AED',
            'proposed_joining_date' => now()->addMonth()->toDateString(),
            'offer_date' => now()->toDateString(),
            'lock_version' => 0,
            'expected_stage' => 'interview',
            'expected_outcome' => 'selected',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('candidate');

    $this->requirement->update(['assigned_to' => $this->recruiterA->id, 'status' => RequirementStatus::OnHold]);

    $this->post("/organization/recruitment/candidates/{$candidate->id}/offers", [
        'salary_amount' => '5000',
        'salary_currency_code' => 'AED',
        'proposed_joining_date' => now()->addMonth()->toDateString(),
        'offer_date' => now()->toDateString(),
        'lock_version' => 0,
        'expected_stage' => 'interview',
        'expected_outcome' => 'selected',
    ])->assertRedirect()->assertSessionHasErrors('candidate');
});

test('stale lock and duplicate prepare are rejected', function () {
    $candidate = createSelectedInterviewCandidate(
        $this->companyA,
        $this->requirement,
        $this->line,
        $this->recruiterA,
    );

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/candidates/{$candidate->id}/offers", [
            'salary_amount' => '5000',
            'salary_currency_code' => 'AED',
            'proposed_joining_date' => now()->addMonth()->toDateString(),
            'offer_date' => now()->toDateString(),
            'lock_version' => 99,
            'expected_stage' => 'interview',
            'expected_outcome' => 'selected',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('lock_version');

    $this->post("/organization/recruitment/candidates/{$candidate->id}/offers", [
        'salary_amount' => '5000',
        'salary_currency_code' => 'AED',
        'proposed_joining_date' => now()->addMonth()->toDateString(),
        'offer_date' => now()->toDateString(),
        'lock_version' => 0,
        'expected_stage' => 'interview',
        'expected_outcome' => 'selected',
    ])->assertRedirect();

    $candidate->refresh();
    $this->post("/organization/recruitment/candidates/{$candidate->id}/offers", [
        'salary_amount' => '5100',
        'salary_currency_code' => 'AED',
        'proposed_joining_date' => now()->addMonth()->toDateString(),
        'offer_date' => now()->toDateString(),
        'lock_version' => $candidate->lock_version,
        'expected_stage' => 'offer_jol',
        'expected_outcome' => 'selected',
    ])->assertRedirect()->assertSessionHasErrors();

    expect(RecruitmentCandidateOffer::query()->count())->toBe(1);
});

test('private offer documents download with permission and stay company scoped', function () {
    $candidate = createSelectedInterviewCandidate(
        $this->companyA,
        $this->requirement,
        $this->line,
        $this->recruiterA,
    );

    $file = UploadedFile::fake()->create('offer.pdf', 100, 'application/pdf');

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/candidates/{$candidate->id}/offers", [
            'salary_amount' => '5000',
            'salary_currency_code' => 'AED',
            'proposed_joining_date' => now()->addMonth()->toDateString(),
            'offer_date' => now()->toDateString(),
            'offer_document' => $file,
            'lock_version' => 0,
            'expected_stage' => 'interview',
            'expected_outcome' => 'selected',
        ])
        ->assertRedirect();

    $offer = RecruitmentCandidateOffer::query()->firstOrFail();

    $this->get("/organization/recruitment/candidates/{$candidate->id}/offers/{$offer->id}/documents/offer")
        ->assertOk();

    $this->actingAs($this->viewerOnly)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get("/organization/recruitment/candidates/{$candidate->id}/offers/{$offer->id}/documents/offer")
        ->assertForbidden();

    $this->actingAs($this->recruiterB)
        ->withSession(['current_company_id' => $this->companyB->id])
        ->get("/organization/recruitment/candidates/{$candidate->id}/offers/{$offer->id}/documents/offer")
        ->assertNotFound();
});

test('kanban includes offer_jol and joining stages and through_page refresh after offer action', function () {
    foreach (range(1, 16) as $index) {
        RecruitmentCandidate::query()->create([
            'company_id' => $this->companyA->id,
            'recruitment_requirement_id' => $this->requirement->id,
            'recruitment_requirement_line_id' => $this->line->id,
            'requirement_number_snapshot' => $this->requirement->requirement_number,
            'position_title_snapshot' => 'Chief Officer',
            'name' => "Board {$index}",
            'email' => "board{$index}@example.com",
            'email_normalized' => "board{$index}@example.com",
            'stage' => CandidateStage::Interview,
            'interview_outcome' => CandidateInterviewOutcome::Selected,
            'lock_version' => 0,
            'created_by' => $this->recruiterA->id,
            'updated_by' => $this->recruiterA->id,
        ]);
    }

    $selected = RecruitmentCandidate::query()->orderByDesc('id')->first();

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get('/organization/recruitment/candidates?view=kanban')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('kanban.offer_jol')
            ->has('kanban.joining')
            ->where('stage_totals.interview', 16)
        );

    $this->post("/organization/recruitment/candidates/{$selected->id}/offers", [
        'salary_amount' => '5000',
        'salary_currency_code' => 'AED',
        'proposed_joining_date' => now()->addMonth()->toDateString(),
        'offer_date' => now()->toDateString(),
        'lock_version' => 0,
        'expected_stage' => 'interview',
        'expected_outcome' => 'selected',
    ])->assertRedirect();

    $this->get('/organization/recruitment/candidates?view=kanban&per_page=15&through_page_interview=2')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('stage_totals.interview', 15)
            ->where('stage_totals.offer_jol', 1)
            ->has('kanban.offer_jol.data', 1)
            ->where('kanban.offer_jol.data.0.id', $selected->id)
        );
});

test('offer validation preserves input and rejects invalid date relationships', function () {
    $candidate = createSelectedInterviewCandidate(
        $this->companyA,
        $this->requirement,
        $this->line,
        $this->recruiterA,
    );

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->from("/organization/recruitment/candidates/{$candidate->id}")
        ->post("/organization/recruitment/candidates/{$candidate->id}/offers", [
            'salary_amount' => '0',
            'salary_currency_code' => 'AED',
            'proposed_joining_date' => now()->toDateString(),
            'offer_date' => now()->addDay()->toDateString(),
            'lock_version' => 0,
            'expected_stage' => 'interview',
            'expected_outcome' => 'selected',
        ])
        ->assertRedirect("/organization/recruitment/candidates/{$candidate->id}")
        ->assertSessionHasErrors(['salary_amount', 'proposed_joining_date'])
        ->assertSessionHasInput('salary_currency_code', 'AED');
});

test('prepare offer rolls back uploaded document if subsequent transition fails', function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->companyA->id);

    $candidate = createSelectedInterviewCandidate(
        $this->companyA,
        $this->requirement,
        $this->line,
        $this->recruiterA,
    );

    $file = UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf');

    Event::listen('eloquent.creating: '.RecruitmentCandidateStageTransition::class, function () {
        throw new RuntimeException('Simulated stage transition database error');
    });

    expect(fn () => app(PrepareCandidateOffer::class)->handle(
        $this->recruiterA,
        $candidate,
        [
            'salary_amount' => '5000',
            'salary_currency_code' => 'AED',
            'proposed_joining_date' => now()->addMonth()->toDateString(),
            'offer_date' => now()->toDateString(),
            'offer_document' => $file,
            'lock_version' => 0,
            'expected_stage' => 'interview',
            'expected_outcome' => 'selected',
        ],
    ))->toThrow(RuntimeException::class, 'Simulated stage transition database error');

    expect(Storage::disk('local')->allFiles())->toBeEmpty();

    expect(RecruitmentCandidateOffer::query()->count())->toBe(0)
        ->and($candidate->fresh()->stage)->toBe(CandidateStage::Interview);
});

test('update offer rolls back multiple new uploads and preserves original files on transaction failure', function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->companyA->id);

    $candidate = createSelectedInterviewCandidate(
        $this->companyA,
        $this->requirement,
        $this->line,
        $this->recruiterA,
    );

    $originalOfferFile = UploadedFile::fake()->create('original_offer.pdf', 100, 'application/pdf');
    $originalAcceptanceFile = UploadedFile::fake()->create('original_acceptance.pdf', 100, 'application/pdf');

    $offer = app(PrepareCandidateOffer::class)->handle(
        $this->recruiterA,
        $candidate,
        [
            'salary_amount' => '5000',
            'salary_currency_code' => 'AED',
            'proposed_joining_date' => now()->addMonth()->toDateString(),
            'offer_date' => now()->toDateString(),
            'offer_document' => $originalOfferFile,
            'lock_version' => 0,
            'expected_stage' => 'interview',
            'expected_outcome' => 'selected',
        ],
    );

    $offer = app(UpdateCandidateOffer::class)->handle(
        $this->recruiterA,
        $candidate->fresh(),
        $offer,
        [
            'salary_amount' => '5000',
            'salary_currency_code' => 'AED',
            'proposed_joining_date' => now()->addMonth()->toDateString(),
            'offer_date' => now()->toDateString(),
            'acceptance_document' => $originalAcceptanceFile,
            'lock_version' => $candidate->fresh()->lock_version,
            'offer_lock_version' => $offer->lock_version,
        ],
    );

    $origOfferPath = $offer->offer_document_path;
    $origAcceptancePath = $offer->acceptance_document_path;

    expect(Storage::disk('local')->exists($origOfferPath))->toBeTrue()
        ->and(Storage::disk('local')->exists($origAcceptancePath))->toBeTrue();

    $newOfferFile = UploadedFile::fake()->create('new_offer.pdf', 100, 'application/pdf');
    $newAcceptanceFile = UploadedFile::fake()->create('new_acceptance.pdf', 100, 'application/pdf');

    Event::listen('eloquent.saving: '.RecruitmentCandidate::class, function () {
        throw new RuntimeException('Simulated candidate update failure');
    });

    expect(fn () => app(UpdateCandidateOffer::class)->handle(
        $this->recruiterA,
        $candidate->fresh(),
        $offer->fresh(),
        [
            'salary_amount' => '5500',
            'salary_currency_code' => 'AED',
            'proposed_joining_date' => now()->addMonth()->toDateString(),
            'offer_date' => now()->toDateString(),
            'offer_document' => $newOfferFile,
            'acceptance_document' => $newAcceptanceFile,
            'lock_version' => $candidate->fresh()->lock_version,
            'offer_lock_version' => $offer->fresh()->lock_version,
        ],
    ))->toThrow(RuntimeException::class, 'Simulated candidate update failure');

    expect(Storage::disk('local')->exists($origOfferPath))->toBeTrue()
        ->and(Storage::disk('local')->exists($origAcceptancePath))->toBeTrue();

    $allFiles = Storage::disk('local')->allFiles();
    expect($allFiles)->toHaveCount(2)
        ->and($allFiles)->toContain($origOfferPath)
        ->and($allFiles)->toContain($origAcceptancePath);

    $offer->refresh();
    expect($offer->offer_document_path)->toBe($origOfferPath)
        ->and($offer->acceptance_document_path)->toBe($origAcceptancePath)
        ->and((string) $offer->salary_amount)->toBe('5000.00');
});

test('update offer deletes replaced files only after commit on success', function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->companyA->id);

    $candidate = createSelectedInterviewCandidate(
        $this->companyA,
        $this->requirement,
        $this->line,
        $this->recruiterA,
    );

    $originalFile = UploadedFile::fake()->create('old_contract.pdf', 100, 'application/pdf');
    $offer = app(PrepareCandidateOffer::class)->handle(
        $this->recruiterA,
        $candidate,
        [
            'salary_amount' => '5000',
            'salary_currency_code' => 'AED',
            'proposed_joining_date' => now()->addMonth()->toDateString(),
            'offer_date' => now()->toDateString(),
            'offer_document' => $originalFile,
            'lock_version' => 0,
            'expected_stage' => 'interview',
            'expected_outcome' => 'selected',
        ],
    );

    $oldPath = $offer->offer_document_path;
    expect(Storage::disk('local')->exists($oldPath))->toBeTrue();

    $newFile = UploadedFile::fake()->create('new_contract.pdf', 100, 'application/pdf');
    $updatedOffer = app(UpdateCandidateOffer::class)->handle(
        $this->recruiterA,
        $candidate->fresh(),
        $offer->fresh(),
        [
            'salary_amount' => '5000',
            'salary_currency_code' => 'AED',
            'proposed_joining_date' => now()->addMonth()->toDateString(),
            'offer_date' => now()->toDateString(),
            'offer_document' => $newFile,
            'lock_version' => $candidate->fresh()->lock_version,
            'offer_lock_version' => $offer->fresh()->lock_version,
        ],
    );

    expect(Storage::disk('local')->exists($oldPath))->toBeFalse()
        ->and(Storage::disk('local')->exists($updatedOffer->offer_document_path))->toBeTrue()
        ->and($updatedOffer->offer_document_path)->not->toBe($oldPath);
});

test('accept offer cleans up uploaded document on failure and removes replaced document after commit on success', function () {
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->companyA->id);

    $candidate = createSelectedInterviewCandidate(
        $this->companyA,
        $this->requirement,
        $this->line,
        $this->recruiterA,
    );

    $offer = app(PrepareCandidateOffer::class)->handle(
        $this->recruiterA,
        $candidate,
        [
            'salary_amount' => '5000',
            'salary_currency_code' => 'AED',
            'proposed_joining_date' => now()->addMonth()->toDateString(),
            'offer_date' => now()->toDateString(),
            'lock_version' => 0,
            'expected_stage' => 'interview',
            'expected_outcome' => 'selected',
        ],
    );

    $offer = app(SendCandidateOffer::class)->handle(
        $this->recruiterA,
        $candidate->fresh(),
        $offer->fresh(),
        [
            'lock_version' => $candidate->fresh()->lock_version,
            'offer_lock_version' => $offer->fresh()->lock_version,
            'sent_at' => now()->toDateString(),
        ],
    );

    $fileToFail = UploadedFile::fake()->create('signed_fail.pdf', 100, 'application/pdf');

    Event::listen('eloquent.creating: '.RecruitmentCandidateStageTransition::class, function () {
        throw new RuntimeException('Simulated accept stage transition error');
    });

    expect(fn () => app(AcceptCandidateOffer::class)->handle(
        $this->recruiterA,
        $candidate->fresh(),
        $offer->fresh(),
        [
            'lock_version' => $candidate->fresh()->lock_version,
            'offer_lock_version' => $offer->fresh()->lock_version,
            'acceptance_document' => $fileToFail,
            'accepted_at' => now()->toDateString(),
        ],
    ))->toThrow(RuntimeException::class, 'Simulated accept stage transition error');

    expect(Storage::disk('local')->allFiles())->toBeEmpty();

    Event::forget('eloquent.creating: '.RecruitmentCandidateStageTransition::class);

    $fileSuccess = UploadedFile::fake()->create('signed_success.pdf', 100, 'application/pdf');
    $acceptedOffer = app(AcceptCandidateOffer::class)->handle(
        $this->recruiterA,
        $candidate->fresh(),
        $offer->fresh(),
        [
            'lock_version' => $candidate->fresh()->lock_version,
            'offer_lock_version' => $offer->fresh()->lock_version,
            'acceptance_document' => $fileSuccess,
            'accepted_at' => now()->toDateString(),
        ],
    );

    expect(Storage::disk('local')->exists($acceptedOffer->acceptance_document_path))->toBeTrue()
        ->and($acceptedOffer->status)->toBe(CandidateOfferStatus::Accepted);
});

test('offer date validation rejects expiry date before offer date', function () {
    $candidate = createSelectedInterviewCandidate(
        $this->companyA,
        $this->requirement,
        $this->line,
        $this->recruiterA,
    );

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->from("/organization/recruitment/candidates/{$candidate->id}")
        ->post("/organization/recruitment/candidates/{$candidate->id}/offers", [
            'salary_amount' => '5000',
            'salary_currency_code' => 'AED',
            'proposed_joining_date' => now('Asia/Dubai')->addDays(30)->toDateString(),
            'offer_date' => now('Asia/Dubai')->toDateString(),
            'expiry_date' => now('Asia/Dubai')->subDay()->toDateString(),
            'lock_version' => 0,
            'expected_stage' => 'interview',
            'expected_outcome' => 'selected',
        ])
        ->assertRedirect("/organization/recruitment/candidates/{$candidate->id}")
        ->assertSessionHasErrors(['expiry_date']);
});

test('send offer rejects sent_at before offer date and future sent_at', function () {
    $candidate = createSelectedInterviewCandidate(
        $this->companyA,
        $this->requirement,
        $this->line,
        $this->recruiterA,
    );

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id]);

    $this->post("/organization/recruitment/candidates/{$candidate->id}/offers", [
        'salary_amount' => '5000',
        'salary_currency_code' => 'AED',
        'proposed_joining_date' => now('Asia/Dubai')->addMonth()->toDateString(),
        'offer_date' => now('Asia/Dubai')->toDateString(),
        'lock_version' => 0,
        'expected_stage' => 'interview',
        'expected_outcome' => 'selected',
    ]);

    $offer = RecruitmentCandidateOffer::query()->firstOrFail();
    $candidate->refresh();

    // 1. Future sent_at rejected
    $this->from("/organization/recruitment/candidates/{$candidate->id}")
        ->post("/organization/recruitment/candidates/{$candidate->id}/offers/{$offer->id}/send", [
            'lock_version' => $candidate->lock_version,
            'offer_lock_version' => $offer->lock_version,
            'expected_stage' => 'offer_jol',
            'expected_offer_status' => 'draft',
            'sent_at' => now('Asia/Dubai')->addDays(2)->toDateString(),
        ])
        ->assertRedirect("/organization/recruitment/candidates/{$candidate->id}")
        ->assertSessionHasErrors(['sent_at']);

    // 2. Sent at before offer_date rejected
    $this->from("/organization/recruitment/candidates/{$candidate->id}")
        ->post("/organization/recruitment/candidates/{$candidate->id}/offers/{$offer->id}/send", [
            'lock_version' => $candidate->lock_version,
            'offer_lock_version' => $offer->lock_version,
            'expected_stage' => 'offer_jol',
            'expected_offer_status' => 'draft',
            'sent_at' => now('Asia/Dubai')->subDays(2)->toDateString(),
        ])
        ->assertRedirect("/organization/recruitment/candidates/{$candidate->id}")
        ->assertSessionHasErrors(['sent_at']);
});

test('send offer default-to-now fails if offer date is in the future', function () {
    $candidate = createSelectedInterviewCandidate(
        $this->companyA,
        $this->requirement,
        $this->line,
        $this->recruiterA,
    );

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id]);

    $this->post("/organization/recruitment/candidates/{$candidate->id}/offers", [
        'salary_amount' => '5000',
        'salary_currency_code' => 'AED',
        'proposed_joining_date' => now('Asia/Dubai')->addMonth()->toDateString(),
        'offer_date' => now('Asia/Dubai')->addDays(5)->toDateString(),
        'lock_version' => 0,
        'expected_stage' => 'interview',
        'expected_outcome' => 'selected',
    ]);

    $offer = RecruitmentCandidateOffer::query()->firstOrFail();
    $candidate->refresh();

    $this->from("/organization/recruitment/candidates/{$candidate->id}")
        ->post("/organization/recruitment/candidates/{$candidate->id}/offers/{$offer->id}/send", [
            'lock_version' => $candidate->lock_version,
            'offer_lock_version' => $offer->lock_version,
            'expected_stage' => 'offer_jol',
            'expected_offer_status' => 'draft',
        ])
        ->assertRedirect("/organization/recruitment/candidates/{$candidate->id}")
        ->assertSessionHasErrors(['sent_at']);
});

test('accept and reject offer reject dates before sent_at or in the future', function () {
    $candidate = createSelectedInterviewCandidate(
        $this->companyA,
        $this->requirement,
        $this->line,
        $this->recruiterA,
    );

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id]);

    $this->post("/organization/recruitment/candidates/{$candidate->id}/offers", [
        'salary_amount' => '5000',
        'salary_currency_code' => 'AED',
        'proposed_joining_date' => now('Asia/Dubai')->addMonth()->toDateString(),
        'offer_date' => now('Asia/Dubai')->subDays(5)->toDateString(),
        'lock_version' => 0,
        'expected_stage' => 'interview',
        'expected_outcome' => 'selected',
    ]);

    $offer = RecruitmentCandidateOffer::query()->firstOrFail();
    $candidate->refresh();

    $this->post("/organization/recruitment/candidates/{$candidate->id}/offers/{$offer->id}/send", [
        'lock_version' => $candidate->lock_version,
        'offer_lock_version' => $offer->lock_version,
        'expected_stage' => 'offer_jol',
        'expected_offer_status' => 'draft',
        'sent_at' => now('Asia/Dubai')->subDays(3)->toDateString(),
    ]);

    $offer->refresh();
    $candidate->refresh();

    $this->from("/organization/recruitment/candidates/{$candidate->id}")
        ->post("/organization/recruitment/candidates/{$candidate->id}/offers/{$offer->id}/accept", [
            'lock_version' => $candidate->lock_version,
            'offer_lock_version' => $offer->lock_version,
            'expected_stage' => 'offer_jol',
            'expected_offer_status' => 'sent',
            'accepted_at' => now('Asia/Dubai')->subDays(4)->toDateString(),
        ])
        ->assertRedirect("/organization/recruitment/candidates/{$candidate->id}")
        ->assertSessionHasErrors(['accepted_at']);

    $this->from("/organization/recruitment/candidates/{$candidate->id}")
        ->post("/organization/recruitment/candidates/{$candidate->id}/offers/{$offer->id}/accept", [
            'lock_version' => $candidate->lock_version,
            'offer_lock_version' => $offer->lock_version,
            'expected_stage' => 'offer_jol',
            'expected_offer_status' => 'sent',
            'accepted_at' => now('Asia/Dubai')->addDay()->toDateString(),
        ])
        ->assertRedirect("/organization/recruitment/candidates/{$candidate->id}")
        ->assertSessionHasErrors(['accepted_at']);

    $this->from("/organization/recruitment/candidates/{$candidate->id}")
        ->post("/organization/recruitment/candidates/{$candidate->id}/offers/{$offer->id}/reject", [
            'reason' => 'Declined package',
            'lock_version' => $candidate->lock_version,
            'offer_lock_version' => $offer->lock_version,
            'expected_stage' => 'offer_jol',
            'expected_offer_status' => 'sent',
            'rejected_at' => now('Asia/Dubai')->subDays(4)->toDateString(),
        ])
        ->assertRedirect("/organization/recruitment/candidates/{$candidate->id}")
        ->assertSessionHasErrors(['rejected_at']);

    $this->from("/organization/recruitment/candidates/{$candidate->id}")
        ->post("/organization/recruitment/candidates/{$candidate->id}/offers/{$offer->id}/reject", [
            'reason' => 'Declined package',
            'lock_version' => $candidate->lock_version,
            'offer_lock_version' => $offer->lock_version,
            'expected_stage' => 'offer_jol',
            'expected_offer_status' => 'sent',
            'rejected_at' => now('Asia/Dubai')->addDay()->toDateString(),
        ])
        ->assertRedirect("/organization/recruitment/candidates/{$candidate->id}")
        ->assertSessionHasErrors(['rejected_at']);
});

test('allows valid historical offer chronology in company timezone', function () {
    $candidate = createSelectedInterviewCandidate(
        $this->companyA,
        $this->requirement,
        $this->line,
        $this->recruiterA,
    );

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id]);

    $histOfferDate = now('Asia/Dubai')->subDays(10)->toDateString();
    $histSentDate = now('Asia/Dubai')->subDays(8)->toDateString();
    $histAcceptedDate = now('Asia/Dubai')->subDays(5)->toDateString();
    $futureJoinDate = now('Asia/Dubai')->addDays(20)->toDateString();

    $this->post("/organization/recruitment/candidates/{$candidate->id}/offers", [
        'salary_amount' => '6000',
        'salary_currency_code' => 'AED',
        'proposed_joining_date' => $futureJoinDate,
        'offer_date' => $histOfferDate,
        'lock_version' => 0,
        'expected_stage' => 'interview',
        'expected_outcome' => 'selected',
    ])->assertRedirect()->assertSessionHas('success');

    $offer = RecruitmentCandidateOffer::query()->firstOrFail();
    $candidate->refresh();

    $this->post("/organization/recruitment/candidates/{$candidate->id}/offers/{$offer->id}/send", [
        'lock_version' => $candidate->lock_version,
        'offer_lock_version' => $offer->lock_version,
        'expected_stage' => 'offer_jol',
        'expected_offer_status' => 'draft',
        'sent_at' => $histSentDate,
    ])->assertRedirect()->assertSessionHas('success');

    $offer->refresh();
    $candidate->refresh();

    $this->post("/organization/recruitment/candidates/{$candidate->id}/offers/{$offer->id}/accept", [
        'lock_version' => $candidate->lock_version,
        'offer_lock_version' => $offer->lock_version,
        'expected_stage' => 'offer_jol',
        'expected_offer_status' => 'sent',
        'accepted_at' => $histAcceptedDate,
    ])->assertRedirect()->assertSessionHas('success');

    $offer->refresh();
    $candidate->refresh();

    expect($offer->status)->toBe(CandidateOfferStatus::Accepted)
        ->and($candidate->stage)->toBe(CandidateStage::Joining)
        ->and($offer->offer_date->toDateString())->toBe($histOfferDate)
        ->and($offer->sent_at->setTimezone('Asia/Dubai')->toDateString())->toBe($histSentDate)
        ->and($offer->accepted_at->setTimezone('Asia/Dubai')->toDateString())->toBe($histAcceptedDate);
});

test('revise offer validates effective dates against inherited fields inside locked transaction', function () {
    $candidate = createSelectedInterviewCandidate(
        $this->companyA,
        $this->requirement,
        $this->line,
        $this->recruiterA,
    );

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id]);

    $this->post("/organization/recruitment/candidates/{$candidate->id}/offers", [
        'salary_amount' => '5000',
        'salary_currency_code' => 'AED',
        'proposed_joining_date' => now('Asia/Dubai')->addDays(10)->toDateString(),
        'offer_date' => now('Asia/Dubai')->toDateString(),
        'expiry_date' => now('Asia/Dubai')->addDays(5)->toDateString(),
        'lock_version' => 0,
        'expected_stage' => 'interview',
        'expected_outcome' => 'selected',
    ]);

    $offer = RecruitmentCandidateOffer::query()->firstOrFail();
    $candidate->refresh();

    $this->post("/organization/recruitment/candidates/{$candidate->id}/offers/{$offer->id}/send", [
        'lock_version' => $candidate->lock_version,
        'offer_lock_version' => $offer->lock_version,
        'expected_stage' => 'offer_jol',
        'expected_offer_status' => 'draft',
    ]);

    $offer->refresh();
    $candidate->refresh();

    $this->actingAs($this->managerA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->from("/organization/recruitment/candidates/{$candidate->id}")
        ->post("/organization/recruitment/candidates/{$candidate->id}/offers/{$offer->id}/revise", [
            'reason' => 'Delay offer release',
            'offer_date' => now('Asia/Dubai')->addDays(7)->toDateString(),
            'lock_version' => $candidate->lock_version,
            'offer_lock_version' => $offer->lock_version,
            'expected_stage' => 'offer_jol',
            'expected_offer_status' => 'sent',
        ])
        ->assertRedirect("/organization/recruitment/candidates/{$candidate->id}")
        ->assertSessionHasErrors(['expiry_date']);

    $this->from("/organization/recruitment/candidates/{$candidate->id}")
        ->post("/organization/recruitment/candidates/{$candidate->id}/offers/{$offer->id}/revise", [
            'reason' => 'Delay offer release beyond joining',
            'offer_date' => now('Asia/Dubai')->addDays(15)->toDateString(),
            'lock_version' => $candidate->lock_version,
            'offer_lock_version' => $offer->lock_version,
            'expected_stage' => 'offer_jol',
            'expected_offer_status' => 'sent',
        ])
        ->assertRedirect("/organization/recruitment/candidates/{$candidate->id}")
        ->assertSessionHasErrors(['proposed_joining_date']);
});

test('accept candidate offer attaches signed document and transitions candidate', function () {
    $candidate = createSelectedInterviewCandidate(
        $this->companyA,
        $this->requirement,
        $this->line,
        $this->recruiterA,
    );

    $this->actingAs($this->recruiterA)
        ->withSession(['current_company_id' => $this->companyA->id]);

    $this->post("/organization/recruitment/candidates/{$candidate->id}/offers", [
        'salary_amount' => '5000',
        'salary_currency_code' => 'AED',
        'proposed_joining_date' => now('Asia/Dubai')->addMonth()->toDateString(),
        'offer_date' => now('Asia/Dubai')->toDateString(),
        'lock_version' => 0,
        'expected_stage' => 'interview',
        'expected_outcome' => 'selected',
    ]);

    $offer = RecruitmentCandidateOffer::query()->firstOrFail();
    $candidate->refresh();

    $this->post("/organization/recruitment/candidates/{$candidate->id}/offers/{$offer->id}/send", [
        'lock_version' => $candidate->lock_version,
        'offer_lock_version' => $offer->lock_version,
        'expected_stage' => 'offer_jol',
        'expected_offer_status' => 'draft',
    ]);

    $offer->refresh();
    $candidate->refresh();

    $signedDoc = UploadedFile::fake()->create('signed_offer.pdf', 150, 'application/pdf');

    $this->post("/organization/recruitment/candidates/{$candidate->id}/offers/{$offer->id}/accept", [
        'lock_version' => $candidate->lock_version,
        'offer_lock_version' => $offer->lock_version,
        'expected_stage' => 'offer_jol',
        'expected_offer_status' => 'sent',
        'accepted_at' => now('Asia/Dubai')->toDateString(),
        'acceptance_document' => $signedDoc,
    ])->assertRedirect()->assertSessionHas('success');

    $offer->refresh();
    $candidate->refresh();

    expect($offer->status)->toBe(CandidateOfferStatus::Accepted)
        ->and($candidate->stage)->toBe(CandidateStage::Joining)
        ->and($offer->acceptance_document_path)->not->toBeNull()
        ->and(Storage::disk('local')->exists($offer->acceptance_document_path))->toBeTrue();
});
