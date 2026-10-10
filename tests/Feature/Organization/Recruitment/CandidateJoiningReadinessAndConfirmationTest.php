<?php

use App\Enums\Recruitment\CandidateJoiningReadinessStatus;
use App\Enums\Recruitment\CandidateOfferStatus;
use App\Enums\Recruitment\CandidateStage;
use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\Position;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentCandidateOffer;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementLine;
use App\Models\User;
use App\Support\Recruitment\Candidates\Actions\AcceptCandidateOffer;
use App\Support\Recruitment\Candidates\Actions\ConfirmCandidateJoined;
use App\Support\Recruitment\Candidates\Actions\CorrectCandidateJoined;
use App\Support\Recruitment\Candidates\Actions\ReviseCandidateOffer;
use App\Support\Recruitment\Candidates\Actions\UpdateCandidateJoiningReadiness;
use App\Support\Recruitment\Candidates\CandidateWorkflowAuthorization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    config(['app.timezone' => 'UTC']);
    date_default_timezone_set('UTC');

    $country = Country::query()->firstOrCreate(
        ['code' => 'AE'],
        ['name' => 'United Arab Emirates', 'dial_code' => '+971', 'is_active' => true],
    );

    $currency = Currency::query()->firstOrCreate(
        ['code' => 'AED'],
        ['name' => 'Dirham', 'symbol' => 'د.إ', 'is_active' => true],
    );

    $this->company = Company::query()->create([
        'name' => 'Gulf Energy Corp',
        'slug' => 'gulf-energy-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $this->client = Client::query()->create(['name' => 'Client A', 'is_active' => true]);
    $this->position = Position::query()->create(['company_id' => $this->company->id, 'title' => 'Specialist', 'status' => 'active']);

    // Recruiter user
    $this->recruiter = User::factory()->create(['company_id' => $this->company->id]);
    DB::table('company_user')->updateOrInsert(
        ['company_id' => $this->company->id, 'user_id' => $this->recruiter->id],
        ['status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    );

    // Manager user (has manage override)
    $this->manager = User::factory()->create(['company_id' => $this->company->id]);
    DB::table('company_user')->updateOrInsert(
        ['company_id' => $this->company->id, 'user_id' => $this->manager->id],
        ['status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    );

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);

    $recruiterPermissions = [
        'recruitment.candidates.view',
        'recruitment.candidates.update',
        'recruitment.candidates.offer.decide',
        'recruitment.candidates.joining.confirm',
    ];
    foreach ($recruiterPermissions as $perm) {
        $p = Permission::query()->firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        $this->recruiter->givePermissionTo($p);
    }

    $managerPermissions = [
        'recruitment.candidates.view',
        'recruitment.candidates.update',
        'recruitment.candidates.manage',
        'recruitment.candidates.joining.confirm',
    ];
    foreach ($managerPermissions as $perm) {
        $p = Permission::query()->firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        $this->manager->givePermissionTo($p);
    }

    $this->requirement = RecruitmentRequirement::query()->create([
        'company_id' => $this->company->id,
        'client_id' => $this->client->id,
        'requirement_number' => 'REQ-JOIN-1',
        'status' => RequirementStatus::Open,
        'priority' => 'normal',
        'request_received_date' => now()->subDays(10),
        'required_by_date' => now()->addDays(20),
        'total_headcount' => 2,
        'assigned_to' => $this->recruiter->id,
        'created_by' => $this->recruiter->id,
    ]);

    $this->line = RecruitmentRequirementLine::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'position_id' => $this->position->id,
        'required_headcount' => 2,
        'status' => RequirementLineStatus::Open,
    ]);
});

test('accepting an offer moves candidate to joining and initializes readiness and expected date', function (): void {
    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'name' => 'Candidate Alpha',
        'stage' => CandidateStage::OfferJol,
        'lock_version' => 1,
        'position_title_snapshot' => 'Specialist',
        'requirement_number_snapshot' => 'REQ-JOIN-1',
    ]);

    $offer = RecruitmentCandidateOffer::query()->create([
        'company_id' => $this->company->id,
        'recruitment_candidate_id' => $candidate->id,
        'revision_number' => 1,
        'is_current' => true,
        'status' => CandidateOfferStatus::Sent,
        'salary_amount' => '12000',
        'salary_currency_code' => 'AED',
        'offer_date' => '2026-10-01',
        'proposed_joining_date' => '2026-11-01',
        'sent_at' => CarbonImmutable::parse('2026-10-02 08:00:00', 'UTC'),
        'lock_version' => 1,
    ]);

    $acceptAction = app(AcceptCandidateOffer::class);
    $acceptAction->handle($this->recruiter, $candidate, $offer, [
        'accepted_at' => '2026-10-05T10:00',
        'offer_lock_version' => 1,
        'lock_version' => 1,
    ]);

    $candidate->refresh();
    expect($candidate->stage)->toBe(CandidateStage::Joining)
        ->and($candidate->expected_joining_date?->format('Y-m-d'))->toBe('2026-11-01')
        ->and($candidate->joining_readiness_status)->toBe(CandidateJoiningReadinessStatus::Pending);
});

test('updating readiness audits change without altering accepted offer terms', function (): void {
    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'name' => 'Candidate Beta',
        'stage' => CandidateStage::Joining,
        'expected_joining_date' => '2026-11-01',
        'joining_readiness_status' => CandidateJoiningReadinessStatus::Pending,
        'lock_version' => 1,
        'position_title_snapshot' => 'Specialist',
        'requirement_number_snapshot' => 'REQ-JOIN-1',
    ]);

    $offer = RecruitmentCandidateOffer::query()->create([
        'company_id' => $this->company->id,
        'recruitment_candidate_id' => $candidate->id,
        'revision_number' => 1,
        'is_current' => true,
        'status' => CandidateOfferStatus::Accepted,
        'salary_amount' => '12000',
        'salary_currency_code' => 'AED',
        'offer_date' => '2026-10-01',
        'proposed_joining_date' => '2026-11-01',
        'accepted_at' => CarbonImmutable::parse('2026-10-05 08:00:00', 'UTC'),
        'lock_version' => 1,
    ]);

    $updateReadiness = app(UpdateCandidateJoiningReadiness::class);
    $updateReadiness->handle($this->recruiter, $candidate, [
        'expected_joining_date' => '2026-11-15',
        'joining_readiness_status' => 'ready',
        'joining_readiness_notes' => 'Visa issued and ticket booked',
        'joining_blocker_notes' => null,
        'reason' => 'Candidate requested 2 weeks delayed arrival',
        'lock_version' => 1,
    ]);

    $candidate->refresh();
    $offer->refresh();

    expect($candidate->expected_joining_date?->format('Y-m-d'))->toBe('2026-11-15')
        ->and($candidate->joining_readiness_status)->toBe(CandidateJoiningReadinessStatus::Ready)
        ->and($candidate->joining_readiness_notes)->toBe('Visa issued and ticket booked')
        ->and($candidate->lock_version)->toBe(2);

    // Offer proposed joining date must NOT be modified
    expect($offer->proposed_joining_date?->format('Y-m-d'))->toBe('2026-11-01');

    // Audit transition recorded
    $lastTransition = $candidate->stageTransitions()->latest('id')->first();
    expect($lastTransition)->not->toBeNull()
        ->and($lastTransition->action->value)->toBe('joining_readiness_updated')
        ->and($lastTransition->reason)->toBe('Candidate requested 2 weeks delayed arrival');
});

test('confirming joined requires ready readiness status and accepted offer', function (): void {
    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'name' => 'Candidate Gamma',
        'stage' => CandidateStage::Joining,
        'expected_joining_date' => '2026-10-10',
        'joining_readiness_status' => CandidateJoiningReadinessStatus::Pending, // Not ready
        'lock_version' => 1,
        'position_title_snapshot' => 'Specialist',
        'requirement_number_snapshot' => 'REQ-JOIN-1',
    ]);

    $offer = RecruitmentCandidateOffer::query()->create([
        'company_id' => $this->company->id,
        'recruitment_candidate_id' => $candidate->id,
        'revision_number' => 1,
        'is_current' => true,
        'status' => CandidateOfferStatus::Accepted,
        'salary_amount' => '12000',
        'salary_currency_code' => 'AED',
        'offer_date' => '2026-10-01',
        'proposed_joining_date' => '2026-10-10',
        'accepted_at' => CarbonImmutable::parse('2026-10-05 08:00:00', 'UTC'),
        'lock_version' => 1,
    ]);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-12 12:00:00', 'Asia/Dubai'));

    try {
        $confirmJoined = app(ConfirmCandidateJoined::class);

        // 1. Fails when readiness is Pending
        expect(fn () => $confirmJoined->handle($this->recruiter, $candidate, [
            'actual_joining_date' => '2026-10-10',
            'lock_version' => 1,
        ]))->toThrow(ValidationException::class);

        // 2. Mark ready, then confirm works
        $candidate->update(['joining_readiness_status' => CandidateJoiningReadinessStatus::Ready->value]);

        $confirmJoined->handle($this->recruiter, $candidate, [
            'actual_joining_date' => '2026-10-10',
            'notes' => 'Arrived on site',
            'lock_version' => 1,
        ]);

        $candidate->refresh();
        expect($candidate->stage)->toBe(CandidateStage::Joined)
            ->and($candidate->actual_joining_date?->format('Y-m-d'))->toBe('2026-10-10')
            ->and($candidate->joined_by)->toBe($this->recruiter->id)
            ->and($candidate->joined_at)->not->toBeNull();

        // 3. Stale / repeated submission cannot count twice
        expect(fn () => $confirmJoined->handle($this->recruiter, $candidate, [
            'actual_joining_date' => '2026-10-10',
            'lock_version' => $candidate->lock_version,
        ]))->toThrow(ValidationException::class);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('management can correct/undo joining with audit reason unless downstream employee conversion exists', function (): void {
    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'name' => 'Candidate Delta',
        'stage' => CandidateStage::Joined,
        'expected_joining_date' => '2026-10-10',
        'actual_joining_date' => '2026-10-10',
        'joined_at' => now(),
        'joined_by' => $this->recruiter->id,
        'joining_readiness_status' => CandidateJoiningReadinessStatus::Ready,
        'lock_version' => 1,
        'position_title_snapshot' => 'Specialist',
        'requirement_number_snapshot' => 'REQ-JOIN-1',
    ]);

    RecruitmentCandidateOffer::query()->create([
        'company_id' => $this->company->id,
        'recruitment_candidate_id' => $candidate->id,
        'revision_number' => 1,
        'is_current' => true,
        'status' => CandidateOfferStatus::Accepted,
        'salary_amount' => '12000',
        'salary_currency_code' => 'AED',
        'offer_date' => '2026-10-01',
        'proposed_joining_date' => '2026-10-10',
        'accepted_at' => CarbonImmutable::parse('2026-10-05 08:00:00', 'UTC'),
        'lock_version' => 1,
    ]);

    $correctJoined = app(CorrectCandidateJoined::class);

    // 1. Non-manager recruiter without manage override cannot correct
    expect(fn () => $correctJoined->handle($this->recruiter, $candidate, [
        'reason' => 'Mistaken click',
        'lock_version' => 1,
    ]))->toThrow(ValidationException::class);

    // 2. Reason < 3 characters rejected
    expect(fn () => $correctJoined->handle($this->manager, $candidate, [
        'reason' => 'no',
        'lock_version' => 1,
    ]))->toThrow(ValidationException::class);

    // 3. Prevented if downstream employee conversion exists
    $employee = Employee::factory()->create(['company_id' => $this->company->id]);
    $candidate->update(['employee_id' => $employee->id]);
    expect(fn () => $correctJoined->handle($this->manager, $candidate, [
        'reason' => 'Undo joining because employee did not report',
        'lock_version' => 1,
    ]))->toThrow(ValidationException::class);

    // 4. Successful correction when no employee conversion
    $candidate->update(['employee_id' => null]);
    $correctJoined->handle($this->manager, $candidate, [
        'reason' => 'Undo joining because candidate deferred arrival to next month',
        'lock_version' => 1,
    ]);

    $candidate->refresh();
    expect($candidate->stage)->toBe(CandidateStage::Joining)
        ->and($candidate->actual_joining_date)->toBeNull()
        ->and($candidate->joined_at)->toBeNull()
        ->and($candidate->joined_by)->toBeNull()
        ->and($candidate->lock_version)->toBe(2);

    $lastTransition = $candidate->stageTransitions()->latest('id')->first();
    expect($lastTransition)->not->toBeNull()
        ->and($lastTransition->action->value)->toBe('joining_corrected')
        ->and($lastTransition->to_stage)->toBe(CandidateStage::Joining)
        ->and($lastTransition->reason)->toBe('Undo joining because candidate deferred arrival to next month');
});

test('revising an accepted offer cannot undo a confirmed join or bypass the employee link guard', function (): void {
    $revisePermission = Permission::query()->firstOrCreate([
        'name' => 'recruitment.candidates.offer.revise',
        'guard_name' => 'web',
    ]);
    $this->manager->givePermissionTo($revisePermission);

    $candidate = RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'name' => 'Candidate Epsilon',
        'stage' => CandidateStage::Joined,
        'expected_joining_date' => '2026-10-10',
        'actual_joining_date' => '2026-10-10',
        'joined_at' => now(),
        'joined_by' => $this->recruiter->id,
        'joining_readiness_status' => CandidateJoiningReadinessStatus::Ready,
        'lock_version' => 1,
        'position_title_snapshot' => 'Specialist',
        'requirement_number_snapshot' => 'REQ-JOIN-1',
    ]);

    $offer = RecruitmentCandidateOffer::query()->create([
        'company_id' => $this->company->id,
        'recruitment_candidate_id' => $candidate->id,
        'revision_number' => 1,
        'is_current' => true,
        'status' => CandidateOfferStatus::Accepted,
        'salary_amount' => '12000',
        'salary_currency_code' => 'AED',
        'offer_date' => '2026-10-01',
        'proposed_joining_date' => '2026-10-10',
        'accepted_at' => CarbonImmutable::parse('2026-10-05 08:00:00', 'UTC'),
        'lock_version' => 1,
    ]);

    $candidate->setRelation('currentOffer', $offer);
    expect(CandidateWorkflowAuthorization::canReviseOffer($offer, $candidate))->toBeFalse();

    $revise = app(ReviseCandidateOffer::class);
    $payload = [
        'reason' => 'Correct salary after the candidate already joined',
        'salary_amount' => '13000',
        'lock_version' => 1,
        'offer_lock_version' => 1,
        'expected_stage' => CandidateStage::Joined->value,
        'expected_offer_status' => CandidateOfferStatus::Accepted->value,
    ];

    expect(fn () => $revise->handle($this->manager, $candidate, $offer, $payload))
        ->toThrow(ValidationException::class);

    $employee = Employee::factory()->create(['company_id' => $this->company->id]);
    $candidate->update(['employee_id' => $employee->id]);
    $candidate->refresh();

    expect(fn () => $revise->handle($this->manager, $candidate->fresh(), $offer->fresh(), $payload))
        ->toThrow(ValidationException::class);

    $candidate->refresh();
    $offer->refresh();

    expect($candidate->stage)->toBe(CandidateStage::Joined)
        ->and($candidate->actual_joining_date?->format('Y-m-d'))->toBe('2026-10-10')
        ->and($candidate->joined_by)->toBe($this->recruiter->id)
        ->and($candidate->employee_id)->toBe($employee->id)
        ->and($offer->is_current)->toBeTrue()
        ->and($offer->status)->toBe(CandidateOfferStatus::Accepted)
        ->and(RecruitmentCandidateOffer::query()->count())->toBe(1)
        ->and($this->line->candidates()->where('stage', CandidateStage::Joined->value)->count())->toBe(1);

    $candidate->update(['employee_id' => null]);
    app(CorrectCandidateJoined::class)->handle($this->manager, $candidate->fresh(), [
        'reason' => 'Arrival was recorded against the wrong month',
        'lock_version' => $candidate->fresh()->lock_version,
    ]);

    $candidate->refresh();
    $offer->refresh();
    $candidate->setRelation('currentOffer', $offer);

    expect($candidate->stage)->toBe(CandidateStage::Joining)
        ->and(CandidateWorkflowAuthorization::canReviseOffer($offer, $candidate))->toBeTrue();

    $revised = $revise->handle($this->manager, $candidate, $offer, [
        'reason' => 'Revise salary after joining was undone',
        'salary_amount' => '13000',
        'lock_version' => $candidate->lock_version,
        'offer_lock_version' => $offer->lock_version,
        'expected_stage' => CandidateStage::Joining->value,
        'expected_offer_status' => CandidateOfferStatus::Accepted->value,
    ]);

    $candidate->refresh();

    expect($candidate->stage)->toBe(CandidateStage::OfferJol)
        ->and($revised->is_current)->toBeTrue()
        ->and($revised->status)->toBe(CandidateOfferStatus::Draft)
        ->and($offer->fresh()->is_current)->toBeFalse();
});
