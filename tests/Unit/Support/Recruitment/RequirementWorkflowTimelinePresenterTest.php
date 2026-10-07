<?php

use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Position;
use App\Models\Project;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementLine;
use App\Models\User;
use App\Support\Recruitment\RecordRequirementStatusTransition;
use App\Support\Recruitment\RequirementWorkflowTimelinePresenter;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $country = Country::query()->create([
        'code' => 'RTL',
        'name' => 'Timeline Country',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'RTL',
        'name' => 'Timeline Currency',
        'symbol' => 'D',
        'is_active' => true,
    ]);

    $this->company = Company::query()->create([
        'name' => 'Timeline Co',
        'slug' => 'timeline-co-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $this->otherCompany = Company::query()->create([
        'name' => 'Other Timeline Co',
        'slug' => 'other-timeline-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'UTC',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $this->user = User::factory()->create([
        'company_id' => $this->company->id,
        'name' => 'Timeline Actor',
        'status' => 'active',
    ]);

    DB::table('company_user')->updateOrInsert(
        ['company_id' => $this->company->id, 'user_id' => $this->user->id],
        ['status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    );

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
    foreach ([
        'recruitment.requirements.view',
        'recruitment.requirements.update',
        'recruitment.requirements.submit',
        'recruitment.requirements.approve',
        'recruitment.requirements.close',
        'recruitment.requirements.cancel',
        'recruitment.requirements.reopen',
    ] as $perm) {
        $permission = Permission::query()->firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        $this->user->givePermissionTo($permission);
    }

    $this->client = Client::query()->create(['name' => 'Timeline Client', 'is_active' => true]);
    $this->project = Project::query()->create(['title' => 'Timeline Project', 'is_active' => true]);
    $this->project->clients()->sync([$this->client->id]);
    $this->position = Position::query()->create([
        'company_id' => $this->company->id,
        'title' => 'Officer',
        'status' => 'active',
    ]);
});

function createTimelineRequirement(object $context, array $overrides = []): RecruitmentRequirement
{
    $req = RecruitmentRequirement::query()->create(array_merge([
        'company_id' => $context->company->id,
        'requirement_number' => 'REQ-TL-'.random_int(1000, 9999),
        'client_id' => $context->client->id,
        'project_id' => $context->project->id,
        'request_received_date' => now()->subDay(),
        'required_by_date' => now()->addDays(10),
        'priority' => 'normal',
        'status' => RequirementStatus::Draft,
        'assigned_to' => $context->user->id,
        'created_by' => $context->user->id,
        'updated_by' => $context->user->id,
    ], $overrides));

    RecruitmentRequirementLine::query()->create([
        'company_id' => $context->company->id,
        'recruitment_requirement_id' => $req->id,
        'position_id' => $context->position->id,
        'required_headcount' => 1,
        'salary_min' => 1000,
        'salary_max' => 2000,
        'salary_currency_code' => 'AED',
        'status' => RequirementLineStatus::Open,
    ]);

    return $req->fresh(['creator', 'assignedRecruiter']);
}

test('timeline presents chronological transition events with actors and reasons', function () {
    $req = createTimelineRequirement($this);

    Carbon::setTestNow(Carbon::parse('2026-01-01 08:00:00', 'Asia/Dubai'));
    RecordRequirementStatusTransition::handle($req, null, RequirementStatus::Draft, $this->user->id);

    Carbon::setTestNow(Carbon::parse('2026-01-01 09:00:00', 'Asia/Dubai'));
    RecordRequirementStatusTransition::handle(
        $req,
        RequirementStatus::Draft,
        RequirementStatus::PendingApproval,
        $this->user->id,
    );

    Carbon::setTestNow(Carbon::parse('2026-01-01 10:00:00', 'Asia/Dubai'));
    RecordRequirementStatusTransition::handle(
        $req,
        RequirementStatus::PendingApproval,
        RequirementStatus::Returned,
        $this->user->id,
        'Need clearer notes',
    );

    Carbon::setTestNow(Carbon::parse('2026-01-01 11:00:00', 'Asia/Dubai'));
    RecordRequirementStatusTransition::handle(
        $req,
        RequirementStatus::Returned,
        RequirementStatus::PendingApproval,
        $this->user->id,
    );

    Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00', 'Asia/Dubai'));
    RecordRequirementStatusTransition::handle(
        $req,
        RequirementStatus::PendingApproval,
        RequirementStatus::Open,
        $this->user->id,
    );
    $req->update(['status' => RequirementStatus::Open, 'approved_at' => now()]);

    $timeline = RequirementWorkflowTimelinePresenter::for($req->fresh(['creator']), $this->user);

    expect($timeline['current_stage'])->toBe('open')
        ->and($timeline['current_stage_label'])->toBe('Open')
        ->and($timeline['next_expected_action'])->toBe('fill')
        ->and($timeline['next_expected_action_label'])->toBe('Mark as filled')
        ->and(collect($timeline['events'])->pluck('label')->all())->toBe([
            'Draft created',
            'Submitted for approval',
            'Returned',
            'Resubmitted',
            'Approved / recruitment started',
        ])
        ->and($timeline['events'][2]['reason'])->toBe('Need clearer notes')
        ->and($timeline['events'][2]['actor_name'])->toBe('Timeline Actor')
        ->and($timeline['events'][4]['is_current'])->toBeTrue()
        ->and($timeline['events'][4]['state'])->toBe('current');

    Carbon::setTestNow();
});

test('timeline keeps repeated hold and resume cycles', function () {
    $req = createTimelineRequirement($this, [
        'status' => RequirementStatus::Open,
        'approved_at' => now()->subDay(),
    ]);

    RecordRequirementStatusTransition::handle($req, RequirementStatus::PendingApproval, RequirementStatus::Open, $this->user->id);
    RecordRequirementStatusTransition::handle($req, RequirementStatus::Open, RequirementStatus::OnHold, $this->user->id);
    RecordRequirementStatusTransition::handle($req, RequirementStatus::OnHold, RequirementStatus::Open, $this->user->id);
    RecordRequirementStatusTransition::handle($req, RequirementStatus::Open, RequirementStatus::OnHold, $this->user->id);
    $req->update(['status' => RequirementStatus::OnHold]);

    $timeline = RequirementWorkflowTimelinePresenter::for($req->fresh(['creator']), $this->user);
    $labels = collect($timeline['events'])->pluck('label')->all();

    expect($labels)->toContain('Put On Hold')
        ->and($labels)->toContain('Resumed')
        ->and(collect($labels)->filter(fn ($label) => $label === 'Put On Hold')->count())->toBe(2)
        ->and($timeline['next_expected_action'])->toBe('resume');
});

test('timeline isolates company transitions', function () {
    $req = createTimelineRequirement($this, ['status' => RequirementStatus::Open]);
    RecordRequirementStatusTransition::handle($req, RequirementStatus::PendingApproval, RequirementStatus::Open, $this->user->id);

    // Foreign transition with wrong company id must not appear.
    DB::table('recruitment_requirement_status_transitions')->insert([
        'company_id' => $this->otherCompany->id,
        'recruitment_requirement_id' => $req->id,
        'from_status' => RequirementStatus::Open->value,
        'to_status' => RequirementStatus::Cancelled->value,
        'performed_by' => $this->user->id,
        'reason' => 'Foreign noise',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $timeline = RequirementWorkflowTimelinePresenter::for($req->fresh(['creator']), $this->user);

    expect(collect($timeline['events'])->pluck('label')->all())->not->toContain('Cancelled')
        ->and(collect($timeline['events'])->pluck('reason')->filter()->all())->not->toContain('Foreign noise');
});

test('filled cancelled and reopened states are represented', function () {
    $req = createTimelineRequirement($this, ['status' => RequirementStatus::Completed, 'completed_at' => now()]);

    RecordRequirementStatusTransition::handle($req, RequirementStatus::Open, RequirementStatus::Completed, $this->user->id);
    RecordRequirementStatusTransition::handle($req, RequirementStatus::Completed, RequirementStatus::Open, $this->user->id);
    RecordRequirementStatusTransition::handle($req, RequirementStatus::Open, RequirementStatus::Cancelled, $this->user->id, 'No longer needed');
    $req->update(['status' => RequirementStatus::Cancelled, 'cancellation_reason' => 'No longer needed']);

    $timeline = RequirementWorkflowTimelinePresenter::for($req->fresh(['creator']), $this->user);
    $labels = collect($timeline['events'])->pluck('label')->all();

    expect($labels)->toContain('Filled / Completed')
        ->and($labels)->toContain('Reopened')
        ->and($labels)->toContain('Cancelled')
        ->and($timeline['current_stage'])->toBe('cancelled');
});
