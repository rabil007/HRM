<?php

use App\Enums\Recruitment\RequirementDeadlineExtensionInitiator;
use App\Enums\Recruitment\RequirementDeadlineExtensionStatus;
use App\Enums\Recruitment\RequirementHeadcountRevisionInitiator;
use App\Enums\Recruitment\RequirementHeadcountRevisionStatus;
use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Jobs\DeliverRequirementOwnershipTransferEmailJob;
use App\Mail\RequirementOwnershipTransferMail;
use App\Models\Client;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Position;
use App\Models\Project;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementDeadlineExtension;
use App\Models\RecruitmentRequirementHeadcountRevision;
use App\Models\RecruitmentRequirementHeadcountRevisionLine;
use App\Models\RecruitmentRequirementLine;
use App\Models\RecruitmentRequirementStatusTransition;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function createOwnershipTransferTestCompany(string $name, string $code): Company
{
    $country = Country::query()->create([
        'code' => $code,
        'name' => "{$name} Country",
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => $code,
        'name' => "{$name} Currency",
        'symbol' => 'D',
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
 * @param  array<string, mixed>  $attributes
 */
function createOwnershipTransferTestUser(Company $company, array $permissions = [], array $attributes = []): User
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
 * @param  array<string, mixed>  $overrides
 */
function createOwnershipTransferRequirement(object $test, array $overrides = []): RecruitmentRequirement
{
    return RecruitmentRequirement::query()->create(array_merge([
        'company_id' => $test->companyA->id,
        'requirement_number' => 'REQ-OT-001',
        'client_id' => $test->client->id,
        'project_id' => $test->project->id,
        'request_received_date' => now()->subDays(10)->startOfDay(),
        'required_by_date' => now()->addDays(8)->startOfDay(),
        'priority' => 'normal',
        'status' => RequirementStatus::Open,
        'created_by' => $test->requester->id,
        'assigned_to' => $test->recruiter->id,
        'opened_at' => now(),
    ], $overrides));
}

beforeEach(function () {
    $this->companyA = createOwnershipTransferTestCompany('Ownership Transfer Co A', 'OTA');
    $this->companyB = createOwnershipTransferTestCompany('Ownership Transfer Co B', 'OTB');

    $this->client = Client::query()->create([
        'name' => 'Ownership Client',
        'is_active' => true,
    ]);
    $this->project = Project::query()->create([
        'title' => 'Ownership Project',
        'is_active' => true,
    ]);
    $this->project->clients()->sync([$this->client->id]);

    $this->position = Position::query()->create([
        'company_id' => $this->companyA->id,
        'title' => 'Welder',
        'status' => 'active',
    ]);

    $this->requester = createOwnershipTransferTestUser($this->companyA, [
        'recruitment.requirements.view',
        'recruitment.requirements.update',
        'recruitment.requirements.submit',
    ]);
    $this->recruiter = createOwnershipTransferTestUser($this->companyA, [
        'recruitment.requirements.view',
        'recruitment.requirements.approve',
        'recruitment.requirements.request_deadline_extension',
        'recruitment.requirements.request_headcount_revision',
    ]);
    $this->admin = createOwnershipTransferTestUser($this->companyA, [
        'recruitment.requirements.view',
        'recruitment.requirements.transfer_ownership',
    ]);
    $this->viewer = createOwnershipTransferTestUser($this->companyA, [
        'recruitment.requirements.view',
    ]);
    $this->replacementRequester = createOwnershipTransferTestUser($this->companyA, [
        'recruitment.requirements.view',
        'recruitment.requirements.update',
        'recruitment.requirements.submit',
    ]);
    $this->replacementRecruiter = createOwnershipTransferTestUser($this->companyA, [
        'recruitment.requirements.view',
        'recruitment.requirements.approve',
        'recruitment.requirements.request_deadline_extension',
        'recruitment.requirements.request_headcount_revision',
    ]);
    $this->companyBUser = createOwnershipTransferTestUser($this->companyB, [
        'recruitment.requirements.view',
        'recruitment.requirements.transfer_ownership',
        'recruitment.requirements.approve',
    ]);
});

test('unauthorized users cannot transfer ownership', function () {
    $req = createOwnershipTransferRequirement($this);

    $this->actingAs($this->viewer)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/transfer-ownership", [
            'created_by' => $this->replacementRequester->id,
            'assigned_to' => $this->replacementRecruiter->id,
            'reason' => 'Requester left the company.',
        ])
        ->assertForbidden();

    expect($req->fresh()->created_by)->toBe($this->requester->id)
        ->and($req->fresh()->assigned_to)->toBe($this->recruiter->id);
});

test('cross-company and inactive users are rejected for ownership transfer', function () {
    $req = createOwnershipTransferRequirement($this);
    $inactive = createOwnershipTransferTestUser($this->companyA, [
        'recruitment.requirements.view',
        'recruitment.requirements.update',
    ], ['status' => 'inactive']);

    $this->actingAs($this->admin)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/transfer-ownership", [
            'created_by' => $this->companyBUser->id,
            'assigned_to' => $this->replacementRecruiter->id,
            'reason' => 'Cross-company requester should fail.',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['created_by']);

    $this->actingAs($this->admin)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/transfer-ownership", [
            'created_by' => $inactive->id,
            'assigned_to' => $this->replacementRecruiter->id,
            'reason' => 'Inactive requester should fail.',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['created_by']);

    $this->actingAs($this->admin)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/transfer-ownership", [
            'created_by' => $this->replacementRequester->id,
            'assigned_to' => $this->companyBUser->id,
            'reason' => 'Cross-company recruiter should fail.',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['assigned_to']);
});

test('self-approval remains impossible after ownership transfer', function () {
    $req = createOwnershipTransferRequirement($this);

    $this->actingAs($this->admin)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/transfer-ownership", [
            'created_by' => $this->replacementRequester->id,
            'assigned_to' => $this->replacementRequester->id,
            'reason' => 'Would permit self-approval.',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['assigned_to']);
});

test('eligible ownership transfer works for every supported status', function (RequirementStatus $status) {
    Queue::fake([DeliverRequirementOwnershipTransferEmailJob::class]);

    $req = createOwnershipTransferRequirement($this, [
        'requirement_number' => 'REQ-OT-'.$status->value,
        'status' => $status,
        'opened_at' => in_array($status, [RequirementStatus::Open, RequirementStatus::OnHold], true) ? now() : null,
        'submitted_at' => $status === RequirementStatus::PendingApproval ? now() : null,
    ]);

    $this->actingAs($this->admin)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/transfer-ownership", [
            'created_by' => $this->replacementRequester->id,
            'assigned_to' => $this->replacementRecruiter->id,
            'reason' => "Transfer while {$status->value}.",
        ])
        ->assertRedirect();

    $fresh = $req->fresh();
    expect($fresh->created_by)->toBe($this->replacementRequester->id)
        ->and($fresh->assigned_to)->toBe($this->replacementRecruiter->id)
        ->and($fresh->status)->toBe($status);

    expect(RecruitmentRequirementStatusTransition::query()
        ->where('recruitment_requirement_id', $req->id)
        ->where('reason', 'Ownership transferred')
        ->exists())->toBeTrue();

    expect(Activity::query()
        ->where('description', "Ownership transferred for requirement {$req->requirement_number}.")
        ->exists())->toBeTrue();

    Queue::assertPushed(DeliverRequirementOwnershipTransferEmailJob::class, 2);
})->with([
    RequirementStatus::Draft,
    RequirementStatus::Returned,
    RequirementStatus::PendingApproval,
    RequirementStatus::Open,
    RequirementStatus::OnHold,
]);

test('filled and cancelled requirements cannot transfer ownership', function (RequirementStatus $status) {
    $req = createOwnershipTransferRequirement($this, [
        'status' => $status,
        'completed_at' => $status === RequirementStatus::Completed ? now() : null,
        'cancelled_at' => $status === RequirementStatus::Cancelled ? now() : null,
        'cancellation_reason' => $status === RequirementStatus::Cancelled ? 'Done' : null,
    ]);

    $this->actingAs($this->admin)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/transfer-ownership", [
            'created_by' => $this->replacementRequester->id,
            'assigned_to' => $this->replacementRecruiter->id,
            'reason' => 'Should not transfer terminal requirements.',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
})->with([
    RequirementStatus::Completed,
    RequirementStatus::Cancelled,
]);

test('pending decisions become available to the correct replacement user', function () {
    Mail::fake();

    $req = createOwnershipTransferRequirement($this);
    RecruitmentRequirementLine::query()->create([
        'company_id' => $req->company_id,
        'recruitment_requirement_id' => $req->id,
        'position_id' => $this->position->id,
        'required_headcount' => 5,
        'status' => RequirementLineStatus::Open,
    ]);

    $extension = RecruitmentRequirementDeadlineExtension::query()->create([
        'company_id' => $req->company_id,
        'recruitment_requirement_id' => $req->id,
        'requested_by' => $this->recruiter->id,
        'initiator' => RequirementDeadlineExtensionInitiator::Recruiter,
        'old_deadline' => $req->required_by_date,
        'requested_deadline' => now()->addDays(20)->startOfDay(),
        'reason' => 'Need more sourcing time.',
        'status' => RequirementDeadlineExtensionStatus::Pending,
    ]);

    $revision = RecruitmentRequirementHeadcountRevision::query()->create([
        'company_id' => $req->company_id,
        'recruitment_requirement_id' => $req->id,
        'requested_by' => $this->recruiter->id,
        'initiator' => RequirementHeadcountRevisionInitiator::Recruiter,
        'reason' => 'Client asked for more welders.',
        'status' => RequirementHeadcountRevisionStatus::Pending,
    ]);
    RecruitmentRequirementHeadcountRevisionLine::query()->create([
        'company_id' => $req->company_id,
        'headcount_revision_id' => $revision->id,
        'recruitment_requirement_line_id' => $req->lines()->first()->id,
        'position_id' => $this->position->id,
        'position_title' => 'Welder',
        'old_headcount' => 5,
        'requested_headcount' => 8,
    ]);

    $this->actingAs($this->admin)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/transfer-ownership", [
            'created_by' => $this->replacementRequester->id,
            'assigned_to' => $this->replacementRecruiter->id,
            'reason' => 'Original requester unavailable.',
        ])
        ->assertRedirect();

    expect($extension->fresh()->status)->toBe(RequirementDeadlineExtensionStatus::Pending)
        ->and($revision->fresh()->status)->toBe(RequirementHeadcountRevisionStatus::Pending)
        ->and(RecruitmentRequirementDeadlineExtension::query()->count())->toBe(1)
        ->and(RecruitmentRequirementHeadcountRevision::query()->count())->toBe(1);

    $this->actingAs($this->replacementRequester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get("/organization/recruitment/requirements/{$req->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('requirement.can_decide_deadline_extension', true)
            ->where('requirement.can_decide_headcount_revision', true)
        );

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get("/organization/recruitment/requirements/{$req->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('requirement.can_decide_deadline_extension', false)
            ->where('requirement.can_decide_headcount_revision', false)
        );

    $this->actingAs($this->replacementRequester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/deadline-extensions/{$extension->id}/approve")
        ->assertRedirect();

    expect($extension->fresh()->status)->toBe(RequirementDeadlineExtensionStatus::Approved);
});

test('ownership transfer notifies the new responsible users after commit', function () {
    Mail::fake();

    $req = createOwnershipTransferRequirement($this);

    $this->actingAs($this->admin)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/transfer-ownership", [
            'created_by' => $this->replacementRequester->id,
            'assigned_to' => $this->replacementRecruiter->id,
            'reason' => 'Notify replacement owners.',
        ])
        ->assertRedirect();

    (new DeliverRequirementOwnershipTransferEmailJob([
        'requirement_id' => $req->id,
        'company_id' => $this->companyA->id,
        'status_transition_id' => RecruitmentRequirementStatusTransition::query()
            ->where('recruitment_requirement_id', $req->id)
            ->where('reason', 'Ownership transferred')
            ->value('id'),
        'primary_recipient_user_id' => $this->replacementRequester->id,
        'role' => 'requester',
        'expected_requester_id' => $this->replacementRequester->id,
        'expected_recruiter_id' => $this->replacementRecruiter->id,
        'expected_status' => RequirementStatus::Open->value,
    ]))->handle();

    Mail::assertSent(RequirementOwnershipTransferMail::class, function (RequirementOwnershipTransferMail $mail) {
        return str_contains($mail->subjectLine, 'requester');
    });
});

test('show page exposes transfer ownership capability for authorized users', function () {
    $req = createOwnershipTransferRequirement($this);

    $this->actingAs($this->admin)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get("/organization/recruitment/requirements/{$req->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('requirement.can_transfer_ownership', true)
            ->where('can.transfer_ownership', true)
            ->has('options.requesters')
        );

    $this->actingAs($this->viewer)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get("/organization/recruitment/requirements/{$req->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('requirement.can_transfer_ownership', false)
            ->where('can.transfer_ownership', false)
        );
});

test('deadline requester transfer to the extension initiator is rejected', function () {
    $req = createOwnershipTransferRequirement($this);
    RecruitmentRequirementDeadlineExtension::query()->create([
        'company_id' => $req->company_id,
        'recruitment_requirement_id' => $req->id,
        'requested_by' => $this->recruiter->id,
        'initiator' => RequirementDeadlineExtensionInitiator::Recruiter,
        'old_deadline' => $req->required_by_date,
        'requested_deadline' => now()->addDays(20)->startOfDay(),
        'reason' => 'Need more sourcing time.',
        'status' => RequirementDeadlineExtensionStatus::Pending,
    ]);

    $this->actingAs($this->admin)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/transfer-ownership", [
            'created_by' => $this->recruiter->id,
            'assigned_to' => $this->replacementRecruiter->id,
            'reason' => 'Would let extension initiator self-approve.',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['created_by']);

    expect($req->fresh()->created_by)->toBe($this->requester->id);
});

test('headcount requester transfer to recruiter-originated revision initiator is rejected', function () {
    $req = createOwnershipTransferRequirement($this);
    $line = RecruitmentRequirementLine::query()->create([
        'company_id' => $req->company_id,
        'recruitment_requirement_id' => $req->id,
        'position_id' => $this->position->id,
        'required_headcount' => 5,
        'status' => RequirementLineStatus::Open,
    ]);
    $revision = RecruitmentRequirementHeadcountRevision::query()->create([
        'company_id' => $req->company_id,
        'recruitment_requirement_id' => $req->id,
        'requested_by' => $this->recruiter->id,
        'initiator' => RequirementHeadcountRevisionInitiator::Recruiter,
        'reason' => 'Client asked for more welders.',
        'status' => RequirementHeadcountRevisionStatus::Pending,
    ]);
    RecruitmentRequirementHeadcountRevisionLine::query()->create([
        'company_id' => $req->company_id,
        'headcount_revision_id' => $revision->id,
        'recruitment_requirement_line_id' => $line->id,
        'position_id' => $this->position->id,
        'position_title' => 'Welder',
        'old_headcount' => 5,
        'requested_headcount' => 8,
    ]);

    $this->actingAs($this->admin)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/transfer-ownership", [
            'created_by' => $this->recruiter->id,
            'assigned_to' => $this->replacementRecruiter->id,
            'reason' => 'Would let revision initiator self-approve.',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['created_by']);

    expect($req->fresh()->created_by)->toBe($this->requester->id);
});

test('recruiter transfer to requester-originated revision initiator is rejected', function () {
    $req = createOwnershipTransferRequirement($this);
    $line = RecruitmentRequirementLine::query()->create([
        'company_id' => $req->company_id,
        'recruitment_requirement_id' => $req->id,
        'position_id' => $this->position->id,
        'required_headcount' => 5,
        'status' => RequirementLineStatus::Open,
    ]);
    $revision = RecruitmentRequirementHeadcountRevision::query()->create([
        'company_id' => $req->company_id,
        'recruitment_requirement_id' => $req->id,
        'requested_by' => $this->requester->id,
        'initiator' => RequirementHeadcountRevisionInitiator::Requester,
        'reason' => 'Need more headcount.',
        'status' => RequirementHeadcountRevisionStatus::Pending,
    ]);
    RecruitmentRequirementHeadcountRevisionLine::query()->create([
        'company_id' => $req->company_id,
        'headcount_revision_id' => $revision->id,
        'recruitment_requirement_line_id' => $line->id,
        'position_id' => $this->position->id,
        'position_title' => 'Welder',
        'old_headcount' => 5,
        'requested_headcount' => 8,
    ]);

    $this->actingAs($this->admin)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/transfer-ownership", [
            'created_by' => $this->replacementRequester->id,
            'assigned_to' => $this->requester->id,
            'reason' => 'Would let revision initiator self-approve.',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['assigned_to']);

    expect($req->fresh()->assigned_to)->toBe($this->recruiter->id);
});

test('unrelated eligible replacement users can still receive ownership with pending requests', function () {
    Queue::fake([DeliverRequirementOwnershipTransferEmailJob::class]);

    $req = createOwnershipTransferRequirement($this);
    RecruitmentRequirementDeadlineExtension::query()->create([
        'company_id' => $req->company_id,
        'recruitment_requirement_id' => $req->id,
        'requested_by' => $this->recruiter->id,
        'initiator' => RequirementDeadlineExtensionInitiator::Recruiter,
        'old_deadline' => $req->required_by_date,
        'requested_deadline' => now()->addDays(20)->startOfDay(),
        'reason' => 'Need more sourcing time.',
        'status' => RequirementDeadlineExtensionStatus::Pending,
    ]);

    $this->actingAs($this->admin)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/transfer-ownership", [
            'created_by' => $this->replacementRequester->id,
            'assigned_to' => $this->replacementRecruiter->id,
            'reason' => 'Safe replacement owners.',
        ])
        ->assertRedirect();

    expect($req->fresh()->created_by)->toBe($this->replacementRequester->id)
        ->and($req->fresh()->assigned_to)->toBe($this->replacementRecruiter->id);
});

test('cross-company requirement ownership transfer is not found', function () {
    $req = createOwnershipTransferRequirement($this);

    $this->actingAs($this->companyBUser)
        ->withSession(['current_company_id' => $this->companyB->id])
        ->post("/organization/recruitment/requirements/{$req->id}/transfer-ownership", [
            'created_by' => $this->companyBUser->id,
            'assigned_to' => $this->companyBUser->id,
            'reason' => 'Cross-company transfer attempt.',
        ])
        ->assertNotFound();
});
