<?php

use App\Actions\Recruitment\CreateRequirementAction;
use App\Actions\Recruitment\SubmitRequirementForApprovalAction;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Position;
use App\Models\Project;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementStatusTransition;
use App\Models\User;
use App\Support\Recruitment\RecordRequirementStatusTransition;
use App\Support\Recruitment\RequirementWorkflowTimelinePresenter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $country = Country::query()->create([
        'code' => 'RCT',
        'name' => 'Create Timeline Country',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'RCT',
        'name' => 'Create Timeline Currency',
        'symbol' => 'D',
        'is_active' => true,
    ]);

    $this->company = Company::query()->create([
        'name' => 'Create Timeline Co',
        'slug' => 'create-timeline-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $this->requester = User::factory()->create([
        'company_id' => $this->company->id,
        'name' => 'Requester',
        'email' => 'create.timeline.requester@example.com',
        'status' => 'active',
    ]);
    $this->recruiter = User::factory()->create([
        'company_id' => $this->company->id,
        'name' => 'Recruiter',
        'email' => 'create.timeline.recruiter@example.com',
        'status' => 'active',
    ]);

    foreach ([$this->requester, $this->recruiter] as $user) {
        DB::table('company_user')->updateOrInsert(
            ['company_id' => $this->company->id, 'user_id' => $user->id],
            ['status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        );
    }

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);

    foreach ([
        'recruitment.requirements.view',
        'recruitment.requirements.create',
        'recruitment.requirements.update',
        'recruitment.requirements.submit',
        'recruitment.requirements.approve',
    ] as $perm) {
        $permission = Permission::query()->firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        $this->requester->givePermissionTo($permission);
        $this->recruiter->givePermissionTo($permission);
    }

    $this->client = Client::query()->create(['name' => 'Create Timeline Client', 'is_active' => true]);
    $this->project = Project::query()->create(['title' => 'Create Timeline Project', 'is_active' => true]);
    $this->project->clients()->sync([$this->client->id]);
    $this->position = Position::query()->create([
        'company_id' => $this->company->id,
        'title' => 'Create Timeline Officer',
        'status' => 'active',
    ]);
});

function createTimelinePayload(object $context, array $overrides = []): array
{
    return array_merge([
        'client_id' => $context->client->id,
        'project_id' => $context->project->id,
        'request_received_date' => now()->subDay()->toDateString(),
        'required_by_date' => now()->addDays(10)->toDateString(),
        'priority' => 'normal',
        'assigned_to' => $context->recruiter->id,
        'notes' => null,
        'lines' => [[
            'position_id' => $context->position->id,
            'required_headcount' => 1,
            'salary_min' => 1000,
            'salary_max' => 2000,
        ]],
        'submit_for_approval' => false,
    ], $overrides);
}

test('save as draft records Draft created in the timeline', function () {
    $requirement = app(CreateRequirementAction::class)->execute(
        $this->company->id,
        $this->requester->id,
        createTimelinePayload($this, ['submit_for_approval' => false]),
    );

    expect($requirement->status)->toBe(RequirementStatus::Draft);

    $timeline = RequirementWorkflowTimelinePresenter::for(
        $requirement->fresh(['creator']),
        $this->requester,
    );

    expect(collect($timeline['events'])->pluck('label')->all())->toBe([
        'Draft created',
    ]);
});

test('direct create-and-submit timeline starts with Submitted for approval without Draft created', function () {
    $requirement = app(CreateRequirementAction::class)->execute(
        $this->company->id,
        $this->requester->id,
        createTimelinePayload($this, ['submit_for_approval' => true]),
    );

    expect($requirement->status)->toBe(RequirementStatus::PendingApproval);

    $timeline = RequirementWorkflowTimelinePresenter::for(
        $requirement->fresh(['creator']),
        $this->requester,
    );

    expect(collect($timeline['events'])->pluck('label')->all())->toBe([
        'Submitted for approval',
    ])
        ->and(collect($timeline['events'])->pluck('label')->all())->not->toContain('Draft created');
});

test('save draft then later submit keeps both events in order', function () {
    $requirement = app(CreateRequirementAction::class)->execute(
        $this->company->id,
        $this->requester->id,
        createTimelinePayload($this, ['submit_for_approval' => false]),
    );

    $submitted = app(SubmitRequirementForApprovalAction::class)
        ->execute($requirement, $this->requester);

    $timeline = RequirementWorkflowTimelinePresenter::for(
        $submitted->fresh(['creator']),
        $this->requester,
    );

    expect(collect($timeline['events'])->pluck('label')->all())->toBe([
        'Draft created',
        'Submitted for approval',
    ]);
});

test('failed initial direct submission leaves a valid Draft event', function () {
    expect(fn () => app(CreateRequirementAction::class)->execute(
        $this->company->id,
        $this->requester->id,
        createTimelinePayload($this, [
            'submit_for_approval' => true,
            'assigned_to' => $this->requester->id,
        ]),
    ))->toThrow(ValidationException::class);

    $requirement = RecruitmentRequirement::query()
        ->where('company_id', $this->company->id)
        ->latest('id')
        ->first();

    expect($requirement)->not->toBeNull()
        ->and($requirement->status)->toBe(RequirementStatus::Draft);

    $timeline = RequirementWorkflowTimelinePresenter::for(
        $requirement->fresh(['creator']),
        $this->requester,
    );

    expect(collect($timeline['events'])->pluck('label')->all())->toBe([
        'Draft created',
    ]);
});

test('retries do not create duplicate Draft transitions', function () {
    $requirement = app(CreateRequirementAction::class)->execute(
        $this->company->id,
        $this->requester->id,
        createTimelinePayload($this, [
            'submit_for_approval' => false,
        ]),
    );

    RecordRequirementStatusTransition::ensureDraftCreated($requirement, $this->requester->id);
    RecordRequirementStatusTransition::ensureDraftCreated($requirement, $this->requester->id);

    expect(
        RecruitmentRequirementStatusTransition::query()
            ->where('recruitment_requirement_id', $requirement->id)
            ->whereNull('from_status')
            ->where('to_status', RequirementStatus::Draft->value)
            ->count()
    )->toBe(1);
});
