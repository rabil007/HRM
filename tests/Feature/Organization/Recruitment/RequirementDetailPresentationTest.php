<?php

use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Jobs\DeliverRequirementLifecycleEmailJob;
use App\Mail\RequirementApprovedMail;
use App\Mail\RequirementReturnedMail;
use App\Mail\RequirementSubmittedForApprovalMail;
use App\Models\Client;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Position;
use App\Models\Project;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementLine;
use App\Models\User;
use App\Support\Recruitment\RequirementLifecycleEmailPayload;
use Database\Seeders\EmailTemplatesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    Mail::fake();
    (new EmailTemplatesSeeder)->run();

    $country = Country::query()->create([
        'code' => 'RDP',
        'name' => 'Detail Presentation Country',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'RDP',
        'name' => 'Detail Presentation Currency',
        'symbol' => 'D',
        'is_active' => true,
    ]);

    $this->company = Company::query()->create([
        'name' => 'Detail Presentation Co',
        'slug' => 'detail-presentation-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $permissions = [
        'recruitment.requirements.view',
        'recruitment.requirements.create',
        'recruitment.requirements.update',
        'recruitment.requirements.submit',
        'recruitment.requirements.approve',
        'recruitment.requirements.close',
        'recruitment.requirements.cancel',
        'recruitment.requirements.reopen',
        'recruitment.requirements.attachments.download',
    ];

    $this->requester = User::factory()->create([
        'company_id' => $this->company->id,
        'email' => 'detail.requester@example.com',
        'name' => 'Detail Requester',
        'status' => 'active',
    ]);
    $this->recruiter = User::factory()->create([
        'company_id' => $this->company->id,
        'email' => 'detail.recruiter@example.com',
        'name' => 'Detail Recruiter',
        'status' => 'active',
    ]);

    foreach ([$this->requester, $this->recruiter] as $user) {
        DB::table('company_user')->updateOrInsert(
            ['company_id' => $this->company->id, 'user_id' => $user->id],
            ['status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        );

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);

        foreach ($permissions as $permName) {
            $permission = Permission::query()->firstOrCreate([
                'name' => $permName,
                'guard_name' => 'web',
            ]);
            $user->givePermissionTo($permission);
        }
    }

    $this->client = Client::query()->create([
        'name' => 'Detail Client',
        'is_active' => true,
    ]);
    $this->project = Project::query()->create([
        'title' => 'Detail Project',
        'is_active' => true,
    ]);
    $this->project->clients()->sync([$this->client->id]);
    $this->position = Position::query()->create([
        'company_id' => $this->company->id,
        'title' => 'Able Seaman',
        'status' => 'active',
    ]);
});

function createPresentationRequirement(object $context, array $overrides = []): RecruitmentRequirement
{
    $req = RecruitmentRequirement::query()->create(array_merge([
        'company_id' => $context->company->id,
        'requirement_number' => 'REQ-'.now()->year.'-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
        'client_id' => $context->client->id,
        'project_id' => $context->project->id,
        'request_received_date' => now()->subDay(),
        'required_by_date' => now()->addDays(14),
        'priority' => 'normal',
        'status' => RequirementStatus::Draft,
        'assigned_to' => $context->recruiter->id,
        'created_by' => $context->requester->id,
        'updated_by' => $context->requester->id,
    ], $overrides));

    RecruitmentRequirementLine::query()->create([
        'company_id' => $context->company->id,
        'recruitment_requirement_id' => $req->id,
        'position_id' => $context->position->id,
        'required_headcount' => 2,
        'salary_min' => 5000.00,
        'salary_max' => 8000.00,
        'salary_currency_code' => 'AED',
        'status' => RequirementLineStatus::Open,
    ]);

    return $req->fresh(['lines.position', 'client', 'project']);
}

/**
 * @param  list<array{label: string, value: string}>  $details
 */
function assertLifecycleEmailDetails(array $details): void
{
    $labels = collect($details)->pluck('label')->all();

    expect($labels)->toContain('Requirement')
        ->and($labels)->toContain('Client')
        ->and($labels)->toContain('Project')
        ->and($labels)->toContain('Positions')
        ->and($labels)->toContain('Target Date')
        ->and($labels)->toContain('Priority')
        ->and($labels)->toContain('Requester')
        ->and($labels)->not->toContain('Submitted by')
        ->and($labels)->not->toContain('Required by');
}

test('lifecycle emails use Target Date and omit Submitted by while keeping Requester', function () {
    $req = createPresentationRequirement($this);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->company->id])
        ->post("/organization/recruitment/requirements/{$req->id}/submit")
        ->assertRedirect();

    Mail::assertSent(RequirementSubmittedForApprovalMail::class, function (RequirementSubmittedForApprovalMail $mail) {
        assertLifecycleEmailDetails($mail->details);

        return $mail->hasTo('detail.recruiter@example.com')
            && $mail->hasCc('detail.requester@example.com')
            && collect($mail->details)->firstWhere('label', 'Requester')['value'] === 'Detail Requester';
    });

    Mail::fake();

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->company->id])
        ->post("/organization/recruitment/requirements/{$req->id}/return", [
            'return_reason' => 'Please clarify mobilisation window.',
        ])
        ->assertRedirect();

    Mail::assertSent(RequirementReturnedMail::class, function (RequirementReturnedMail $mail) {
        assertLifecycleEmailDetails($mail->details);

        return $mail->hasTo('detail.requester@example.com');
    });

    Mail::fake();

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->company->id])
        ->post("/organization/recruitment/requirements/{$req->id}/resubmit")
        ->assertRedirect();

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->company->id])
        ->post("/organization/recruitment/requirements/{$req->id}/approve")
        ->assertRedirect();

    Mail::assertSent(RequirementApprovedMail::class, function (RequirementApprovedMail $mail) {
        assertLifecycleEmailDetails($mail->details);

        return $mail->hasTo('detail.requester@example.com');
    });
});

test('removing submitted-by display does not change recipients or submitter audit fields', function () {
    $submitter = User::factory()->create([
        'company_id' => $this->company->id,
        'email' => 'alt.detail.submitter@example.com',
        'name' => 'Alt Detail Submitter',
        'status' => 'active',
    ]);

    DB::table('company_user')->updateOrInsert(
        ['company_id' => $this->company->id, 'user_id' => $submitter->id],
        ['status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    );

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
    foreach ([
        'recruitment.requirements.view',
        'recruitment.requirements.submit',
        'recruitment.requirements.update',
    ] as $permName) {
        $permission = Permission::query()->firstOrCreate([
            'name' => $permName,
            'guard_name' => 'web',
        ]);
        $submitter->givePermissionTo($permission);
    }

    $req = createPresentationRequirement($this);

    $this->actingAs($submitter)
        ->withSession(['current_company_id' => $this->company->id])
        ->post("/organization/recruitment/requirements/{$req->id}/submit")
        ->assertRedirect();

    $fresh = $req->fresh();
    expect($fresh->submitted_by)->toBe($submitter->id)
        ->and($fresh->status)->toBe(RequirementStatus::PendingApproval);

    Mail::assertSent(RequirementSubmittedForApprovalMail::class, function (RequirementSubmittedForApprovalMail $mail) {
        assertLifecycleEmailDetails($mail->details);

        return $mail->hasTo('detail.recruiter@example.com')
            && $mail->hasCc('detail.requester@example.com')
            && $mail->hasCc('alt.detail.submitter@example.com')
            && $mail->submitterName === 'Alt Detail Submitter'
            && collect($mail->details)->firstWhere('label', 'Submitted by') === null
            && collect($mail->details)->firstWhere('label', 'Requester')['value'] === 'Detail Requester';
    });
});

test('requirement show page exposes day-based duration props and fill permission without duplicate action flags', function () {
    $approvedAt = now()->subDays(3);

    $req = createPresentationRequirement($this, [
        'status' => RequirementStatus::Open,
        'approved_at' => $approvedAt,
        'approved_by' => $this->recruiter->id,
        'opened_at' => $approvedAt,
        'assigned_to' => $this->recruiter->id,
    ]);

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->company->id])
        ->get("/organization/recruitment/requirements/{$req->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/recruitment/requirements/show')
            ->where('requirement.id', $req->id)
            ->where('requirement.can_fill', true)
            ->where('requirement.can_approve', false)
            ->where('requirement.can_submit', false)
            ->where('requirement.can_resubmit', false)
            ->where('requirement.can_resume', false)
            ->where('requirement.recruitment_clock_state', 'running')
            ->where('requirement.active_recruitment_seconds', fn ($seconds) => is_int($seconds) && $seconds >= 2 * 86400)
            ->has('requirement.active_recruitment_days')
            ->has('requirement.required_by_date_formatted')
        );
});

test('queued lifecycle email payload still routes using submitter and requester ids', function () {
    Queue::fake();

    $req = createPresentationRequirement($this);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->company->id])
        ->post("/organization/recruitment/requirements/{$req->id}/submit")
        ->assertRedirect();

    /** @var DeliverRequirementLifecycleEmailJob|null $job */
    $job = null;
    Queue::assertPushed(DeliverRequirementLifecycleEmailJob::class, function (DeliverRequirementLifecycleEmailJob $pushed) use (&$job): bool {
        if (($pushed->payload['event'] ?? null) === RequirementLifecycleEmailPayload::EVENT_SUBMITTED) {
            $job = $pushed;

            return true;
        }

        return false;
    });

    expect($job)->not->toBeNull()
        ->and($job->payload['submitter_user_id'] ?? null)->toBe($this->requester->id)
        ->and($job->payload['requester_user_id'] ?? null)->toBe($this->requester->id);

    Mail::fake();
    $job->handle();

    Mail::assertSent(RequirementSubmittedForApprovalMail::class, function (RequirementSubmittedForApprovalMail $mail) {
        assertLifecycleEmailDetails($mail->details);

        return $mail->hasTo('detail.recruiter@example.com');
    });
});
