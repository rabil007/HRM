<?php

use App\Actions\Recruitment\ApproveHeadcountRevisionAction;
use App\Actions\Recruitment\ChangeHeadcountAction;
use App\Enums\Recruitment\CandidateStage;
use App\Enums\Recruitment\RequirementHeadcountRevisionInitiator;
use App\Enums\Recruitment\RequirementHeadcountRevisionStatus;
use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Position;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementHeadcountRevision;
use App\Models\RecruitmentRequirementHeadcountRevisionLine;
use App\Models\RecruitmentRequirementLine;
use App\Models\User;
use App\Support\Recruitment\RequirementPresenter;
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
        'name' => 'Energy Infra',
        'slug' => 'energy-infra-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $this->client = Client::query()->create(['name' => 'Client A', 'is_active' => true]);
    $this->position = Position::query()->create(['company_id' => $this->company->id, 'title' => 'Supervisor', 'status' => 'active']);

    $this->requester = User::factory()->create(['company_id' => $this->company->id, 'status' => 'active']);
    $this->recruiter = User::factory()->create(['company_id' => $this->company->id, 'status' => 'active']);

    foreach ([$this->requester, $this->recruiter] as $u) {
        DB::table('company_user')->updateOrInsert(
            ['company_id' => $this->company->id, 'user_id' => $u->id],
            ['status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        );
    }

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
    foreach (['recruitment.requirements.view', 'recruitment.requirements.update', 'recruitment.requirements.change_headcount', 'recruitment.requirements.request_headcount_revision', 'recruitment.requirements.approve'] as $perm) {
        Permission::query()->firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
    }

    $this->requester->givePermissionTo(['recruitment.requirements.view', 'recruitment.requirements.update', 'recruitment.requirements.change_headcount']);
    $this->recruiter->givePermissionTo(['recruitment.requirements.view', 'recruitment.requirements.request_headcount_revision', 'recruitment.requirements.approve']);

    $this->requirement = RecruitmentRequirement::query()->create([
        'company_id' => $this->company->id,
        'client_id' => $this->client->id,
        'requirement_number' => 'REQ-PROG-1',
        'status' => RequirementStatus::Open,
        'priority' => 'normal',
        'request_received_date' => now()->subDays(10),
        'required_by_date' => now()->addDays(20),
        'total_headcount' => 2,
        'assigned_to' => $this->recruiter->id,
        'created_by' => $this->requester->id,
    ]);

    $this->line = RecruitmentRequirementLine::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'position_id' => $this->position->id,
        'required_headcount' => 2,
        'status' => RequirementLineStatus::Open,
    ]);
});

test('requirement line joined counts are derived dynamically with clamped remaining and overfill flag', function (): void {
    // 0 candidates joined
    $show0 = RequirementPresenter::toShow($this->requirement->fresh());
    expect($show0['lines'][0]['joined_count'])->toBe(0)
        ->and($show0['lines'][0]['remaining_headcount'])->toBe(2)
        ->and($show0['lines'][0]['is_overfilled'])->toBeFalse()
        ->and($show0['lines'][0]['target_reached'])->toBeFalse()
        ->and($show0['progress']['filled'])->toBe(0)
        ->and($show0['progress']['remaining'])->toBe(2)
        ->and($show0['progress']['is_overfilled'])->toBeFalse()
        ->and($show0['progress']['suggest_mark_filled'])->toBeFalse();

    // 1 candidate joined
    $cand1 = RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'name' => 'Joined 1',
        'stage' => CandidateStage::Joined,
        'actual_joining_date' => '2026-10-01',
        'lock_version' => 1,
        'position_title_snapshot' => 'Supervisor',
        'requirement_number_snapshot' => 'REQ-PROG-1',
    ]);

    $show1 = RequirementPresenter::toShow($this->requirement->fresh());
    expect($show1['lines'][0]['joined_count'])->toBe(1)
        ->and($show1['lines'][0]['remaining_headcount'])->toBe(1)
        ->and($show1['lines'][0]['target_reached'])->toBeFalse()
        ->and($show1['progress']['filled'])->toBe(1)
        ->and($show1['progress']['remaining'])->toBe(1);

    // 2 candidates joined: target reached
    $cand2 = RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'name' => 'Joined 2',
        'stage' => CandidateStage::Joined,
        'actual_joining_date' => '2026-10-02',
        'lock_version' => 1,
        'position_title_snapshot' => 'Supervisor',
        'requirement_number_snapshot' => 'REQ-PROG-1',
    ]);

    $show2 = RequirementPresenter::toShow($this->requirement->fresh());
    expect($show2['lines'][0]['joined_count'])->toBe(2)
        ->and($show2['lines'][0]['remaining_headcount'])->toBe(0)
        ->and($show2['lines'][0]['target_reached'])->toBeTrue()
        ->and($show2['lines'][0]['is_overfilled'])->toBeFalse()
        ->and($show2['progress']['filled'])->toBe(2)
        ->and($show2['progress']['remaining'])->toBe(0)
        ->and($show2['progress']['is_target_reached'])->toBeTrue()
        ->and($show2['progress']['suggest_mark_filled'])->toBeTrue();

    // 3 candidates joined: overfilled
    $cand3 = RecruitmentCandidate::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'name' => 'Joined 3',
        'stage' => CandidateStage::Joined,
        'actual_joining_date' => '2026-10-03',
        'lock_version' => 1,
        'position_title_snapshot' => 'Supervisor',
        'requirement_number_snapshot' => 'REQ-PROG-1',
    ]);

    $show3 = RequirementPresenter::toShow($this->requirement->fresh());
    expect($show3['lines'][0]['joined_count'])->toBe(3)
        ->and($show3['lines'][0]['remaining_headcount'])->toBe(0)
        ->and($show3['lines'][0]['is_overfilled'])->toBeTrue()
        ->and($show3['progress']['filled'])->toBe(3)
        ->and($show3['progress']['remaining'])->toBe(0)
        ->and($show3['progress']['is_overfilled'])->toBeTrue();
});

test('ChangeHeadcountAction guards line reduction below confirmed joined count', function (): void {
    // Set requirement to Draft for direct edit test
    $this->requirement->update(['status' => RequirementStatus::Draft]);

    // Have 2 joined candidates on the line
    foreach ([1, 2] as $i) {
        RecruitmentCandidate::query()->create([
            'company_id' => $this->company->id,
            'recruitment_requirement_id' => $this->requirement->id,
            'recruitment_requirement_line_id' => $this->line->id,
            'name' => "Joined {$i}",
            'stage' => CandidateStage::Joined,
            'actual_joining_date' => '2026-10-01',
            'lock_version' => 1,
            'position_title_snapshot' => 'Supervisor',
            'requirement_number_snapshot' => 'REQ-PROG-1',
        ]);
    }

    $action = app(ChangeHeadcountAction::class);

    // 1. Direct reduction to 1 (below joined count 2) must fail
    expect(fn () => $action->execute(
        $this->requirement,
        $this->requester,
        [['id' => $this->line->id, 'required_headcount' => 1]],
        'Reduce requirement headcount',
    ))->toThrow(ValidationException::class);

    // 2. Increasing or keeping at >= 2 succeeds
    $action->execute(
        $this->requirement,
        $this->requester,
        [['id' => $this->line->id, 'required_headcount' => 3]],
        'Increase requirement headcount',
    );

    $this->line->refresh();
    expect($this->line->required_headcount)->toBe(3);
});

test('ApproveHeadcountRevisionAction guards revision approval below confirmed joined count', function (): void {
    // Have 2 joined candidates on the line
    foreach ([1, 2] as $i) {
        RecruitmentCandidate::query()->create([
            'company_id' => $this->company->id,
            'recruitment_requirement_id' => $this->requirement->id,
            'recruitment_requirement_line_id' => $this->line->id,
            'name' => "Joined {$i}",
            'stage' => CandidateStage::Joined,
            'actual_joining_date' => '2026-10-01',
            'lock_version' => 1,
            'position_title_snapshot' => 'Supervisor',
            'requirement_number_snapshot' => 'REQ-PROG-1',
        ]);
    }

    // Pending revision requested by requester requesting reduction to 1
    $revision = RecruitmentRequirementHeadcountRevision::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $this->requirement->id,
        'initiator' => RequirementHeadcountRevisionInitiator::Requester,
        'status' => RequirementHeadcountRevisionStatus::Pending,
        'requested_by' => $this->requester->id,
        'reason' => 'Need fewer positions',
    ]);

    RecruitmentRequirementHeadcountRevisionLine::query()->create([
        'company_id' => $this->company->id,
        'headcount_revision_id' => $revision->id,
        'recruitment_requirement_line_id' => $this->line->id,
        'position_id' => $this->position->id,
        'position_title' => 'Supervisor',
        'old_headcount' => 2,
        'requested_headcount' => 1, // Below 2 joined candidates!
    ]);

    $approveAction = app(ApproveHeadcountRevisionAction::class);

    // Recruiter approving this revision must be rejected because 2 candidates are already confirmed Joined
    expect(fn () => $approveAction->execute(
        $this->requirement,
        $revision,
        $this->recruiter,
    ))->toThrow(ValidationException::class);
});
