<?php

use App\Enums\Recruitment\RequirementHeadcountRevisionInitiator;
use App\Enums\Recruitment\RequirementHeadcountRevisionStatus;
use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Mail\RequirementHeadcountRevisionMail;
use App\Models\Client;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Position;
use App\Models\Project;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementHeadcountRevision;
use App\Models\RecruitmentRequirementLine;
use App\Models\User;
use App\Support\Recruitment\RecruiterOptionsQuery;
use Database\Seeders\EmailTemplatesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function createHeadcountRevisionTestCompany(string $name, string $code): Company
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
function createHeadcountRevisionTestUser(Company $company, array $permissions = [], array $attributes = []): User
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
function createHeadcountRevisionRequirement(object $test, array $overrides = []): RecruitmentRequirement
{
    return RecruitmentRequirement::query()->create(array_merge([
        'company_id' => $test->companyA->id,
        'requirement_number' => 'REQ-0041',
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

/**
 * @return array{welder: RecruitmentRequirementLine, electrician: RecruitmentRequirementLine, rigger: RecruitmentRequirementLine}
 */
function createHeadcountRevisionLines(object $test, RecruitmentRequirement $requirement, int $welder = 5, int $electrician = 3, int $rigger = 4): array
{
    $make = function (Position $position, int $headcount) use ($requirement): RecruitmentRequirementLine {
        return RecruitmentRequirementLine::query()->create([
            'company_id' => $requirement->company_id,
            'recruitment_requirement_id' => $requirement->id,
            'position_id' => $position->id,
            'required_headcount' => $headcount,
            'salary_min' => 1000,
            'salary_max' => 2000,
            'salary_currency_code' => 'HRA',
            'status' => RequirementLineStatus::Open,
        ]);
    };

    return [
        'welder' => $make($test->welder, $welder),
        'electrician' => $make($test->electrician, $electrician),
        'rigger' => $make($test->rigger, $rigger),
    ];
}

/**
 * @param  list<array{id: int, required_headcount: int}>  $lines
 * @return array<string, mixed>
 */
function headcountRevisionPayload(array $lines, ?string $reason = 'Client requested additional manpower.'): array
{
    return [
        'lines' => $lines,
        'reason' => $reason,
    ];
}

beforeEach(function () {
    Mail::fake();
    (new EmailTemplatesSeeder)->run();

    $this->companyA = createHeadcountRevisionTestCompany('Headcount Alpha', 'HRA');
    $this->companyB = createHeadcountRevisionTestCompany('Headcount Beta', 'HRB');

    $this->requester = createHeadcountRevisionTestUser($this->companyA, [
        'recruitment.requirements.view',
        'recruitment.requirements.create',
        'recruitment.requirements.update',
        'recruitment.requirements.submit',
    ], [
        'email' => 'headcount-requester@example.com',
        'name' => 'Mohammed',
    ]);

    $this->recruiter = createHeadcountRevisionTestUser($this->companyA, [
        'recruitment.requirements.view',
        'recruitment.requirements.approve',
        'recruitment.requirements.request_headcount_revision',
    ], [
        'email' => 'headcount-recruiter@example.com',
        'name' => 'Ahmed',
    ]);

    $this->otherRecruiter = createHeadcountRevisionTestUser($this->companyA, [
        'recruitment.requirements.view',
        'recruitment.requirements.approve',
        'recruitment.requirements.request_headcount_revision',
    ], [
        'email' => 'headcount-other-recruiter@example.com',
        'name' => 'Other Recruiter',
    ]);

    $this->outsider = createHeadcountRevisionTestUser($this->companyA, [
        'recruitment.requirements.view',
        'recruitment.requirements.update',
    ], [
        'email' => 'headcount-outsider@example.com',
        'name' => 'Outsider',
    ]);

    $this->companyBUser = createHeadcountRevisionTestUser($this->companyB, [
        'recruitment.requirements.view',
        'recruitment.requirements.update',
        'recruitment.requirements.request_headcount_revision',
        'recruitment.requirements.approve',
    ], [
        'email' => 'headcount-foreign@example.com',
        'name' => 'Foreign User',
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($this->companyA->id);

    $this->client = Client::query()->create([
        'name' => 'Headcount Client',
        'is_active' => true,
    ]);

    $this->project = Project::query()->create([
        'title' => 'Headcount Project',
        'is_active' => true,
    ]);
    $this->project->clients()->sync([$this->client->id]);

    $this->welder = Position::query()->create([
        'company_id' => $this->companyA->id,
        'title' => 'Welder',
        'status' => 'active',
    ]);
    $this->electrician = Position::query()->create([
        'company_id' => $this->companyA->id,
        'title' => 'Electrician',
        'status' => 'active',
    ]);
    $this->rigger = Position::query()->create([
        'company_id' => $this->companyA->id,
        'title' => 'Rigger',
        'status' => 'active',
    ]);
});

test('draft and returned requesters still edit official headcount directly', function () {
    foreach ([RequirementStatus::Draft, RequirementStatus::Returned] as $status) {
        $req = createHeadcountRevisionRequirement($this, [
            'requirement_number' => 'REQ-'.$status->value,
            'status' => $status,
            'assigned_to' => $this->recruiter->id,
        ]);
        $lines = createHeadcountRevisionLines($this, $req);

        $this->actingAs($this->requester)
            ->withSession(['current_company_id' => $this->companyA->id])
            ->post("/organization/recruitment/requirements/{$req->id}/change-headcount", headcountRevisionPayload([
                ['id' => $lines['welder']->id, 'required_headcount' => 8],
                ['id' => $lines['electrician']->id, 'required_headcount' => 3],
            ], 'Preparation update'))
            ->assertRedirect();

        expect($lines['welder']->fresh()->required_headcount)->toBe(8)
            ->and($lines['electrician']->fresh()->required_headcount)->toBe(3)
            ->and(RecruitmentRequirementHeadcountRevision::query()->where('recruitment_requirement_id', $req->id)->exists())->toBeFalse();
    }
});

test('draft submission validation still requires an assigned recruiter and does not create a headcount revision', function () {
    $incomplete = createHeadcountRevisionRequirement($this, [
        'requirement_number' => 'REQ-DRAFT-1',
        'status' => RequirementStatus::Draft,
        'assigned_to' => null,
    ]);
    createHeadcountRevisionLines($this, $incomplete);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$incomplete->id}/submit")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['assigned_to']);

    $ready = createHeadcountRevisionRequirement($this, [
        'requirement_number' => 'REQ-DRAFT-2',
        'status' => RequirementStatus::Draft,
    ]);
    createHeadcountRevisionLines($this, $ready);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$ready->id}/submit")
        ->assertRedirect();

    expect($ready->fresh()->status)->toBe(RequirementStatus::PendingApproval)
        ->and(RecruitmentRequirementHeadcountRevision::query()->where('recruitment_requirement_id', $ready->id)->exists())->toBeFalse();
});

test('assigned recruiter can request a headcount revision on open and on hold requirements', function (RequirementStatus $status) {
    $req = createHeadcountRevisionRequirement($this, [
        'requirement_number' => 'REQ-'.$status->value,
        'status' => $status,
    ]);
    $lines = createHeadcountRevisionLines($this, $req);

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/change-headcount", headcountRevisionPayload([
            ['id' => $lines['welder']->id, 'required_headcount' => 8],
            ['id' => $lines['electrician']->id, 'required_headcount' => 3],
            ['id' => $lines['rigger']->id, 'required_headcount' => 4],
        ], 'Client requested three additional welders.'))
        ->assertRedirect()
        ->assertSessionHas('success', 'Headcount revision submitted for approval.');

    expect($lines['welder']->fresh()->required_headcount)->toBe(5)
        ->and($lines['electrician']->fresh()->required_headcount)->toBe(3)
        ->and($lines['rigger']->fresh()->required_headcount)->toBe(4);

    $revision = RecruitmentRequirementHeadcountRevision::query()
        ->where('recruitment_requirement_id', $req->id)
        ->with('lines')
        ->first();

    expect($revision)->not->toBeNull()
        ->and($revision->status)->toBe(RequirementHeadcountRevisionStatus::Pending)
        ->and($revision->initiator)->toBe(RequirementHeadcountRevisionInitiator::Recruiter)
        ->and($revision->requested_by)->toBe($this->recruiter->id)
        ->and($revision->company_id)->toBe($this->companyA->id)
        ->and($revision->reason)->toBe('Client requested three additional welders.')
        ->and($revision->lines)->toHaveCount(1)
        ->and($revision->lines->first()->old_headcount)->toBe(5)
        ->and($revision->lines->first()->requested_headcount)->toBe(8)
        ->and($revision->lines->first()->position_title)->toBe('Welder');

    Mail::assertSent(RequirementHeadcountRevisionMail::class, function (RequirementHeadcountRevisionMail $mail) {
        return $mail->hasTo('headcount-requester@example.com')
            && $mail->heading === 'Headcount revision requires your approval'
            && collect($mail->details)->contains(fn (array $detail): bool => $detail['label'] === 'Reason');
    });
})->with([
    RequirementStatus::Open,
    RequirementStatus::OnHold,
]);

test('recruiter headcount revision requires a reason and blocks a second pending revision', function () {
    $req = createHeadcountRevisionRequirement($this);
    $lines = createHeadcountRevisionLines($this, $req);
    $payload = headcountRevisionPayload([
        ['id' => $lines['welder']->id, 'required_headcount' => 8],
    ], '');

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/change-headcount", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reason']);

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/change-headcount", headcountRevisionPayload([
            ['id' => $lines['welder']->id, 'required_headcount' => 8],
        ]))
        ->assertRedirect();

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/change-headcount", headcountRevisionPayload([
            ['id' => $lines['electrician']->id, 'required_headcount' => 6],
        ], 'Another change'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);

    expect(RecruitmentRequirementHeadcountRevision::query()->where('recruitment_requirement_id', $req->id)->count())->toBe(1)
        ->and($lines['electrician']->fresh()->required_headcount)->toBe(3);
});

test('a recruiter who is not assigned cannot request a headcount revision', function () {
    $req = createHeadcountRevisionRequirement($this);
    $lines = createHeadcountRevisionLines($this, $req);

    $this->actingAs($this->otherRecruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/change-headcount", headcountRevisionPayload([
            ['id' => $lines['welder']->id, 'required_headcount' => 8],
        ]))
        ->assertForbidden();

    expect(RecruitmentRequirementHeadcountRevision::query()->count())->toBe(0);
});

test('requester can propose a headcount revision with an optional empty note', function () {
    $req = createHeadcountRevisionRequirement($this);
    $lines = createHeadcountRevisionLines($this, $req);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/change-headcount", headcountRevisionPayload([
            ['id' => $lines['welder']->id, 'required_headcount' => 8],
            ['id' => $lines['electrician']->id, 'required_headcount' => 3],
        ], ''))
        ->assertRedirect();

    expect($lines['welder']->fresh()->required_headcount)->toBe(5);

    $revision = RecruitmentRequirementHeadcountRevision::query()->with('lines')->first();

    expect($revision->initiator)->toBe(RequirementHeadcountRevisionInitiator::Requester)
        ->and($revision->reason)->toBeNull()
        ->and($revision->lines)->toHaveCount(1)
        ->and($revision->status)->toBe(RequirementHeadcountRevisionStatus::Pending);

    Mail::assertSent(RequirementHeadcountRevisionMail::class, function (RequirementHeadcountRevisionMail $mail) {
        return $mail->hasTo('headcount-recruiter@example.com')
            && $mail->heading === 'Headcount revision requires your approval';
    });
});

test('requester approves a recruiter revision atomically and notifies the initiator', function () {
    $req = createHeadcountRevisionRequirement($this);
    $lines = createHeadcountRevisionLines($this, $req);

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/change-headcount", headcountRevisionPayload([
            ['id' => $lines['welder']->id, 'required_headcount' => 8],
            ['id' => $lines['electrician']->id, 'required_headcount' => 4],
            ['id' => $lines['rigger']->id, 'required_headcount' => 4],
        ]))
        ->assertRedirect();

    $revision = RecruitmentRequirementHeadcountRevision::query()->firstOrFail();

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/headcount-revisions/{$revision->id}/approve")
        ->assertForbidden();

    $this->actingAs($this->outsider)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/headcount-revisions/{$revision->id}/approve")
        ->assertForbidden();

    Mail::fake();

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/headcount-revisions/{$revision->id}/approve")
        ->assertRedirect();

    expect($lines['welder']->fresh()->required_headcount)->toBe(8)
        ->and($lines['electrician']->fresh()->required_headcount)->toBe(4)
        ->and($lines['rigger']->fresh()->required_headcount)->toBe(4)
        ->and($revision->fresh()->status)->toBe(RequirementHeadcountRevisionStatus::Approved)
        ->and($revision->fresh()->decided_by)->toBe($this->requester->id)
        ->and($revision->fresh()->decided_at)->not->toBeNull();

    expect(Activity::query()->where('description', 'Headcount revision approved.')->exists())->toBeTrue();

    Mail::assertSent(RequirementHeadcountRevisionMail::class, function (RequirementHeadcountRevisionMail $mail) {
        return $mail->hasTo('headcount-recruiter@example.com')
            && $mail->heading === 'Headcount revision approved';
    });

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/headcount-revisions/{$revision->id}/approve")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

test('assigned recruiter approves a requester revision', function () {
    $req = createHeadcountRevisionRequirement($this);
    $lines = createHeadcountRevisionLines($this, $req);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/change-headcount", headcountRevisionPayload([
            ['id' => $lines['welder']->id, 'required_headcount' => 8],
        ], 'Additional manpower required.'))
        ->assertRedirect();

    $revision = RecruitmentRequirementHeadcountRevision::query()->firstOrFail();

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/headcount-revisions/{$revision->id}/approve")
        ->assertForbidden();

    Mail::fake();

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/headcount-revisions/{$revision->id}/approve")
        ->assertRedirect();

    expect($lines['welder']->fresh()->required_headcount)->toBe(8)
        ->and($revision->fresh()->status)->toBe(RequirementHeadcountRevisionStatus::Approved)
        ->and($revision->fresh()->decided_by)->toBe($this->recruiter->id);

    Mail::assertSent(RequirementHeadcountRevisionMail::class, function (RequirementHeadcountRevisionMail $mail) {
        return $mail->hasTo('headcount-requester@example.com')
            && $mail->heading === 'Headcount revision approved';
    });
});

test('reviewer rejection keeps official headcount and blocks later approval', function () {
    $req = createHeadcountRevisionRequirement($this);
    $lines = createHeadcountRevisionLines($this, $req);

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/change-headcount", headcountRevisionPayload([
            ['id' => $lines['welder']->id, 'required_headcount' => 8],
        ]))
        ->assertRedirect();

    $revision = RecruitmentRequirementHeadcountRevision::query()->firstOrFail();
    Mail::fake();

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/headcount-revisions/{$revision->id}/reject", [
            'decision_note' => 'Not required.',
        ])
        ->assertRedirect();

    $fresh = $revision->fresh();
    expect($lines['welder']->fresh()->required_headcount)->toBe(5)
        ->and($fresh->status)->toBe(RequirementHeadcountRevisionStatus::Rejected)
        ->and($fresh->decided_by)->toBe($this->requester->id)
        ->and($fresh->decision_note)->toBe('Not required.')
        ->and(RecruitmentRequirementHeadcountRevision::query()->whereKey($revision->id)->exists())->toBeTrue();

    Mail::assertSent(RequirementHeadcountRevisionMail::class, function (RequirementHeadcountRevisionMail $mail) {
        return $mail->hasTo('headcount-recruiter@example.com')
            && $mail->heading === 'Headcount revision rejected';
    });

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/headcount-revisions/{$revision->id}/approve")
        ->assertUnprocessable();

    expect($lines['welder']->fresh()->required_headcount)->toBe(5);
});

test('unchanged negative and unsafe status proposals are rejected', function () {
    $req = createHeadcountRevisionRequirement($this);
    $lines = createHeadcountRevisionLines($this, $req);

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/change-headcount", headcountRevisionPayload([
            ['id' => $lines['welder']->id, 'required_headcount' => 5],
            ['id' => $lines['electrician']->id, 'required_headcount' => 3],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['lines']);

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/change-headcount", headcountRevisionPayload([
            ['id' => $lines['welder']->id, 'required_headcount' => 0],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['lines.0.required_headcount']);

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/change-headcount", headcountRevisionPayload([
            ['id' => $lines['welder']->id, 'required_headcount' => -2],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['lines.0.required_headcount']);

    foreach ([RequirementStatus::Completed, RequirementStatus::Cancelled, RequirementStatus::PendingApproval] as $status) {
        $blocked = createHeadcountRevisionRequirement($this, [
            'requirement_number' => 'REQ-'.$status->value,
            'status' => $status,
        ]);
        $blockedLines = createHeadcountRevisionLines($this, $blocked);

        $this->actingAs($this->recruiter)
            ->withSession(['current_company_id' => $this->companyA->id])
            ->postJson("/organization/recruitment/requirements/{$blocked->id}/change-headcount", headcountRevisionPayload([
                ['id' => $blockedLines['welder']->id, 'required_headcount' => 8],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    expect(RecruitmentRequirementHeadcountRevision::query()->count())->toBe(0);
});

test('reduction to the existing minimum is allowed because no committed candidate count exists', function () {
    $req = createHeadcountRevisionRequirement($this);
    $lines = createHeadcountRevisionLines($this, $req);

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/change-headcount", headcountRevisionPayload([
            ['id' => $lines['welder']->id, 'required_headcount' => 1],
        ], 'Reduce until candidate commitment is tracked.'))
        ->assertRedirect();

    expect($lines['welder']->fresh()->required_headcount)->toBe(5)
        ->and(RecruitmentRequirementHeadcountRevision::query()->first()->lines()->first()->requested_headcount)->toBe(1);
});

test('a stale headcount revision cannot partially overwrite newer official values', function () {
    $req = createHeadcountRevisionRequirement($this);
    $lines = createHeadcountRevisionLines($this, $req);

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/change-headcount", headcountRevisionPayload([
            ['id' => $lines['welder']->id, 'required_headcount' => 8],
            ['id' => $lines['electrician']->id, 'required_headcount' => 6],
        ]))
        ->assertRedirect();

    $revision = RecruitmentRequirementHeadcountRevision::query()->firstOrFail();
    $lines['electrician']->update(['required_headcount' => 7]);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/headcount-revisions/{$revision->id}/approve")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);

    expect($lines['welder']->fresh()->required_headcount)->toBe(5)
        ->and($lines['electrician']->fresh()->required_headcount)->toBe(7)
        ->and($revision->fresh()->status)->toBe(RequirementHeadcountRevisionStatus::Pending);
});

test('needs my action shows the pending headcount revision to the correct reviewer including on hold', function () {
    $recruiterOpen = createHeadcountRevisionRequirement($this, ['requirement_number' => 'REQ-0101']);
    $recruiterHold = createHeadcountRevisionRequirement($this, [
        'requirement_number' => 'REQ-0102',
        'status' => RequirementStatus::OnHold,
    ]);
    $requesterOpen = createHeadcountRevisionRequirement($this, ['requirement_number' => 'REQ-0103']);
    $linesA = createHeadcountRevisionLines($this, $recruiterOpen);
    $linesB = createHeadcountRevisionLines($this, $recruiterHold);
    $linesC = createHeadcountRevisionLines($this, $requesterOpen);

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$recruiterOpen->id}/change-headcount", headcountRevisionPayload([
            ['id' => $linesA['welder']->id, 'required_headcount' => 8],
        ]))
        ->assertRedirect();

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$recruiterHold->id}/change-headcount", headcountRevisionPayload([
            ['id' => $linesB['welder']->id, 'required_headcount' => 9],
        ]))
        ->assertRedirect();

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$requesterOpen->id}/change-headcount", headcountRevisionPayload([
            ['id' => $linesC['welder']->id, 'required_headcount' => 7],
        ], ''))
        ->assertRedirect();

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get('/organization/recruitment/requirements?needs_action=deadline_extension')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('requirements.data', 2)
            ->where('requirements.data.0.next_action', 'review_headcount_revision')
            ->where('requirements.data.0.can_decide_headcount_revision', true)
        );

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get('/organization/recruitment/requirements?needs_action=headcount_revision')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('requirements.data', 1)
            ->where('requirements.data.0.id', $requesterOpen->id)
            ->where('requirements.data.0.next_action', 'review_headcount_revision')
        );

    $this->actingAs($this->outsider)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get('/organization/recruitment/requirements?needs_action=deadline_extension')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('requirements.data', 0));

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get('/organization/recruitment/requirements?tab=active')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('requirements.data', function ($rows) use ($recruiterHold): bool {
                return collect($rows)->pluck('id')->doesntContain($recruiterHold->id);
            })
        );

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get('/organization/recruitment/requirements?tab=on_hold')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('requirements.data', 1)
            ->where('requirements.data.0.id', $recruiterHold->id)
            ->where('requirements.data.0.status', 'on_hold')
        );

    $pending = RecruitmentRequirementHeadcountRevision::query()
        ->where('recruitment_requirement_id', $recruiterOpen->id)
        ->firstOrFail();
    $pending->update([
        'status' => RequirementHeadcountRevisionStatus::Approved,
        'decided_by' => $this->requester->id,
        'decided_at' => now(),
    ]);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get('/organization/recruitment/requirements?needs_action=deadline_extension')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('requirements.data', 1)
            ->where('requirements.data.0.id', $recruiterHold->id)
        );
});

test('cross company headcount revision creation approval and foreign lines are rejected', function () {
    $req = createHeadcountRevisionRequirement($this);
    $lines = createHeadcountRevisionLines($this, $req);

    $this->actingAs($this->companyBUser)
        ->withSession(['current_company_id' => $this->companyB->id])
        ->post("/organization/recruitment/requirements/{$req->id}/change-headcount", headcountRevisionPayload([
            ['id' => $lines['welder']->id, 'required_headcount' => 8],
        ]))
        ->assertNotFound();

    $foreignRequirement = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyB->id,
        'requirement_number' => 'REQ-B',
        'client_id' => $this->client->id,
        'status' => RequirementStatus::Open,
        'priority' => 'normal',
        'request_received_date' => now(),
        'required_by_date' => now()->addDay(),
        'created_by' => $this->companyBUser->id,
    ]);
    $foreignPosition = Position::query()->create([
        'company_id' => $this->companyB->id,
        'title' => 'Foreign Welder',
        'status' => 'active',
    ]);
    $foreignLine = RecruitmentRequirementLine::query()->create([
        'company_id' => $this->companyB->id,
        'recruitment_requirement_id' => $foreignRequirement->id,
        'position_id' => $foreignPosition->id,
        'required_headcount' => 2,
        'status' => RequirementLineStatus::Open,
    ]);

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/change-headcount", headcountRevisionPayload([
            ['id' => $foreignLine->id, 'required_headcount' => 8],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['lines']);

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/change-headcount", headcountRevisionPayload([
            ['id' => $lines['welder']->id, 'required_headcount' => 8],
        ]))
        ->assertRedirect();

    $revision = RecruitmentRequirementHeadcountRevision::query()->firstOrFail();

    $this->actingAs($this->companyBUser)
        ->withSession(['current_company_id' => $this->companyB->id])
        ->post("/organization/recruitment/requirements/{$req->id}/headcount-revisions/{$revision->id}/approve")
        ->assertNotFound();

    $this->actingAs($this->companyBUser)
        ->withSession(['current_company_id' => $this->companyB->id])
        ->post("/organization/recruitment/requirements/{$req->id}/headcount-revisions/{$revision->id}/reject")
        ->assertNotFound();

    expect($lines['welder']->fresh()->required_headcount)->toBe(5)
        ->and($revision->fresh()->status)->toBe(RequirementHeadcountRevisionStatus::Pending);
});

test('the same person cannot submit a self-approved headcount revision', function () {
    $req = createHeadcountRevisionRequirement($this, [
        'assigned_to' => $this->requester->id,
    ]);
    $lines = createHeadcountRevisionLines($this, $req);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/change-headcount", headcountRevisionPayload([
            ['id' => $lines['welder']->id, 'required_headcount' => 8],
        ], 'Should not self approve'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);

    expect(RecruitmentRequirementHeadcountRevision::query()->count())->toBe(0)
        ->and($lines['welder']->fresh()->required_headcount)->toBe(5);
});

test('approved and rejected headcount history keeps old and proposed values with the correct note label', function () {
    $approvedReq = createHeadcountRevisionRequirement($this, ['requirement_number' => 'REQ-HIST-1']);
    $approvedLines = createHeadcountRevisionLines($this, $approvedReq);

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$approvedReq->id}/change-headcount", headcountRevisionPayload([
            ['id' => $approvedLines['welder']->id, 'required_headcount' => 8],
        ], 'Client requested additional manpower.'))
        ->assertRedirect();

    $approved = RecruitmentRequirementHeadcountRevision::query()->firstOrFail();

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$approvedReq->id}/headcount-revisions/{$approved->id}/approve")
        ->assertRedirect();

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get("/organization/recruitment/requirements/{$approvedReq->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('requirement.headcount_revisions.0.status', 'approved')
            ->where('requirement.headcount_revisions.0.note_label', 'Reason')
            ->where('requirement.headcount_revisions.0.reason', 'Client requested additional manpower.')
            ->where('requirement.headcount_revisions.0.lines.0.position_title', 'Welder')
            ->where('requirement.headcount_revisions.0.lines.0.old_headcount', 5)
            ->where('requirement.headcount_revisions.0.lines.0.requested_headcount', 8)
            ->where('requirement.headcount_revisions.0.decided_by_name', 'Mohammed')
        );

    $rejectedReq = createHeadcountRevisionRequirement($this, ['requirement_number' => 'REQ-HIST-2']);
    $rejectedLines = createHeadcountRevisionLines($this, $rejectedReq);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$rejectedReq->id}/change-headcount", headcountRevisionPayload([
            ['id' => $rejectedLines['welder']->id, 'required_headcount' => 8],
        ], 'Additional manpower required.'))
        ->assertRedirect();

    $rejected = RecruitmentRequirementHeadcountRevision::query()
        ->where('recruitment_requirement_id', $rejectedReq->id)
        ->firstOrFail();

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$rejectedReq->id}/headcount-revisions/{$rejected->id}/reject")
        ->assertRedirect();

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get("/organization/recruitment/requirements/{$rejectedReq->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('requirement.headcount_revisions.0.status', 'rejected')
            ->where('requirement.headcount_revisions.0.note_label', 'Note')
            ->where('requirement.headcount_revisions.0.reason', 'Additional manpower required.')
            ->where('requirement.headcount_revisions.0.lines.0.old_headcount', 5)
            ->where('requirement.headcount_revisions.0.lines.0.requested_headcount', 8)
            ->where('requirement.pending_headcount_revision', null)
        );

    expect($rejectedLines['welder']->fresh()->required_headcount)->toBe(5);
});

test('assigned recruiter with approve can review requester-initiated revisions even without request permission', function () {
    $approveOnlyRecruiter = createHeadcountRevisionTestUser($this->companyA, [
        'recruitment.requirements.view',
        'recruitment.requirements.approve',
    ], [
        'email' => 'approve-only-recruiter@example.com',
        'name' => 'Approve Only Recruiter',
    ]);

    $req = createHeadcountRevisionRequirement($this, [
        'assigned_to' => $approveOnlyRecruiter->id,
    ]);
    $lines = createHeadcountRevisionLines($this, $req);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/change-headcount", headcountRevisionPayload([
            ['id' => $lines['welder']->id, 'required_headcount' => 8],
        ], 'Additional manpower required.'))
        ->assertRedirect();

    $revision = RecruitmentRequirementHeadcountRevision::query()->firstOrFail();

    $this->actingAs($approveOnlyRecruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/headcount-revisions/{$revision->id}/approve")
        ->assertRedirect();

    expect($lines['welder']->fresh()->required_headcount)->toBe(8);
});

test('requester cannot create a headcount revision when assigned recruiter lacks approve permission', function () {
    $requestOnlyRecruiter = createHeadcountRevisionTestUser($this->companyA, [
        'recruitment.requirements.view',
        'recruitment.requirements.request_headcount_revision',
    ], [
        'email' => 'request-only-recruiter@example.com',
        'name' => 'Request Only Recruiter',
    ]);

    $req = createHeadcountRevisionRequirement($this, [
        'assigned_to' => $requestOnlyRecruiter->id,
    ]);
    $lines = createHeadcountRevisionLines($this, $req);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/change-headcount", headcountRevisionPayload([
            ['id' => $lines['welder']->id, 'required_headcount' => 8],
        ], 'Additional manpower required.'))
        ->assertSessionHasErrors([
            'assigned_to' => 'The assigned recruiter does not have permission to approve headcount revisions. Assign an authorized recruiter before submitting this revision.',
        ]);

    expect(RecruitmentRequirementHeadcountRevision::query()->count())->toBe(0)
        ->and(DB::table('recruitment_requirement_headcount_revision_lines')->count())->toBe(0)
        ->and($lines['welder']->fresh()->required_headcount)->toBe(5);

    Mail::assertNothingSent();
});

test('requester can create a headcount revision when assigned recruiter is an eligible approver', function () {
    expect(RecruiterOptionsQuery::isEligibleApprover($this->recruiter->id, $this->companyA->id))->toBeTrue();

    $req = createHeadcountRevisionRequirement($this);
    $lines = createHeadcountRevisionLines($this, $req);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/change-headcount", headcountRevisionPayload([
            ['id' => $lines['welder']->id, 'required_headcount' => 8],
        ]))
        ->assertRedirect();

    expect(RecruitmentRequirementHeadcountRevision::query()->pending()->count())->toBe(1);
});

test('requester cannot create a headcount revision when assigned recruiter is inactive', function () {
    $inactiveRecruiter = createHeadcountRevisionTestUser($this->companyA, [
        'recruitment.requirements.view',
        'recruitment.requirements.approve',
    ], [
        'email' => 'inactive-recruiter@example.com',
        'status' => 'inactive',
    ]);

    expect(RecruiterOptionsQuery::isEligibleApprover($inactiveRecruiter->id, $this->companyA->id))->toBeFalse();

    $req = createHeadcountRevisionRequirement($this, [
        'assigned_to' => $inactiveRecruiter->id,
    ]);
    $lines = createHeadcountRevisionLines($this, $req);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/change-headcount", headcountRevisionPayload([
            ['id' => $lines['welder']->id, 'required_headcount' => 8],
        ]))
        ->assertSessionHasErrors(['assigned_to']);

    expect(RecruitmentRequirementHeadcountRevision::query()->count())->toBe(0)
        ->and(DB::table('recruitment_requirement_headcount_revision_lines')->count())->toBe(0)
        ->and($lines['welder']->fresh()->required_headcount)->toBe(5);

    Mail::assertNothingSent();
});

test('requester cannot create a headcount revision when assigned recruiter is soft-deleted', function () {
    $deletedRecruiter = createHeadcountRevisionTestUser($this->companyA, [
        'recruitment.requirements.view',
        'recruitment.requirements.approve',
    ], [
        'email' => 'deleted-recruiter@example.com',
    ]);

    $deletedRecruiter->delete();

    expect(RecruiterOptionsQuery::isEligibleApprover($deletedRecruiter->id, $this->companyA->id))->toBeFalse();

    $req = createHeadcountRevisionRequirement($this, [
        'assigned_to' => $deletedRecruiter->id,
    ]);
    $lines = createHeadcountRevisionLines($this, $req);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/change-headcount", headcountRevisionPayload([
            ['id' => $lines['welder']->id, 'required_headcount' => 8],
        ]))
        ->assertSessionHasErrors(['assigned_to']);

    expect(RecruitmentRequirementHeadcountRevision::query()->count())->toBe(0)
        ->and($lines['welder']->fresh()->required_headcount)->toBe(5);

    Mail::assertNothingSent();
});

test('requester cannot create a headcount revision when assigned recruiter has neither approve nor request permission', function () {
    $viewOnlyRecruiter = createHeadcountRevisionTestUser($this->companyA, [
        'recruitment.requirements.view',
    ], [
        'email' => 'view-only-recruiter@example.com',
        'name' => 'View Only Recruiter',
    ]);

    $req = createHeadcountRevisionRequirement($this, [
        'assigned_to' => $viewOnlyRecruiter->id,
    ]);
    $lines = createHeadcountRevisionLines($this, $req);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/change-headcount", headcountRevisionPayload([
            ['id' => $lines['welder']->id, 'required_headcount' => 8],
        ]))
        ->assertSessionHasErrors(['assigned_to']);

    expect(RecruitmentRequirementHeadcountRevision::query()->count())->toBe(0)
        ->and($lines['welder']->fresh()->required_headcount)->toBe(5);

    Mail::assertNothingSent();
});

test('requester cannot create a headcount revision without an assigned recruiter', function () {
    $req = createHeadcountRevisionRequirement($this, [
        'assigned_to' => null,
    ]);
    $lines = createHeadcountRevisionLines($this, $req);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/change-headcount", headcountRevisionPayload([
            ['id' => $lines['welder']->id, 'required_headcount' => 8],
        ]))
        ->assertSessionHasErrors([
            'status' => 'An assigned recruiter is required before a headcount revision can be submitted.',
        ]);

    expect(RecruitmentRequirementHeadcountRevision::query()->count())->toBe(0);
});

test('requester show page exposes headcount revision action only when assigned recruiter can approve', function () {
    $requestOnlyRecruiter = createHeadcountRevisionTestUser($this->companyA, [
        'recruitment.requirements.view',
        'recruitment.requirements.request_headcount_revision',
    ], [
        'email' => 'request-only-recruiter-show@example.com',
    ]);

    $blockedReq = createHeadcountRevisionRequirement($this, [
        'assigned_to' => $requestOnlyRecruiter->id,
    ]);
    createHeadcountRevisionLines($this, $blockedReq);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get("/organization/recruitment/requirements/{$blockedReq->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('requirement.can_change_headcount', false)
            ->where('requirement.headcount_revision_mode', null)
        );

    $allowedReq = createHeadcountRevisionRequirement($this, [
        'requirement_number' => 'REQ-0042',
    ]);
    createHeadcountRevisionLines($this, $allowedReq);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get("/organization/recruitment/requirements/{$allowedReq->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('requirement.can_change_headcount', true)
            ->where('requirement.headcount_revision_mode', 'requester')
        );
});

test('draft and returned requesters can still edit headcount directly when assigned recruiter lacks approve permission', function () {
    $requestOnlyRecruiter = createHeadcountRevisionTestUser($this->companyA, [
        'recruitment.requirements.view',
        'recruitment.requirements.request_headcount_revision',
    ]);

    foreach ([RequirementStatus::Draft, RequirementStatus::Returned] as $index => $status) {
        $req = createHeadcountRevisionRequirement($this, [
            'status' => $status,
            'assigned_to' => $requestOnlyRecruiter->id,
            'requirement_number' => 'REQ-DR'.($index + 1),
        ]);
        $lines = createHeadcountRevisionLines($this, $req);

        $this->actingAs($this->requester)
            ->withSession(['current_company_id' => $this->companyA->id])
            ->post("/organization/recruitment/requirements/{$req->id}/change-headcount", headcountRevisionPayload([
                ['id' => $lines['welder']->id, 'required_headcount' => 8],
            ], 'Direct edit while preparing requirement.'))
            ->assertRedirect();

        expect($lines['welder']->fresh()->required_headcount)->toBe(8)
            ->and(RecruitmentRequirementHeadcountRevision::query()->count())->toBe(0);
    }
});

test('assigned recruiter with request permission but without approve cannot review requester-initiated revisions', function () {
    $requestOnlyRecruiter = createHeadcountRevisionTestUser($this->companyA, [
        'recruitment.requirements.view',
        'recruitment.requirements.request_headcount_revision',
    ], [
        'email' => 'request-only-recruiter-review@example.com',
        'name' => 'Request Only Recruiter Review',
    ]);

    $req = createHeadcountRevisionRequirement($this, [
        'assigned_to' => $this->recruiter->id,
    ]);
    $lines = createHeadcountRevisionLines($this, $req);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/change-headcount", headcountRevisionPayload([
            ['id' => $lines['welder']->id, 'required_headcount' => 8],
        ]))
        ->assertRedirect();

    $revision = RecruitmentRequirementHeadcountRevision::query()->firstOrFail();

    $this->actingAs($requestOnlyRecruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/headcount-revisions/{$revision->id}/approve")
        ->assertForbidden();

    $this->actingAs($requestOnlyRecruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/headcount-revisions/{$revision->id}/reject")
        ->assertForbidden();
});

test('reviewer eligibility for requester revisions uses the requirement company permission context', function () {
    $crossCompanyRecruiter = createHeadcountRevisionTestUser($this->companyB, [
        'recruitment.requirements.view',
        'recruitment.requirements.approve',
    ], [
        'email' => 'cross-company-recruiter@example.com',
        'name' => 'Cross Company Recruiter',
    ]);

    DB::table('company_user')->updateOrInsert(
        ['company_id' => $this->companyA->id, 'user_id' => $crossCompanyRecruiter->id],
        ['status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    );

    expect(RecruiterOptionsQuery::isEligibleApprover($crossCompanyRecruiter->id, $this->companyA->id))->toBeFalse();

    $req = createHeadcountRevisionRequirement($this, [
        'assigned_to' => $crossCompanyRecruiter->id,
    ]);
    $lines = createHeadcountRevisionLines($this, $req);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/change-headcount", headcountRevisionPayload([
            ['id' => $lines['welder']->id, 'required_headcount' => 8],
        ]))
        ->assertSessionHasErrors(['assigned_to']);

    expect(RecruitmentRequirementHeadcountRevision::query()->count())->toBe(0);
});

test('assigned recruiter with request permission can still submit their own recruiter-initiated revision', function () {
    $requestOnlyRecruiter = createHeadcountRevisionTestUser($this->companyA, [
        'recruitment.requirements.view',
        'recruitment.requirements.request_headcount_revision',
    ], [
        'email' => 'request-only-recruiter-2@example.com',
        'name' => 'Request Only Recruiter Two',
    ]);

    $req = createHeadcountRevisionRequirement($this, [
        'assigned_to' => $requestOnlyRecruiter->id,
    ]);
    $lines = createHeadcountRevisionLines($this, $req);

    $this->actingAs($requestOnlyRecruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/change-headcount", headcountRevisionPayload([
            ['id' => $lines['welder']->id, 'required_headcount' => 8],
        ], 'Client requested additional welders.'))
        ->assertRedirect();

    expect(RecruitmentRequirementHeadcountRevision::query()->first()?->initiator)
        ->toBe(RequirementHeadcountRevisionInitiator::Recruiter);
});

test('open and on hold requirements cannot be filled while a headcount revision is pending', function (RequirementStatus $status) {
    $closer = createHeadcountRevisionTestUser($this->companyA, [
        'recruitment.requirements.view',
        'recruitment.requirements.close',
    ]);

    $req = createHeadcountRevisionRequirement($this, ['status' => $status]);
    $lines = createHeadcountRevisionLines($this, $req);

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/change-headcount", headcountRevisionPayload([
            ['id' => $lines['welder']->id, 'required_headcount' => 8],
        ]))
        ->assertRedirect();

    $this->actingAs($closer)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/fill")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);

    expect($req->fresh()->status)->toBe($status)
        ->and($lines['welder']->fresh()->required_headcount)->toBe(5)
        ->and(RecruitmentRequirementHeadcountRevision::query()->first()?->status)
        ->toBe(RequirementHeadcountRevisionStatus::Pending);
})->with([
    RequirementStatus::Open,
    RequirementStatus::OnHold,
]);

test('requirement fill succeeds after a pending headcount revision is resolved', function () {
    $closer = createHeadcountRevisionTestUser($this->companyA, [
        'recruitment.requirements.view',
        'recruitment.requirements.close',
    ]);

    $req = createHeadcountRevisionRequirement($this);
    $lines = createHeadcountRevisionLines($this, $req);

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/change-headcount", headcountRevisionPayload([
            ['id' => $lines['welder']->id, 'required_headcount' => 8],
        ]))
        ->assertRedirect();

    $revision = RecruitmentRequirementHeadcountRevision::query()->firstOrFail();

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/headcount-revisions/{$revision->id}/reject")
        ->assertRedirect();

    $this->actingAs($closer)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/fill")
        ->assertRedirect();

    expect($req->fresh()->status)->toBe(RequirementStatus::Completed);
});

test('cancelling a requirement cancels a pending headcount revision and preserves history', function () {
    $canceller = createHeadcountRevisionTestUser($this->companyA, [
        'recruitment.requirements.view',
        'recruitment.requirements.cancel',
    ]);

    $req = createHeadcountRevisionRequirement($this);
    $lines = createHeadcountRevisionLines($this, $req);

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/change-headcount", headcountRevisionPayload([
            ['id' => $lines['welder']->id, 'required_headcount' => 8],
        ], 'Client requested additional manpower.'))
        ->assertRedirect();

    $revision = RecruitmentRequirementHeadcountRevision::query()->firstOrFail();

    $this->actingAs($canceller)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/cancel", [
            'cancellation_reason' => 'Project postponed.',
        ])
        ->assertRedirect();

    $freshRevision = $revision->fresh();
    expect($req->fresh()->status)->toBe(RequirementStatus::Cancelled)
        ->and($lines['welder']->fresh()->required_headcount)->toBe(5)
        ->and($lines['welder']->fresh()->status)->toBe(RequirementLineStatus::Cancelled)
        ->and($freshRevision->status)->toBe(RequirementHeadcountRevisionStatus::Cancelled)
        ->and($freshRevision->decision_note)->toBe('Cancelled because the requirement was cancelled.')
        ->and($freshRevision->decided_by)->toBe($canceller->id);

    expect(Activity::query()->where('description', 'Headcount revision cancelled. Official headcount remains unchanged.')->exists())->toBeTrue();

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get("/organization/recruitment/requirements/{$req->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('requirement.headcount_revisions.0.status', 'cancelled')
            ->where('requirement.headcount_revisions.0.decision_note', 'Cancelled because the requirement was cancelled.')
        );
});

test('cancelled headcount revisions stay terminal after requirement reopen and cannot be reviewed again', function () {
    $canceller = createHeadcountRevisionTestUser($this->companyA, [
        'recruitment.requirements.view',
        'recruitment.requirements.cancel',
    ]);

    $reopenUser = createHeadcountRevisionTestUser($this->companyA, [
        'recruitment.requirements.view',
        'recruitment.requirements.reopen',
        'recruitment.requirements.approve',
        'recruitment.requirements.request_headcount_revision',
    ]);

    $req = createHeadcountRevisionRequirement($this, [
        'assigned_to' => $reopenUser->id,
    ]);
    $lines = createHeadcountRevisionLines($this, $req);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/change-headcount", headcountRevisionPayload([
            ['id' => $lines['welder']->id, 'required_headcount' => 8],
        ], 'Additional manpower required.'))
        ->assertRedirect();

    $revision = RecruitmentRequirementHeadcountRevision::query()->firstOrFail();

    $this->actingAs($canceller)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/cancel", [
            'cancellation_reason' => 'Cancelled for reopen test.',
        ])
        ->assertRedirect();

    $this->actingAs($reopenUser)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/reopen", [
            'reason' => 'Project resumed.',
            'new_required_by_date' => now()->addDays(20)->format('Y-m-d'),
        ])
        ->assertRedirect();

    $this->actingAs($reopenUser)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get('/organization/recruitment/requirements?needs_action=deadline_extension')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('requirements.data', 0));

    $this->actingAs($reopenUser)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->fresh()->id}/headcount-revisions/{$revision->id}/approve")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);

    $this->actingAs($reopenUser)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->fresh()->id}/headcount-revisions/{$revision->id}/reject")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->fresh()->id}/change-headcount", headcountRevisionPayload([
            ['id' => $lines['welder']->id, 'required_headcount' => 9],
        ], 'New staffing request after reopen.'))
        ->assertRedirect();

    expect(RecruitmentRequirementHeadcountRevision::query()->where('status', RequirementHeadcountRevisionStatus::Pending)->count())->toBe(1)
        ->and($revision->fresh()->status)->toBe(RequirementHeadcountRevisionStatus::Cancelled);
});

test('pending headcount revisions remain valid through hold and resume', function () {
    $req = createHeadcountRevisionRequirement($this);
    $lines = createHeadcountRevisionLines($this, $req);

    $this->actingAs($this->recruiter)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/change-headcount", headcountRevisionPayload([
            ['id' => $lines['welder']->id, 'required_headcount' => 8],
        ]))
        ->assertRedirect();

    $revision = RecruitmentRequirementHeadcountRevision::query()->firstOrFail();

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/hold")
        ->assertRedirect();

    expect($req->fresh()->status)->toBe(RequirementStatus::OnHold)
        ->and($revision->fresh()->status)->toBe(RequirementHeadcountRevisionStatus::Pending);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/resume")
        ->assertRedirect();

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/headcount-revisions/{$revision->id}/approve")
        ->assertRedirect();

    expect($lines['welder']->fresh()->required_headcount)->toBe(8);
});
