<?php

use App\Enums\Recruitment\RequirementDeadlineExtensionInitiator;
use App\Enums\Recruitment\RequirementDeadlineExtensionStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Mail\RequirementDeadlineExtensionMail;
use App\Models\Client;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Position;
use App\Models\Project;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementDeadlineExtension;
use App\Models\User;
use App\Support\Recruitment\RequirementPresenter;
use Database\Seeders\EmailTemplatesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function createDeadlineExtensionTestCompany(string $name, string $code): Company
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
 */
function createDeadlineExtensionTestUser(Company $company, array $permissions = [], array $attributes = []): User
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
function createOpenDeadlineRequirement(object $test, array $overrides = []): RecruitmentRequirement
{
    return RecruitmentRequirement::query()->create(array_merge([
        'company_id' => $test->companyA->id,
        'requirement_number' => 'REQ-0041',
        'client_id' => $test->client->id,
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
    Mail::fake();
    (new EmailTemplatesSeeder)->run();

    $this->companyA = createDeadlineExtensionTestCompany('Deadline Alpha', 'DLA');
    $this->companyB = createDeadlineExtensionTestCompany('Deadline Beta', 'DLB');

    $this->requester = createDeadlineExtensionTestUser($this->companyA, [
        'recruitment.requirements.view',
        'recruitment.requirements.create',
        'recruitment.requirements.update',
        'recruitment.requirements.submit',
    ], [
        'email' => 'deadline-requester@example.com',
        'name' => 'Mohammed',
    ]);

    $this->recruiter = createDeadlineExtensionTestUser($this->companyA, [
        'recruitment.requirements.view',
        'recruitment.requirements.approve',
        'recruitment.requirements.request_deadline_extension',
    ], [
        'email' => 'deadline-recruiter@example.com',
        'name' => 'Ahmed',
    ]);

    $this->outsider = createDeadlineExtensionTestUser($this->companyA, [
        'recruitment.requirements.view',
        'recruitment.requirements.update',
    ], [
        'email' => 'deadline-outsider@example.com',
        'name' => 'Outsider',
    ]);

    $this->companyBUser = createDeadlineExtensionTestUser($this->companyB, [
        'recruitment.requirements.view',
        'recruitment.requirements.update',
        'recruitment.requirements.request_deadline_extension',
        'recruitment.requirements.create',
    ], [
        'email' => 'deadline-foreign@example.com',
        'name' => 'Foreign User',
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->companyA->id);

    $this->client = Client::query()->create([
        'name' => 'Deadline Client',
        'is_active' => true,
    ]);

    $this->project = Project::query()->create([
        'title' => 'Electrical Engineer Project',
        'is_active' => true,
    ]);
    $this->project->clients()->sync([$this->client->id]);

    Position::query()->create([
        'company_id' => $this->companyA->id,
        'title' => 'Electrical Engineer',
        'status' => 'active',
    ]);
});

test('requester can extend their requirement directly with an optional empty note', function () {
    $req = createOpenDeadlineRequirement($this);
    $oldDate = $req->required_by_date->format('Y-m-d');
    $newDate = now()->addDays(18)->format('Y-m-d');

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/extend-deadline", [
            'new_date' => $newDate,
            'reason' => '',
        ])
        ->assertRedirect();

    expect($req->fresh()->required_by_date->format('Y-m-d'))->toBe($newDate);

    $extension = RecruitmentRequirementDeadlineExtension::query()
        ->where('recruitment_requirement_id', $req->id)
        ->first();

    expect($extension)->not->toBeNull()
        ->and($extension->initiator)->toBe(RequirementDeadlineExtensionInitiator::Requester)
        ->and($extension->status)->toBe(RequirementDeadlineExtensionStatus::Approved)
        ->and($extension->reason)->toBeNull()
        ->and($extension->old_deadline->format('Y-m-d'))->toBe($oldDate)
        ->and($extension->requested_deadline->format('Y-m-d'))->toBe($newDate)
        ->and($extension->decided_by)->toBe($this->requester->id);

    expect(Activity::query()->where('description', 'like', 'Deadline extended by requester%')->exists())->toBeTrue();

    Mail::assertSent(RequirementDeadlineExtensionMail::class, function (RequirementDeadlineExtensionMail $mail) {
        return $mail->hasTo('deadline-recruiter@example.com')
            && str_contains($mail->heading, 'updated by requester')
            && str_contains($mail->requirementNumber, 'REQ-0041');
    });
});

test('requester direct extension requires a new deadline', function () {
    $req = createOpenDeadlineRequirement($this);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/extend-deadline", [
            'reason' => 'Optional note',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['new_date']);
});

test('unauthorized user cannot directly extend a deadline', function () {
    $req = createOpenDeadlineRequirement($this);
    $newDate = now()->addDays(20)->format('Y-m-d');

    $this->actingAs($this->outsider)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/extend-deadline", [
            'new_date' => $newDate,
            'reason' => 'Trying to change someone else deadline',
        ])
        ->assertForbidden();

    expect($req->fresh()->required_by_date->format('Y-m-d'))
        ->toBe($req->required_by_date->format('Y-m-d'));
});

test('recruiter can request an extension without changing the official deadline', function () {
    $req = createOpenDeadlineRequirement($this);
    $oldDate = $req->required_by_date->format('Y-m-d');
    $newDate = now()->addDays(18)->format('Y-m-d');

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/extend-deadline", [
            'new_date' => $newDate,
            'reason' => 'Candidate availability requires additional sourcing time.',
        ])
        ->assertRedirect();

    expect($req->fresh()->required_by_date->format('Y-m-d'))->toBe($oldDate);

    $extension = RecruitmentRequirementDeadlineExtension::query()
        ->where('recruitment_requirement_id', $req->id)
        ->first();

    expect($extension)->not->toBeNull()
        ->and($extension->status)->toBe(RequirementDeadlineExtensionStatus::Pending)
        ->and($extension->initiator)->toBe(RequirementDeadlineExtensionInitiator::Recruiter)
        ->and($extension->reason)->toBe('Candidate availability requires additional sourcing time.')
        ->and($extension->requested_by)->toBe($this->recruiter->id);

    Mail::assertSent(RequirementDeadlineExtensionMail::class, function (RequirementDeadlineExtensionMail $mail) {
        return $mail->hasTo('deadline-requester@example.com')
            && str_contains($mail->heading, 'requires your approval');
    });
});

test('recruiter reason is mandatory and duplicate pending requests are blocked', function () {
    $req = createOpenDeadlineRequirement($this);
    $newDate = now()->addDays(18)->format('Y-m-d');

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/extend-deadline", [
            'new_date' => $newDate,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/extend-deadline", [
            'new_date' => $newDate,
            'reason' => 'Need more sourcing time for this campaign.',
        ])
        ->assertRedirect();

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/extend-deadline", [
            'new_date' => now()->addDays(21)->format('Y-m-d'),
            'reason' => 'A second overlapping request should be blocked.',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);

    expect(RecruitmentRequirementDeadlineExtension::query()->where('recruitment_requirement_id', $req->id)->count())->toBe(1);
});

test('requester can approve a pending extension and the official deadline updates', function () {
    $req = createOpenDeadlineRequirement($this);
    $oldDate = $req->required_by_date->format('Y-m-d');
    $newDate = now()->addDays(18)->format('Y-m-d');

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/extend-deadline", [
            'new_date' => $newDate,
            'reason' => 'Candidate availability requires additional sourcing time.',
        ])
        ->assertRedirect();

    $extension = RecruitmentRequirementDeadlineExtension::query()
        ->where('recruitment_requirement_id', $req->id)
        ->firstOrFail();

    Mail::fake();

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/deadline-extensions/{$extension->id}/approve")
        ->assertRedirect();

    expect($req->fresh()->required_by_date->format('Y-m-d'))->toBe($newDate)
        ->and($extension->fresh()->status)->toBe(RequirementDeadlineExtensionStatus::Approved)
        ->and($extension->fresh()->decided_by)->toBe($this->requester->id);

    expect(Activity::query()->where('description', 'like', 'Deadline extension approved%')->exists())->toBeTrue();

    Mail::assertSent(RequirementDeadlineExtensionMail::class, function (RequirementDeadlineExtensionMail $mail) {
        return $mail->hasTo('deadline-recruiter@example.com')
            && str_contains($mail->heading, 'approved');
    });

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/deadline-extensions/{$extension->id}/approve")
        ->assertForbidden();
});

test('non-requester cannot approve and already-approved requests cannot be approved again', function () {
    $req = createOpenDeadlineRequirement($this);
    $newDate = now()->addDays(18)->format('Y-m-d');

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/extend-deadline", [
            'new_date' => $newDate,
            'reason' => 'Need additional sourcing time for this requisition.',
        ])
        ->assertRedirect();

    $extension = RecruitmentRequirementDeadlineExtension::query()
        ->where('recruitment_requirement_id', $req->id)
        ->firstOrFail();

    $this->actingAs($this->outsider)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/deadline-extensions/{$extension->id}/approve")
        ->assertForbidden();

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/deadline-extensions/{$extension->id}/approve")
        ->assertForbidden();

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/deadline-extensions/{$extension->id}/approve")
        ->assertRedirect();

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/deadline-extensions/{$extension->id}/approve")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

test('requester can reject a pending extension and the official deadline stays unchanged', function () {
    $req = createOpenDeadlineRequirement($this);
    $oldDate = $req->required_by_date->format('Y-m-d');
    $newDate = now()->addDays(18)->format('Y-m-d');

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/extend-deadline", [
            'new_date' => $newDate,
            'reason' => 'Need additional sourcing time for this requisition.',
        ])
        ->assertRedirect();

    $extension = RecruitmentRequirementDeadlineExtension::query()
        ->where('recruitment_requirement_id', $req->id)
        ->firstOrFail();

    Mail::fake();

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/deadline-extensions/{$extension->id}/reject")
        ->assertRedirect();

    expect($req->fresh()->required_by_date->format('Y-m-d'))->toBe($oldDate)
        ->and($extension->fresh()->status)->toBe(RequirementDeadlineExtensionStatus::Rejected)
        ->and(RecruitmentRequirementDeadlineExtension::query()->whereKey($extension->id)->exists())->toBeTrue();

    Mail::assertSent(RequirementDeadlineExtensionMail::class, function (RequirementDeadlineExtensionMail $mail) {
        return $mail->hasTo('deadline-recruiter@example.com')
            && str_contains($mail->heading, 'rejected');
    });

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/deadline-extensions/{$extension->id}/approve")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

test('cross-company deadline extension create approve and reject are isolated', function () {
    $req = createOpenDeadlineRequirement($this);
    $newDate = now()->addDays(18)->format('Y-m-d');

    $this->actingAs($this->companyBUser)
        ->withSession(['current_company_id' => $this->companyB->id])
        ->post("/organization/recruitment/requirements/{$req->id}/extend-deadline", [
            'new_date' => $newDate,
            'reason' => 'Cross-company request should not succeed.',
        ])
        ->assertNotFound();

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/extend-deadline", [
            'new_date' => $newDate,
            'reason' => 'Need additional sourcing time for this requisition.',
        ])
        ->assertRedirect();

    $extension = RecruitmentRequirementDeadlineExtension::query()
        ->where('recruitment_requirement_id', $req->id)
        ->firstOrFail();

    $this->actingAs($this->companyBUser)
        ->withSession(['current_company_id' => $this->companyB->id])
        ->post("/organization/recruitment/requirements/{$req->id}/deadline-extensions/{$extension->id}/approve")
        ->assertNotFound();

    $this->actingAs($this->companyBUser)
        ->withSession(['current_company_id' => $this->companyB->id])
        ->post("/organization/recruitment/requirements/{$req->id}/deadline-extensions/{$extension->id}/reject")
        ->assertNotFound();

    $foreignRequirement = createOpenDeadlineRequirement($this, [
        'company_id' => $this->companyB->id,
        'requirement_number' => 'REQ-B-0041',
        'created_by' => $this->companyBUser->id,
        'assigned_to' => $this->companyBUser->id,
    ]);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$foreignRequirement->id}/extend-deadline", [
            'new_date' => $newDate,
            'reason' => 'Cross-company direct extension should not succeed.',
        ])
        ->assertNotFound();
});

test('a stale extension request cannot overwrite a deadline that changed after it was created', function () {
    $req = createOpenDeadlineRequirement($this);
    $oldDate = $req->required_by_date->format('Y-m-d');
    $requestedDate = now()->addDays(18)->format('Y-m-d');
    $laterDate = now()->addDays(25)->format('Y-m-d');

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/extend-deadline", [
            'new_date' => $requestedDate,
            'reason' => 'Need additional sourcing time for this requisition.',
        ])
        ->assertRedirect();

    $extension = RecruitmentRequirementDeadlineExtension::query()
        ->where('recruitment_requirement_id', $req->id)
        ->firstOrFail();

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/extend-deadline", [
            'new_date' => $laterDate,
        ])
        ->assertRedirect();

    expect($req->fresh()->required_by_date->format('Y-m-d'))->toBe($laterDate)
        ->and($extension->fresh()->status)->toBe(RequirementDeadlineExtensionStatus::Cancelled);

    $stale = RecruitmentRequirementDeadlineExtension::query()->create([
        'company_id' => $req->company_id,
        'recruitment_requirement_id' => $req->id,
        'requested_by' => $this->recruiter->id,
        'initiator' => RequirementDeadlineExtensionInitiator::Recruiter,
        'old_deadline' => $oldDate,
        'requested_deadline' => $requestedDate,
        'reason' => 'Stale request after a later deadline change.',
        'status' => RequirementDeadlineExtensionStatus::Pending,
    ]);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/deadline-extensions/{$stale->id}/approve")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);

    expect($req->fresh()->required_by_date->format('Y-m-d'))->toBe($laterDate)
        ->and($stale->fresh()->status)->toBe(RequirementDeadlineExtensionStatus::Pending);
});

test('completed and cancelled requirements cannot receive deadline extensions', function () {
    $completed = createOpenDeadlineRequirement($this, [
        'requirement_number' => 'REQ-0042',
        'status' => RequirementStatus::Completed,
        'completed_at' => now(),
    ]);
    $cancelled = createOpenDeadlineRequirement($this, [
        'requirement_number' => 'REQ-0043',
        'status' => RequirementStatus::Cancelled,
        'cancelled_at' => now(),
        'cancellation_reason' => 'No longer required',
    ]);
    $newDate = now()->addDays(20)->format('Y-m-d');

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$completed->id}/extend-deadline", [
            'new_date' => $newDate,
        ])
        ->assertForbidden();

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$cancelled->id}/extend-deadline", [
            'new_date' => $newDate,
            'reason' => 'Should not be allowed on cancelled requirements.',
        ])
        ->assertForbidden();
});

test('requirement show presents pending extension review props for the requester', function () {
    $req = createOpenDeadlineRequirement($this);
    $newDate = now()->addDays(18)->format('Y-m-d');

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/extend-deadline", [
            'new_date' => $newDate,
            'reason' => 'Candidate availability requires additional sourcing time.',
        ])
        ->assertRedirect();

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get("/organization/recruitment/requirements/{$req->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/recruitment/requirements/show')
            ->where('requirement.can_decide_deadline_extension', true)
            ->where('requirement.pending_deadline_extension.status', 'pending')
            ->where('requirement.deadline_extension_mode', 'direct')
        );

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get("/organization/recruitment/requirements/{$req->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('requirement.can_decide_deadline_extension', false)
            ->where('requirement.can_extend', false)
            ->where('requirement.pending_deadline_extension.status', 'pending')
        );

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get('/organization/recruitment/requirements?needs_action=deadline_extension')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('requirements.data', 1)
            ->where('requirements.data.0.id', $req->id)
        );
});

test('presenter flags keep recruiter request mode distinct from requester direct extend', function () {
    $req = createOpenDeadlineRequirement($this);
    $req->load(['client', 'project', 'assignedRecruiter', 'lines.position', 'pendingDeadlineExtension']);

    $requesterData = RequirementPresenter::toShow($req, user: $this->requester);
    $recruiterData = RequirementPresenter::toShow($req, user: $this->recruiter);
    $outsiderData = RequirementPresenter::toShow($req, user: $this->outsider);

    expect($requesterData['deadline_extension_mode'])->toBe('direct')
        ->and($requesterData['can_extend'])->toBeTrue()
        ->and($recruiterData['deadline_extension_mode'])->toBe('request')
        ->and($recruiterData['can_extend'])->toBeTrue()
        ->and($outsiderData['can_extend'])->toBeFalse()
        ->and($outsiderData['deadline_extension_mode'])->toBeNull();
});
