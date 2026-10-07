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
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $country = Country::query()->create([
        'code' => 'RWT',
        'name' => 'Workflow Country',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'RWT',
        'name' => 'Workflow Currency',
        'symbol' => 'D',
        'is_active' => true,
    ]);

    $this->company = Company::query()->create([
        'name' => 'Workflow Co',
        'slug' => 'workflow-co-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $this->user = User::factory()->create([
        'company_id' => $this->company->id,
        'email' => 'workflow.user@example.com',
        'name' => 'Workflow User',
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
        'recruitment.requirements.close',
        'audit.view',
    ] as $perm) {
        $permission = Permission::query()->firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        $this->user->givePermissionTo($permission);
    }

    $this->client = Client::query()->create(['name' => 'Workflow Client', 'is_active' => true]);
    $this->project = Project::query()->create(['title' => 'Workflow Project', 'is_active' => true]);
    $this->project->clients()->sync([$this->client->id]);
    $this->position = Position::query()->create([
        'company_id' => $this->company->id,
        'title' => 'Workflow Officer',
        'status' => 'active',
    ]);
});

test('show page exposes workflow timeline and hold recovery props', function () {
    $req = RecruitmentRequirement::query()->create([
        'company_id' => $this->company->id,
        'requirement_number' => 'REQ-WF-0001',
        'client_id' => $this->client->id,
        'project_id' => $this->project->id,
        'request_received_date' => now()->subDays(4),
        'required_by_date' => now()->addDays(6),
        'priority' => 'normal',
        'status' => RequirementStatus::OnHold,
        'assigned_to' => $this->user->id,
        'created_by' => $this->user->id,
        'approved_at' => now()->subDays(3),
        'approved_by' => $this->user->id,
        'opened_at' => now()->subDays(3),
        'updated_by' => $this->user->id,
    ]);

    RecruitmentRequirementLine::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $req->id,
        'position_id' => $this->position->id,
        'required_headcount' => 1,
        'salary_min' => 4000,
        'salary_max' => 6000,
        'salary_currency_code' => 'AED',
        'status' => RequirementLineStatus::Open,
    ]);

    RecordRequirementStatusTransition::handle(
        $req,
        RequirementStatus::PendingApproval,
        RequirementStatus::Open,
        $this->user->id,
    );
    RecordRequirementStatusTransition::handle(
        $req,
        RequirementStatus::Open,
        RequirementStatus::OnHold,
        $this->user->id,
    );

    $this->actingAs($this->user)
        ->withSession(['current_company_id' => $this->company->id])
        ->get("/organization/recruitment/requirements/{$req->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/recruitment/requirements/show')
            ->has('workflow_timeline')
            ->where('workflow_timeline.current_stage', 'on_hold')
            ->where('workflow_timeline.next_expected_action', 'resume')
            ->where('workflow_timeline.next_expected_action_label', 'Resume requirement')
            ->has('workflow_timeline.events', 2)
            ->where('requirement.can_hold', false)
            ->where('requirement.can_resume', true)
            ->has('recent_activity')
        );
});

test('open requirement show page offers fill next action without resume', function () {
    $req = RecruitmentRequirement::query()->create([
        'company_id' => $this->company->id,
        'requirement_number' => 'REQ-WF-0002',
        'client_id' => $this->client->id,
        'project_id' => $this->project->id,
        'request_received_date' => now()->subDays(2),
        'required_by_date' => now()->addDays(8),
        'priority' => 'normal',
        'status' => RequirementStatus::Open,
        'assigned_to' => $this->user->id,
        'created_by' => $this->user->id,
        'approved_at' => now()->subDay(),
        'approved_by' => $this->user->id,
        'opened_at' => now()->subDay(),
        'updated_by' => $this->user->id,
    ]);

    RecruitmentRequirementLine::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $req->id,
        'position_id' => $this->position->id,
        'required_headcount' => 1,
        'salary_min' => 4000,
        'salary_max' => 6000,
        'salary_currency_code' => 'AED',
        'status' => RequirementLineStatus::Open,
    ]);

    RecordRequirementStatusTransition::handle(
        $req,
        RequirementStatus::PendingApproval,
        RequirementStatus::Open,
        $this->user->id,
    );

    $this->actingAs($this->user)
        ->withSession(['current_company_id' => $this->company->id])
        ->get("/organization/recruitment/requirements/{$req->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('workflow_timeline.next_expected_action', 'fill')
            ->where('requirement.can_resume', false)
            ->where('requirement.can_fill', true)
        );
});
