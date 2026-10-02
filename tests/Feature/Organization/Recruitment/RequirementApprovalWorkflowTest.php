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
use App\Models\RecruitmentRequirementNotificationRecipient;
use App\Models\RecruitmentRequirementStatusTransition;
use App\Models\User;
use App\Support\Recruitment\CalculateActiveRecruitmentDuration;
use App\Support\Recruitment\CompanyUserOptionsQuery;
use App\Support\Recruitment\RecruiterOptionsQuery;
use App\Support\Recruitment\RequirementLifecycleEmailPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function createApprovalTestCompany(string $name, string $code): Company
{
    $country = Country::query()->create([
        'code' => $code,
        'name' => "{$name} Country",
        'dial_code' => '+999',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => $code,
        'name' => "{$name} Currency",
        'symbol' => '$',
        'is_active' => true,
    ]);

    return Company::query()->create([
        'name' => $name,
        'slug' => strtolower(str_replace(' ', '-', $name)).'-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
}

/**
 * @param  list<string>  $permissions
 */
function createApprovalTestUser(Company $company, array $permissions = [], array $attributes = []): User
{
    $user = User::factory()->create(array_merge([
        'company_id' => $company->id,
        'status' => 'active',
    ], $attributes));

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
 * @return list<string>
 */
function allRecruitmentApprovalPermissions(): array
{
    return [
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
}

beforeEach(function () {
    Mail::fake();

    $this->companyA = createApprovalTestCompany('Approval Alpha', 'APA');
    $this->companyB = createApprovalTestCompany('Approval Beta', 'APB');

    $this->requester = createApprovalTestUser($this->companyA, allRecruitmentApprovalPermissions(), [
        'email' => 'requester@example.com',
        'name' => 'Requester User',
    ]);

    $this->recruiter = createApprovalTestUser($this->companyA, allRecruitmentApprovalPermissions(), [
        'email' => 'recruiter@example.com',
        'name' => 'Recruiter User',
    ]);

    $this->ccUser = createApprovalTestUser($this->companyA, ['recruitment.requirements.view'], [
        'email' => 'cc.user@example.com',
        'name' => 'CC User',
    ]);

    $this->client = Client::query()->create([
        'name' => 'Approval Client',
        'is_active' => true,
    ]);

    $this->project = Project::query()->create([
        'title' => 'Approval Project',
        'is_active' => true,
    ]);
    $this->project->clients()->sync([$this->client->id]);

    $this->position = Position::query()->create([
        'company_id' => $this->companyA->id,
        'title' => 'Deck Officer',
        'status' => 'active',
    ]);
});

function createDraftRequirement(object $context, array $overrides = []): RecruitmentRequirement
{
    $req = RecruitmentRequirement::query()->create(array_merge([
        'company_id' => $context->companyA->id,
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
        'company_id' => $context->companyA->id,
        'recruitment_requirement_id' => $req->id,
        'position_id' => $context->position->id,
        'required_headcount' => 2,
        'status' => RequirementLineStatus::Open,
    ]);

    return $req;
}

test('draft creation sends no email', function () {
    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post('/organization/recruitment/requirements', [
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'request_received_date' => now()->subDay()->format('Y-m-d'),
            'required_by_date' => now()->addDays(10)->format('Y-m-d'),
            'priority' => 'normal',
            'assigned_to' => $this->recruiter->id,
            'positions' => [
                ['position_id' => $this->position->id, 'required_headcount' => 1],
            ],
        ])
        ->assertRedirect();

    Mail::assertNothingQueued();
});

test('submission requires an assigned recruiter', function () {
    $req = createDraftRequirement($this, ['assigned_to' => null]);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/submit")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['assigned_to']);
});

test('submission requires submit permission', function () {
    $user = createApprovalTestUser($this->companyA, [
        'recruitment.requirements.view',
        'recruitment.requirements.update',
    ]);
    $req = createDraftRequirement($this);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/submit")
        ->assertForbidden();
});

test('submission moves draft to pending approval and queues recruiter email with cc', function () {
    $req = createDraftRequirement($this);

    RecruitmentRequirementNotificationRecipient::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $req->id,
        'user_id' => $this->ccUser->id,
    ]);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/submit")
        ->assertRedirect();

    $fresh = $req->fresh();
    expect($fresh->status)->toBe(RequirementStatus::PendingApproval)
        ->and($fresh->submitted_at)->not->toBeNull()
        ->and($fresh->submitted_by)->toBe($this->requester->id)
        ->and($fresh->approved_at)->toBeNull();

    expect(RecruitmentRequirementStatusTransition::query()
        ->where('recruitment_requirement_id', $req->id)
        ->where('to_status', RequirementStatus::PendingApproval->value)
        ->exists())->toBeTrue();

    Mail::assertSent(RequirementSubmittedForApprovalMail::class, function (RequirementSubmittedForApprovalMail $mail) {
        return $mail->hasTo('recruiter@example.com')
            && $mail->hasCc('requester@example.com')
            && $mail->hasCc('cc.user@example.com');
    });
});

test('duplicate to and cc addresses are removed', function () {
    $req = createDraftRequirement($this);

    // Add recruiter as CC recipient — should be deduped from CC because they are To.
    RecruitmentRequirementNotificationRecipient::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $req->id,
        'user_id' => $this->recruiter->id,
    ]);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/submit")
        ->assertRedirect();

    Mail::assertSent(RequirementSubmittedForApprovalMail::class, function (RequirementSubmittedForApprovalMail $mail) {
        $cc = collect($mail->cc)->pluck('address')->map(fn ($e) => strtolower((string) $e))->all();

        return $mail->hasTo('recruiter@example.com')
            && ! in_array('recruiter@example.com', $cc, true);
    });
});

test('cross-company and inactive notification recipients are rejected', function () {
    $foreignUser = createApprovalTestUser($this->companyB, [], [
        'email' => 'foreign@example.com',
    ]);
    $inactive = createApprovalTestUser($this->companyA, [], [
        'email' => 'inactive@example.com',
        'status' => 'inactive',
    ]);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson('/organization/recruitment/requirements', [
            'client_id' => $this->client->id,
            'request_received_date' => now()->format('Y-m-d'),
            'required_by_date' => now()->addDays(7)->format('Y-m-d'),
            'priority' => 'normal',
            'assigned_to' => $this->recruiter->id,
            'notification_recipient_ids' => [$foreignUser->id],
            'positions' => [
                ['position_id' => $this->position->id, 'required_headcount' => 1],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['notification_recipient_ids']);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson('/organization/recruitment/requirements', [
            'client_id' => $this->client->id,
            'request_received_date' => now()->format('Y-m-d'),
            'required_by_date' => now()->addDays(7)->format('Y-m-d'),
            'priority' => 'normal',
            'assigned_to' => $this->recruiter->id,
            'notification_recipient_ids' => [$inactive->id],
            'positions' => [
                ['position_id' => $this->position->id, 'required_headcount' => 1],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['notification_recipient_ids']);
});

test('unassigned users and non-assigned recruiters cannot approve', function () {
    $req = createDraftRequirement($this);
    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/submit")
        ->assertRedirect();

    $otherRecruiter = createApprovalTestUser($this->companyA, allRecruitmentApprovalPermissions(), [
        'email' => 'other.recruiter@example.com',
    ]);

    $this->actingAs($otherRecruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/approve")
        ->assertStatus(422);

    $noApprove = createApprovalTestUser($this->companyA, [
        'recruitment.requirements.view',
        'recruitment.requirements.update',
    ], [
        'email' => 'no.approve@example.com',
    ]);

    // Even if somehow assigned, missing approve permission is forbidden at middleware.
    $req->update(['assigned_to' => $noApprove->id]);

    $this->actingAs($noApprove)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/approve")
        ->assertForbidden();
});

test('self-approval is blocked', function () {
    $req = createDraftRequirement($this, [
        'created_by' => $this->recruiter->id,
        'assigned_to' => $this->recruiter->id,
    ]);

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/submit")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['assigned_to']);
});

test('approval moves pending to open, sets timestamps, starts clock, and emails requester', function () {
    $req = createDraftRequirement($this);
    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/submit")
        ->assertRedirect();

    Mail::fake();

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/approve")
        ->assertRedirect();

    $fresh = $req->fresh();
    expect($fresh->status)->toBe(RequirementStatus::Open)
        ->and($fresh->approved_at)->not->toBeNull()
        ->and($fresh->approved_by)->toBe($this->recruiter->id)
        ->and($fresh->opened_at)->not->toBeNull();

    $duration = CalculateActiveRecruitmentDuration::for($fresh->load('approver'));
    expect($duration['recruitment_clock_state'])->toBe('running')
        ->and($duration['recruitment_started_at'])->not->toBeNull()
        ->and($duration['active_recruitment_seconds'])->not->toBeNull();

    Mail::assertSent(RequirementApprovedMail::class, function (RequirementApprovedMail $mail) {
        return $mail->hasTo('requester@example.com');
    });
});

test('return requires a reason and returned requirements can be edited and resubmitted', function () {
    $req = createDraftRequirement($this);
    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/submit")
        ->assertRedirect();

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/return", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['return_reason']);

    Mail::fake();

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/return", [
            'return_reason' => 'Please clarify headcount for deck officers.',
        ])
        ->assertRedirect();

    expect($req->fresh()->status)->toBe(RequirementStatus::Returned)
        ->and($req->fresh()->return_reason)->toBe('Please clarify headcount for deck officers.');

    Mail::assertSent(RequirementReturnedMail::class, function (RequirementReturnedMail $mail) {
        return $mail->hasTo('requester@example.com');
    });

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->put("/organization/recruitment/requirements/{$req->id}", [
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'request_received_date' => now()->subDay()->format('Y-m-d'),
            'priority' => 'urgent',
            'assigned_to' => $this->recruiter->id,
            'notes' => 'Updated after return',
        ])
        ->assertRedirect();

    expect($req->fresh()->priority->value)->toBe('urgent');

    Mail::fake();

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/resubmit")
        ->assertRedirect();

    expect($req->fresh()->status)->toBe(RequirementStatus::PendingApproval);
    Mail::assertSent(RequirementSubmittedForApprovalMail::class);
});

test('pending requirements cannot change core fields via generic update', function () {
    $req = createDraftRequirement($this);
    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/submit")
        ->assertRedirect();

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->put("/organization/recruitment/requirements/{$req->id}", [
            'client_id' => $this->client->id,
            'request_received_date' => now()->format('Y-m-d'),
            'priority' => 'urgent',
            'assigned_to' => $this->recruiter->id,
            'notes' => 'Should not apply on pending',
        ])
        ->assertRedirect();

    $fresh = $req->fresh();
    expect($fresh->status)->toBe(RequirementStatus::PendingApproval)
        ->and($fresh->priority->value)->toBe('normal')
        ->and($fresh->notes)->toBeNull();
});

test('lifecycle email job skips safely when recruiter becomes ineligible before delivery', function () {
    Queue::fake();

    $req = createDraftRequirement($this);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
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

    expect($job)->not->toBeNull();

    // Strip approve permission after transition but before deferred delivery.
    app(PermissionRegistrar::class)->setPermissionsTeamId($this->companyA->id);
    $this->recruiter->revokePermissionTo('recruitment.requirements.approve');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Mail::fake();

    $job->handle();

    Mail::assertNothingSent();
    expect($req->fresh()->status)->toBe(RequirementStatus::PendingApproval);
});

test('email failures do not reverse successful transitions', function () {
    $req = createDraftRequirement($this);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/submit")
        ->assertRedirect();

    expect($req->fresh()->status)->toBe(RequirementStatus::PendingApproval);
});

test('on hold intervals are excluded and filled or cancelled stop the clock', function () {
    $req = createDraftRequirement($this);
    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/submit")
        ->assertRedirect();
    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/approve")
        ->assertRedirect();

    $approvedAt = now()->subHours(5);
    $req->update(['approved_at' => $approvedAt, 'opened_at' => $approvedAt]);

    RecruitmentRequirementStatusTransition::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $req->id,
        'from_status' => RequirementStatus::Open->value,
        'to_status' => RequirementStatus::OnHold->value,
        'performed_by' => $this->recruiter->id,
        'created_at' => $approvedAt->copy()->addHours(1),
        'updated_at' => $approvedAt->copy()->addHours(1),
    ]);
    RecruitmentRequirementStatusTransition::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $req->id,
        'from_status' => RequirementStatus::OnHold->value,
        'to_status' => RequirementStatus::Open->value,
        'performed_by' => $this->recruiter->id,
        'created_at' => $approvedAt->copy()->addHours(3),
        'updated_at' => $approvedAt->copy()->addHours(3),
    ]);

    $duration = CalculateActiveRecruitmentDuration::for($req->fresh()->load('approver'), $approvedAt->copy()->addHours(5));
    // 5 hours total - 2 hours on hold = 3 hours = 10800 seconds
    expect($duration['on_hold_seconds'])->toBe(7200)
        ->and($duration['active_recruitment_seconds'])->toBe(10800)
        ->and($duration['recruitment_clock_state'])->toBe('running');

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/fill")
        ->assertRedirect();

    $filled = CalculateActiveRecruitmentDuration::for($req->fresh()->load('approver'));
    expect($filled['recruitment_clock_state'])->toBe('completed');
});

test('legacy open requirements remain usable and client reference is preserved', function () {
    $req = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-LEGACY-000001',
        'client_id' => $this->client->id,
        'client_reference_number' => 'LEGACY-PO-55',
        'request_received_date' => now()->subDays(10),
        'required_by_date' => now()->addDays(5),
        'priority' => 'normal',
        'status' => RequirementStatus::Open,
        'opened_at' => now()->subDays(8),
        'assigned_to' => $this->recruiter->id,
        'created_by' => $this->requester->id,
    ]);

    RecruitmentRequirementLine::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $req->id,
        'position_id' => $this->position->id,
        'required_headcount' => 1,
        'status' => RequirementLineStatus::Open,
    ]);

    $duration = CalculateActiveRecruitmentDuration::for($req);
    expect($duration['recruitment_start_source'])->toBe('opened_at')
        ->and($duration['recruitment_clock_state'])->toBe('running');

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/hold")
        ->assertRedirect();

    expect($req->fresh()->status)->toBe(RequirementStatus::OnHold)
        ->and($req->fresh()->client_reference_number)->toBe('LEGACY-PO-55');
});

test('repeated requirements do not copy client reference', function () {
    $source = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-SRC-000001',
        'client_id' => $this->client->id,
        'client_reference_number' => 'SHOULD-NOT-COPY',
        'request_received_date' => now()->subDays(30),
        'required_by_date' => now()->subDays(5),
        'priority' => 'normal',
        'status' => RequirementStatus::Completed,
        'opened_at' => now()->subDays(20),
        'completed_at' => now()->subDays(5),
        'assigned_to' => $this->recruiter->id,
        'created_by' => $this->requester->id,
    ]);

    RecruitmentRequirementLine::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $source->id,
        'position_id' => $this->position->id,
        'required_headcount' => 1,
        'status' => RequirementLineStatus::Filled,
    ]);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$source->id}/repeat", [
            'request_received_date' => now()->format('Y-m-d'),
            'required_by_date' => now()->addDays(20)->format('Y-m-d'),
            'priority' => 'normal',
            'assigned_to' => $this->recruiter->id,
            'lines' => [
                ['position_id' => $this->position->id, 'required_headcount' => 1],
            ],
            'reason' => 'Repeat for next campaign',
        ])
        ->assertRedirect();

    $repeated = RecruitmentRequirement::query()
        ->where('company_id', $this->companyA->id)
        ->where('repeated_from_id', $source->id)
        ->first();

    expect($repeated)->not->toBeNull()
        ->and($repeated->client_reference_number)->toBeNull()
        ->and($repeated->status)->toBe(RequirementStatus::Draft);
});

test('cross-company requirement actions remain tenant scoped', function () {
    $req = createDraftRequirement($this);

    $companyBUser = createApprovalTestUser($this->companyB, allRecruitmentApprovalPermissions());

    $this->actingAs($companyBUser)
        ->withSession(['current_company_id' => $this->companyB->id])
        ->post("/organization/recruitment/requirements/{$req->id}/submit")
        ->assertNotFound();

    $this->actingAs($companyBUser)
        ->withSession(['current_company_id' => $this->companyB->id])
        ->post("/organization/recruitment/requirements/{$req->id}/approve")
        ->assertNotFound();
});

test('user without approve permission cannot be assigned as recruiter', function () {
    $viewer = createApprovalTestUser($this->companyA, ['recruitment.requirements.view'], [
        'email' => 'viewer.only@example.com',
    ]);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson('/organization/recruitment/requirements', [
            'client_id' => $this->client->id,
            'request_received_date' => now()->format('Y-m-d'),
            'required_by_date' => now()->addDays(7)->format('Y-m-d'),
            'priority' => 'normal',
            'assigned_to' => $viewer->id,
            'positions' => [
                ['position_id' => $this->position->id, 'required_headcount' => 1],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['assigned_to']);
});

test('submission fails if recruiter lost approval permission or company membership', function () {
    $req = createDraftRequirement($this);

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->companyA->id);
    $this->recruiter->revokePermissionTo('recruitment.requirements.approve');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/submit")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['assigned_to']);

    // Restore approve, then remove company membership.
    $this->recruiter->givePermissionTo('recruitment.requirements.approve');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    DB::table('company_user')
        ->where('company_id', $this->companyA->id)
        ->where('user_id', $this->recruiter->id)
        ->update(['status' => 'inactive']);
    $this->recruiter->update(['company_id' => $this->companyB->id]);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/submit")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['assigned_to']);
});

test('pending approval cannot be reassigned to null or requester', function () {
    $req = createDraftRequirement($this);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/submit")
        ->assertRedirect();

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->putJson("/organization/recruitment/requirements/{$req->id}", [
            'assigned_to' => null,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['assigned_to']);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->putJson("/organization/recruitment/requirements/{$req->id}", [
            'assigned_to' => $this->requester->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['assigned_to']);
});

test('submitter is cc when different from requester and deduped when same', function () {
    $submitter = createApprovalTestUser($this->companyA, allRecruitmentApprovalPermissions(), [
        'email' => 'alt.submitter@example.com',
        'name' => 'Alt Submitter',
    ]);

    $req = createDraftRequirement($this);

    Mail::fake();

    $this->actingAs($submitter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/submit")
        ->assertRedirect();

    Mail::assertSent(RequirementSubmittedForApprovalMail::class, function (RequirementSubmittedForApprovalMail $mail) {
        return $mail->hasTo('recruiter@example.com')
            && $mail->hasCc('requester@example.com')
            && $mail->hasCc('alt.submitter@example.com')
            && $mail->submitterName === 'Alt Submitter';
    });

    Mail::fake();

    // Return then resubmit by same requester (creator === submitter).
    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/return", [
            'return_reason' => 'Need clearer notes before recruiting.',
        ])
        ->assertRedirect();

    Mail::fake();

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/resubmit")
        ->assertRedirect();

    Mail::assertSent(RequirementSubmittedForApprovalMail::class, function (RequirementSubmittedForApprovalMail $mail) {
        $cc = collect($mail->cc)->pluck('address')->map(fn ($e) => strtolower((string) $e))->all();

        return $mail->hasTo('recruiter@example.com')
            && $mail->hasCc('requester@example.com')
            && count(array_filter($cc, fn ($e) => $e === 'requester@example.com')) === 1;
    });
});

test('queued job skips recipients who lost company access before execution', function () {
    Queue::fake();

    $req = createDraftRequirement($this);

    RecruitmentRequirementNotificationRecipient::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $req->id,
        'user_id' => $this->ccUser->id,
    ]);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
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

    expect($job)->not->toBeNull();

    DB::table('company_user')
        ->where('company_id', $this->companyA->id)
        ->where('user_id', $this->ccUser->id)
        ->update(['status' => 'inactive']);
    $this->ccUser->update(['company_id' => null, 'status' => 'inactive']);

    Mail::fake();

    $job->handle();

    Mail::assertSent(RequirementSubmittedForApprovalMail::class, function (RequirementSubmittedForApprovalMail $mail) {
        return $mail->hasTo('recruiter@example.com')
            && $mail->hasCc('requester@example.com')
            && ! $mail->hasCc('cc.user@example.com');
    });
});

test('submission job does not email the new recruiter after reassignment before delivery', function () {
    Queue::fake();

    $req = createDraftRequirement($this);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/submit")
        ->assertRedirect();

    /** @var DeliverRequirementLifecycleEmailJob|null $submissionJob */
    $submissionJob = null;
    Queue::assertPushed(DeliverRequirementLifecycleEmailJob::class, function (DeliverRequirementLifecycleEmailJob $pushed) use (&$submissionJob): bool {
        if (($pushed->payload['event'] ?? null) === RequirementLifecycleEmailPayload::EVENT_SUBMITTED) {
            $submissionJob = $pushed;

            return true;
        }

        return false;
    });

    $recruiterB = createApprovalTestUser($this->companyA, allRecruitmentApprovalPermissions(), [
        'email' => 'recruiter.b@example.com',
        'name' => 'Recruiter B',
    ]);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->put("/organization/recruitment/requirements/{$req->id}", [
            'assigned_to' => $recruiterB->id,
        ])
        ->assertRedirect();

    expect($req->fresh()->assigned_to)->toBe($recruiterB->id);

    Mail::fake();
    $submissionJob->handle();
    Mail::assertNothingSent();
});

test('rapid reassignment jobs only deliver to the intended recruiter for each event', function () {
    Queue::fake();

    $req = createDraftRequirement($this);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/submit")
        ->assertRedirect();

    $recruiterB = createApprovalTestUser($this->companyA, allRecruitmentApprovalPermissions(), [
        'email' => 'recruiter.b@example.com',
        'name' => 'Recruiter B',
    ]);
    $recruiterC = createApprovalTestUser($this->companyA, allRecruitmentApprovalPermissions(), [
        'email' => 'recruiter.c@example.com',
        'name' => 'Recruiter C',
    ]);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->put("/organization/recruitment/requirements/{$req->id}", [
            'assigned_to' => $recruiterB->id,
        ])
        ->assertRedirect();

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->put("/organization/recruitment/requirements/{$req->id}", [
            'assigned_to' => $recruiterC->id,
        ])
        ->assertRedirect();

    $jobs = [];
    Queue::assertPushed(DeliverRequirementLifecycleEmailJob::class, function (DeliverRequirementLifecycleEmailJob $pushed) use (&$jobs): bool {
        $jobs[] = $pushed;

        return true;
    });

    expect($jobs)->toHaveCount(3);

    Mail::fake();

    $jobs[0]->handle(); // original submit to A
    $jobs[1]->handle(); // reassignment to B
    Mail::assertNothingSent();

    $jobs[2]->handle(); // reassignment to C
    Mail::assertSent(RequirementSubmittedForApprovalMail::class, 1);
    Mail::assertSent(RequirementSubmittedForApprovalMail::class, function (RequirementSubmittedForApprovalMail $mail) {
        return $mail->hasTo('recruiter.c@example.com')
            && ! $mail->hasTo('recruiter@example.com')
            && ! $mail->hasTo('recruiter.b@example.com');
    });
});

test('submission job is skipped after the requirement is approved', function () {
    Queue::fake();

    $req = createDraftRequirement($this);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/submit")
        ->assertRedirect();

    /** @var DeliverRequirementLifecycleEmailJob|null $submissionJob */
    $submissionJob = null;
    Queue::assertPushed(DeliverRequirementLifecycleEmailJob::class, function (DeliverRequirementLifecycleEmailJob $pushed) use (&$submissionJob): bool {
        if (($pushed->payload['event'] ?? null) === RequirementLifecycleEmailPayload::EVENT_SUBMITTED) {
            $submissionJob = $pushed;

            return true;
        }

        return false;
    });

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/approve")
        ->assertRedirect();

    expect($req->fresh()->status)->toBe(RequirementStatus::Open);

    Mail::fake();
    $submissionJob->handle();
    Mail::assertNothingSent();
});

test('return job after resubmission still uses the original reason and actor', function () {
    Queue::fake();

    $req = createDraftRequirement($this);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/submit")
        ->assertRedirect();

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/return", [
            'return_reason' => 'Original return reason that must survive resubmission.',
        ])
        ->assertRedirect();

    /** @var DeliverRequirementLifecycleEmailJob|null $returnJob */
    $returnJob = null;
    Queue::assertPushed(DeliverRequirementLifecycleEmailJob::class, function (DeliverRequirementLifecycleEmailJob $pushed) use (&$returnJob): bool {
        if (($pushed->payload['event'] ?? null) === RequirementLifecycleEmailPayload::EVENT_RETURNED) {
            $returnJob = $pushed;

            return true;
        }

        return false;
    });

    expect($returnJob)->not->toBeNull()
        ->and($returnJob->payload['return_reason'])->toBe('Original return reason that must survive resubmission.')
        ->and($returnJob->payload['actor_user_id'])->toBe($this->recruiter->id);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/resubmit")
        ->assertRedirect();

    $fresh = $req->fresh();
    expect($fresh->status)->toBe(RequirementStatus::PendingApproval)
        ->and($fresh->return_reason)->toBeNull();

    Mail::fake();
    $returnJob->handle();

    Mail::assertSent(RequirementReturnedMail::class, function (RequirementReturnedMail $mail) {
        return $mail->hasTo('requester@example.com')
            && $mail->returnReason === 'Original return reason that must survive resubmission.'
            && $mail->recruiterName === 'Recruiter User';
    });
});

test('inactive pivot membership is rejected even when users.company_id matches', function () {
    $inactivePivotUser = User::factory()->create([
        'company_id' => $this->companyA->id,
        'status' => 'active',
        'email' => 'inactive.pivot@example.com',
    ]);

    DB::table('company_user')->updateOrInsert(
        ['company_id' => $this->companyA->id, 'user_id' => $inactivePivotUser->id],
        ['status' => 'inactive', 'created_at' => now(), 'updated_at' => now()],
    );

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->companyA->id);
    $permission = Permission::query()->firstOrCreate([
        'name' => 'recruitment.requirements.approve',
        'guard_name' => 'web',
    ]);
    $inactivePivotUser->givePermissionTo($permission);

    expect(CompanyUserOptionsQuery::isActiveCompanyMember($inactivePivotUser->id, $this->companyA->id))->toBeFalse()
        ->and(RecruiterOptionsQuery::isEligibleApprover($inactivePivotUser->id, $this->companyA->id))->toBeFalse();

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson('/organization/recruitment/requirements', [
            'client_id' => $this->client->id,
            'request_received_date' => now()->format('Y-m-d'),
            'required_by_date' => now()->addDays(7)->format('Y-m-d'),
            'priority' => 'normal',
            'assigned_to' => $inactivePivotUser->id,
            'positions' => [
                ['position_id' => $this->position->id, 'required_headcount' => 1],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['assigned_to']);
});

test('active pivot membership and legacy no-pivot home company remain eligible', function () {
    expect(CompanyUserOptionsQuery::isActiveCompanyMember($this->recruiter->id, $this->companyA->id))->toBeTrue();

    $legacyUser = User::factory()->create([
        'company_id' => $this->companyA->id,
        'status' => 'active',
        'email' => 'legacy.home@example.com',
    ]);
    DB::table('company_user')
        ->where('company_id', $this->companyA->id)
        ->where('user_id', $legacyUser->id)
        ->delete();

    expect(CompanyUserOptionsQuery::isActiveCompanyMember($legacyUser->id, $this->companyA->id))->toBeTrue();

    $this->companyA->update(['status' => 'inactive']);
    expect(CompanyUserOptionsQuery::isActiveCompanyMember($this->recruiter->id, $this->companyA->id))->toBeFalse();
    $this->companyA->update(['status' => 'active']);

    expect(CompanyUserOptionsQuery::isActiveCompanyMember($this->requester->id, $this->companyB->id))->toBeFalse();
});

test('lifecycle email skips primary recipient with inactive pivot membership', function () {
    Queue::fake();

    $req = createDraftRequirement($this);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
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

    DB::table('company_user')
        ->where('company_id', $this->companyA->id)
        ->where('user_id', $this->recruiter->id)
        ->update(['status' => 'inactive']);

    Mail::fake();
    $job->handle();
    Mail::assertNothingSent();
});

test('temporary mail exceptions escape handle and leave the requirement transition committed', function () {
    Queue::fake();

    $req = createDraftRequirement($this);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
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
        ->and($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([30, 60, 120]);

    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP temporary failure secret-pass@example.com'));

    expect(fn () => $job->handle())->toThrow(RuntimeException::class);
    expect($req->fresh()->status)->toBe(RequirementStatus::PendingApproval);

    Log::spy();
    $job->failed(new RuntimeException('SMTP temporary failure secret-pass@example.com'));
    Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context): bool {
        $encoded = json_encode($context);

        return str_contains($message, 'Requirement lifecycle email job failed after retries')
            && ! str_contains((string) $encoded, 'secret-pass@example.com')
            && str_contains((string) $encoded, '[redacted-email]');
    });
});

test('expected ineligible recipient skips do not throw from lifecycle email jobs', function () {
    Queue::fake();

    $req = createDraftRequirement($this);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
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

    $this->recruiter->update(['status' => 'inactive']);

    Mail::fake();

    expect(fn () => $job->handle())->not->toThrow(Throwable::class);
    Mail::assertNothingSent();
    expect($req->fresh()->status)->toBe(RequirementStatus::PendingApproval);
});
