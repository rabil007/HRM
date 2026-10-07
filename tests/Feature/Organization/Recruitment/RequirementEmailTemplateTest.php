<?php

use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Jobs\DeliverRequirementLifecycleEmailJob;
use App\Mail\RequirementSubmittedForApprovalMail;
use App\Models\Client;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\EmailTemplate;
use App\Models\Position;
use App\Models\Project;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementLine;
use App\Models\RecruitmentRequirementStatusTransition;
use App\Models\User;
use App\Support\Email\EmailTemplatePreview;
use App\Support\Recruitment\ComposeRequirementLifecycleMail;
use App\Support\Recruitment\RequirementLifecycleEmailPayload;
use Database\Seeders\EmailTemplatesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    Mail::fake();
    (new EmailTemplatesSeeder)->run();

    $country = Country::query()->create([
        'code' => 'RET',
        'name' => 'Requirement Email Template Country',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'RET',
        'name' => 'Requirement Email Template Currency',
        'symbol' => 'D',
        'is_active' => true,
    ]);

    $this->company = Company::query()->create([
        'name' => 'Requirement Email Template Co',
        'slug' => 'requirement-email-template-co-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);

    foreach ([
        'recruitment.requirements.view',
        'recruitment.requirements.submit',
        'recruitment.requirements.approve',
    ] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $this->requester = User::factory()->create([
        'email' => 'ret-requester@example.com',
        'name' => 'RET Requester',
        'company_id' => $this->company->id,
        'status' => 'active',
    ]);
    DB::table('company_user')->updateOrInsert(
        ['company_id' => $this->company->id, 'user_id' => $this->requester->id],
        ['status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    );
    $this->requester->givePermissionTo(['recruitment.requirements.view', 'recruitment.requirements.submit']);

    $this->recruiter = User::factory()->create([
        'email' => 'ret-recruiter@example.com',
        'name' => 'RET Recruiter',
        'company_id' => $this->company->id,
        'status' => 'active',
    ]);
    DB::table('company_user')->updateOrInsert(
        ['company_id' => $this->company->id, 'user_id' => $this->recruiter->id],
        ['status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    );
    $this->recruiter->givePermissionTo([
        'recruitment.requirements.view',
        'recruitment.requirements.approve',
    ]);

    $this->client = Client::query()->create([
        'name' => 'RET Client',
        'is_active' => true,
    ]);
    $this->project = Project::query()->create([
        'title' => 'RET Project',
        'is_active' => true,
    ]);
    $this->project->clients()->sync([$this->client->id]);
    $this->position = Position::query()->create([
        'company_id' => $this->company->id,
        'title' => 'Able Seaman',
        'status' => 'active',
    ]);
});

function createRetRequirement(Company $company, User $requester, User $recruiter, Client $client, Project $project, Position $position): RecruitmentRequirement
{
    $requirement = RecruitmentRequirement::query()->create([
        'company_id' => $company->id,
        'requirement_number' => 'REQ-RET-1',
        'client_id' => $client->id,
        'project_id' => $project->id,
        'request_received_date' => now()->subDay(),
        'required_by_date' => now()->addDays(14),
        'priority' => 'normal',
        'status' => RequirementStatus::PendingApproval,
        'assigned_to' => $recruiter->id,
        'created_by' => $requester->id,
        'updated_by' => $requester->id,
        'submitted_by' => $requester->id,
        'submitted_at' => now(),
    ]);

    RecruitmentRequirementLine::query()->create([
        'company_id' => $company->id,
        'recruitment_requirement_id' => $requirement->id,
        'position_id' => $position->id,
        'required_headcount' => 1,
        'status' => RequirementLineStatus::Open,
    ]);

    return $requirement->fresh(['client', 'project', 'lines.position', 'company']);
}

test('lifecycle email uses editable email template subject and body', function () {
    $requirement = createRetRequirement(
        $this->company,
        $this->requester,
        $this->recruiter,
        $this->client,
        $this->project,
        $this->position,
    );

    EmailTemplate::query()
        ->where('slug', ComposeRequirementLifecycleMail::SLUG_SUBMITTED)
        ->update([
            'subject' => 'Custom awaiting {{requirement_number}}',
            'body_html' => 'Please review {{requirement_number}} from {{submitter_name}}.',
        ]);

    $transition = RecruitmentRequirementStatusTransition::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $requirement->id,
        'from_status' => RequirementStatus::Draft->value,
        'to_status' => RequirementStatus::PendingApproval->value,
        'performed_by' => $this->requester->id,
    ]);

    (new DeliverRequirementLifecycleEmailJob(
        RequirementLifecycleEmailPayload::forSubmitted($requirement, $transition),
    ))->handle();

    Mail::assertSent(RequirementSubmittedForApprovalMail::class, function (RequirementSubmittedForApprovalMail $mail) {
        return $mail->subjectLine === 'Custom awaiting REQ-RET-1'
            && $mail->introMessage === 'Please review REQ-RET-1 from RET Requester.';
    });
});

test('lifecycle email is skipped when template is disabled', function () {
    $requirement = createRetRequirement(
        $this->company,
        $this->requester,
        $this->recruiter,
        $this->client,
        $this->project,
        $this->position,
    );

    EmailTemplate::query()
        ->where('slug', ComposeRequirementLifecycleMail::SLUG_SUBMITTED)
        ->update(['enabled' => false]);

    $transition = RecruitmentRequirementStatusTransition::query()->create([
        'company_id' => $this->company->id,
        'recruitment_requirement_id' => $requirement->id,
        'from_status' => RequirementStatus::Draft->value,
        'to_status' => RequirementStatus::PendingApproval->value,
        'performed_by' => $this->requester->id,
    ]);

    (new DeliverRequirementLifecycleEmailJob(
        RequirementLifecycleEmailPayload::forSubmitted($requirement, $transition),
    ))->handle();

    Mail::assertNothingSent();
});

test('email template preview renders recruitment structured layout', function () {
    $template = EmailTemplate::query()
        ->where('slug', ComposeRequirementLifecycleMail::SLUG_SUBMITTED)
        ->firstOrFail();

    $preview = app(EmailTemplatePreview::class)->render($template);

    expect($preview['subject'])->toContain('REQ-1001')
        ->and($preview['html'])->toContain('email-detail-row')
        ->and($preview['html'])->toContain('Acme Shipping')
        ->and($preview['html'])->toContain('Review requirement');
});
