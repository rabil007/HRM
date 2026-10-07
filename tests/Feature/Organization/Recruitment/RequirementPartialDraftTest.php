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
use App\Support\Recruitment\RequirementPresenter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function createPartialDraftCompany(string $name, string $code): Company
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
function createPartialDraftUser(Company $company, array $permissions = [], array $attributes = []): User
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

beforeEach(function () {
    $this->companyA = createPartialDraftCompany('Partial Draft Alpha', 'PDA');
    $this->companyB = createPartialDraftCompany('Partial Draft Beta', 'PDB');

    $perms = [
        'recruitment.requirements.view',
        'recruitment.requirements.create',
        'recruitment.requirements.update',
        'recruitment.requirements.submit',
        'recruitment.requirements.approve',
    ];

    $this->requester = createPartialDraftUser($this->companyA, $perms, [
        'email' => 'partial.requester@example.com',
        'name' => 'Partial Requester',
    ]);

    $this->recruiter = createPartialDraftUser($this->companyA, $perms, [
        'email' => 'partial.recruiter@example.com',
        'name' => 'Partial Recruiter',
    ]);

    $this->client = Client::query()->create([
        'name' => 'Partial Draft Client',
        'is_active' => true,
    ]);

    $this->project = Project::query()->create([
        'title' => 'Partial Draft Project',
        'is_active' => true,
    ]);
    $this->project->clients()->sync([$this->client->id]);

    $this->position = Position::query()->create([
        'company_id' => $this->companyA->id,
        'title' => 'Partial Deck Crew',
        'status' => 'active',
    ]);
});

test('migration allows nullable draft client and date columns', function () {
    expect(Schema::hasColumn('recruitment_requirements', 'client_id'))->toBeTrue();

    $columns = collect(Schema::getColumns('recruitment_requirements'))
        ->keyBy('name');

    expect($columns['client_id']['nullable'])->toBeTrue()
        ->and($columns['request_received_date']['nullable'])->toBeTrue()
        ->and($columns['required_by_date']['nullable'])->toBeTrue();
});

test('partial draft with only notes can be saved', function () {
    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson('/organization/recruitment/requirements', [
            'priority' => 'normal',
            'notes' => 'Client called asking for urgent deck crew.',
            'submit_for_approval' => false,
            'positions' => [],
        ])
        ->assertRedirect();

    $req = RecruitmentRequirement::query()
        ->where('company_id', $this->companyA->id)
        ->latest('id')
        ->first();

    expect($req)->not->toBeNull()
        ->and($req->status)->toBe(RequirementStatus::Draft)
        ->and($req->client_id)->toBeNull()
        ->and($req->request_received_date)->toBeNull()
        ->and($req->required_by_date)->toBeNull()
        ->and($req->assigned_to)->toBeNull()
        ->and($req->notes)->toBe('Client called asking for urgent deck crew.')
        ->and($req->lines()->count())->toBe(0);
});

test('draft can save without client request dates recruiter positions or salary', function () {
    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson('/organization/recruitment/requirements', [
            'priority' => 'urgent',
            'location' => 'Offshore',
            'submit_for_approval' => false,
        ])
        ->assertRedirect();

    $req = RecruitmentRequirement::query()
        ->where('company_id', $this->companyA->id)
        ->latest('id')
        ->firstOrFail();

    expect($req->client_id)->toBeNull()
        ->and($req->request_received_date)->toBeNull()
        ->and($req->required_by_date)->toBeNull()
        ->and($req->assigned_to)->toBeNull()
        ->and($req->lines()->count())->toBe(0);
});

test('provided invalid client id still fails on draft save', function () {
    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson('/organization/recruitment/requirements', [
            'client_id' => 999999,
            'priority' => 'normal',
            'submit_for_approval' => false,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['client_id']);
});

test('provided invalid dates still fail on draft save', function () {
    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson('/organization/recruitment/requirements', [
            'priority' => 'normal',
            'request_received_date' => 'not-a-date',
            'required_by_date' => 'also-bad',
            'submit_for_approval' => false,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['request_received_date', 'required_by_date']);
});

test('self-assigned recruiter still fails on draft save', function () {
    $before = RecruitmentRequirement::query()->where('company_id', $this->companyA->id)->count();

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson('/organization/recruitment/requirements', [
            'priority' => 'normal',
            'assigned_to' => $this->requester->id,
            'notes' => 'Should not save',
            'submit_for_approval' => false,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['assigned_to']);

    expect(
        RecruitmentRequirement::query()->where('company_id', $this->companyA->id)->count()
    )->toBe($before);
});

test('create and submit still requires full readiness', function () {
    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson('/organization/recruitment/requirements', [
            'priority' => 'normal',
            'notes' => 'Incomplete submit attempt',
            'submit_for_approval' => true,
            'positions' => [],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['client_id', 'request_received_date', 'required_by_date', 'positions']);
});

test('save and submit remains atomic when readiness fails', function () {
    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post('/organization/recruitment/requirements', [
            'priority' => 'normal',
            'notes' => 'Original notes',
            'submit_for_approval' => false,
        ])
        ->assertRedirect();

    $req = RecruitmentRequirement::query()
        ->where('company_id', $this->companyA->id)
        ->latest('id')
        ->firstOrFail();

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->putJson("/organization/recruitment/requirements/{$req->id}", [
            'priority' => 'urgent',
            'notes' => 'Should roll back',
            'submit_for_approval' => true,
            'positions' => [],
        ])
        ->assertStatus(422);

    $fresh = $req->fresh();

    expect($fresh->status)->toBe(RequirementStatus::Draft)
        ->and($fresh->priority->value)->toBe('normal')
        ->and($fresh->notes)->toBe('Original notes')
        ->and($fresh->submitted_at)->toBeNull();
});

test('save and submit succeeds after completing a partial draft', function () {
    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post('/organization/recruitment/requirements', [
            'priority' => 'normal',
            'notes' => 'Started as notes only',
            'submit_for_approval' => false,
        ])
        ->assertRedirect();

    $req = RecruitmentRequirement::query()
        ->where('company_id', $this->companyA->id)
        ->latest('id')
        ->firstOrFail();

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->put("/organization/recruitment/requirements/{$req->id}", [
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'request_received_date' => now()->subDay()->format('Y-m-d'),
            'required_by_date' => now()->addDays(7)->format('Y-m-d'),
            'priority' => 'normal',
            'assigned_to' => $this->recruiter->id,
            'notes' => 'Ready now',
            'submit_for_approval' => true,
            'positions' => [
                [
                    'position_id' => $this->position->id,
                    'required_headcount' => 2,
                    'salary_min' => 4000,
                    'salary_max' => 7000,
                ],
            ],
        ])
        ->assertRedirect();

    $fresh = $req->fresh(['lines']);

    expect($fresh->status)->toBe(RequirementStatus::PendingApproval)
        ->and($fresh->client_id)->toBe($this->client->id)
        ->and($fresh->assigned_to)->toBe($this->recruiter->id)
        ->and($fresh->lines)->toHaveCount(1)
        ->and((float) $fresh->lines->first()->salary_min)->toBe(4000.0);
});

test('partial draft renders index and detail safely without converting null ids to zero', function () {
    $req = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-'.now()->year.'-900001',
        'client_id' => null,
        'project_id' => null,
        'request_received_date' => null,
        'required_by_date' => null,
        'priority' => 'normal',
        'status' => RequirementStatus::Draft,
        'assigned_to' => null,
        'notes' => 'Null-safe draft',
        'created_by' => $this->requester->id,
        'updated_by' => $this->requester->id,
    ]);

    $payload = RequirementPresenter::toIndexRow(
        $req->fresh(['client', 'project', 'assignedRecruiter', 'lines.position', 'repeatedFrom']),
        user: $this->requester,
    );

    expect($payload['client_id'])->toBeNull()
        ->and($payload['client_name'])->toBe('—')
        ->and($payload['required_by_date'])->toBeNull()
        ->and($payload['required_by_date_formatted'])->toBe('—')
        ->and($payload['request_received_date'])->toBeNull()
        ->and($payload['assigned_to'])->toBeNull();

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get('/organization/recruitment/requirements')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/recruitment/requirements/index')
            ->has('requirements.data')
        );

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get("/organization/recruitment/requirements/{$req->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/recruitment/requirements/show')
            ->where('requirement.client_id', null)
            ->where('requirement.client_name', '—')
            ->where('requirement.required_by_date', null)
        );
});

test('tenant isolation remains enforced for partial drafts', function () {
    $foreign = createPartialDraftUser($this->companyB, [
        'recruitment.requirements.view',
        'recruitment.requirements.create',
        'recruitment.requirements.update',
    ]);

    $req = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-'.now()->year.'-900002',
        'client_id' => null,
        'priority' => 'normal',
        'status' => RequirementStatus::Draft,
        'notes' => 'Company A draft',
        'created_by' => $this->requester->id,
        'updated_by' => $this->requester->id,
    ]);

    $this->actingAs($foreign)
        ->withSession(['current_company_id' => $this->companyB->id])
        ->get("/organization/recruitment/requirements/{$req->id}")
        ->assertNotFound();

    $this->actingAs($foreign)
        ->withSession(['current_company_id' => $this->companyB->id])
        ->putJson("/organization/recruitment/requirements/{$req->id}", [
            'priority' => 'urgent',
            'notes' => 'Hijack attempt',
            'submit_for_approval' => false,
        ])
        ->assertForbidden();
});

test('invalid position id still fails when provided on draft', function () {
    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson('/organization/recruitment/requirements', [
            'priority' => 'normal',
            'submit_for_approval' => false,
            'positions' => [
                ['position_id' => 999999, 'required_headcount' => 1],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['positions.0.position_id']);
});

test('draft update can remain incomplete without triggering submission readiness', function () {
    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post('/organization/recruitment/requirements', [
            'priority' => 'normal',
            'notes' => 'Start',
            'submit_for_approval' => false,
        ])
        ->assertRedirect();

    $req = RecruitmentRequirement::query()
        ->where('company_id', $this->companyA->id)
        ->latest('id')
        ->firstOrFail();

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->put("/organization/recruitment/requirements/{$req->id}", [
            'priority' => 'urgent',
            'notes' => 'Still incomplete',
            'location' => 'Yard',
            'submit_for_approval' => false,
            'positions' => [],
        ])
        ->assertRedirect();

    $fresh = $req->fresh();

    expect($fresh->status)->toBe(RequirementStatus::Draft)
        ->and($fresh->priority->value)->toBe('urgent')
        ->and($fresh->notes)->toBe('Still incomplete')
        ->and($fresh->location)->toBe('Yard')
        ->and($fresh->client_id)->toBeNull()
        ->and(RecruitmentRequirementLine::query()->where('recruitment_requirement_id', $fresh->id)->count())->toBe(0);
});

test('draft with no lines can add a position line on update', function () {
    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post('/organization/recruitment/requirements', [
            'priority' => 'normal',
            'notes' => 'Need crew later',
            'submit_for_approval' => false,
        ])
        ->assertRedirect();

    $req = RecruitmentRequirement::query()
        ->where('company_id', $this->companyA->id)
        ->latest('id')
        ->firstOrFail();

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->put("/organization/recruitment/requirements/{$req->id}", [
            'priority' => 'normal',
            'notes' => 'Need crew later',
            'submit_for_approval' => false,
            'positions' => [
                [
                    'position_id' => $this->position->id,
                    'required_headcount' => 2,
                    'salary_min' => 4000,
                    'salary_max' => 6000,
                ],
            ],
        ])
        ->assertRedirect();

    $lines = $req->fresh()->lines;

    expect($lines)->toHaveCount(1)
        ->and((int) $lines->first()->position_id)->toBe($this->position->id)
        ->and((int) $lines->first()->required_headcount)->toBe(2);
});

test('draft can edit headcount and remove a position line', function () {
    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post('/organization/recruitment/requirements', [
            'priority' => 'normal',
            'submit_for_approval' => false,
            'positions' => [
                ['position_id' => $this->position->id, 'required_headcount' => 1],
            ],
        ])
        ->assertRedirect();

    $req = RecruitmentRequirement::query()
        ->where('company_id', $this->companyA->id)
        ->latest('id')
        ->firstOrFail();

    $line = $req->lines()->firstOrFail();

    $secondPosition = Position::query()->create([
        'company_id' => $this->companyA->id,
        'title' => 'Second Deck Role',
        'status' => 'active',
    ]);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->put("/organization/recruitment/requirements/{$req->id}", [
            'priority' => 'normal',
            'submit_for_approval' => false,
            'positions' => [
                [
                    'id' => $line->id,
                    'position_id' => $this->position->id,
                    'required_headcount' => 4,
                ],
                [
                    'position_id' => $secondPosition->id,
                    'required_headcount' => 1,
                ],
            ],
        ])
        ->assertRedirect();

    expect($req->fresh()->lines)->toHaveCount(2);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->put("/organization/recruitment/requirements/{$req->id}", [
            'priority' => 'normal',
            'submit_for_approval' => false,
            'positions' => [
                [
                    'id' => $line->id,
                    'position_id' => $this->position->id,
                    'required_headcount' => 4,
                ],
            ],
        ])
        ->assertRedirect();

    expect($req->fresh()->lines)->toHaveCount(1)
        ->and((int) $req->fresh()->lines->first()->required_headcount)->toBe(4);
});

test('draft can remove the final position line and still save', function () {
    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post('/organization/recruitment/requirements', [
            'priority' => 'normal',
            'submit_for_approval' => false,
            'positions' => [
                ['position_id' => $this->position->id, 'required_headcount' => 1],
            ],
        ])
        ->assertRedirect();

    $req = RecruitmentRequirement::query()
        ->where('company_id', $this->companyA->id)
        ->latest('id')
        ->firstOrFail();

    expect($req->lines)->toHaveCount(1);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->put("/organization/recruitment/requirements/{$req->id}", [
            'priority' => 'normal',
            'submit_for_approval' => false,
            'positions' => [],
        ])
        ->assertRedirect();

    expect($req->fresh()->lines)->toHaveCount(0)
        ->and($req->fresh()->status)->toBe(RequirementStatus::Draft);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/submit")
        ->assertSessionHasErrors(['positions']);
});

test('requirement index readiness query count stays bounded for multiple draft and returned rows', function () {
    $nextNumber = 91000;
    $createRows = function (int $count) use (&$nextNumber): void {
        for ($i = 0; $i < $count; $i++) {
            $req = RecruitmentRequirement::query()->create([
                'company_id' => $this->companyA->id,
                'requirement_number' => 'REQ-'.now()->year.'-'.$nextNumber++,
                'client_id' => $this->client->id,
                'project_id' => $this->project->id,
                'request_received_date' => now()->subDay(),
                'required_by_date' => now()->addDays(7),
                'priority' => 'normal',
                'status' => $i % 2 === 0 ? RequirementStatus::Draft : RequirementStatus::Returned,
                'assigned_to' => $this->recruiter->id,
                'created_by' => $this->requester->id,
                'updated_by' => $this->requester->id,
            ]);

            RecruitmentRequirementLine::query()->create([
                'company_id' => $this->companyA->id,
                'recruitment_requirement_id' => $req->id,
                'position_id' => $this->position->id,
                'required_headcount' => 1,
                'salary_min' => 4000,
                'salary_max' => 6000,
                'salary_currency_code' => 'AED',
                'status' => RequirementLineStatus::Open,
            ]);
        }
    };

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id]);

    $createRows(4);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get('/organization/recruitment/requirements')->assertOk();
    $queryCountFour = count(DB::getQueryLog());

    $createRows(4);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get('/organization/recruitment/requirements')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/recruitment/requirements/index')
            ->has('requirements.data', 8)
            ->where('requirements.data.0.submission_readiness.ready', true)
        );
    $queryCountEight = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queryCountEight)->toBeLessThanOrEqual($queryCountFour + 4)
        ->and($queryCountEight)->toBeLessThanOrEqual(55);
});

test('draft provided headcount zero fails validation', function () {
    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson('/organization/recruitment/requirements', [
            'priority' => 'normal',
            'submit_for_approval' => false,
            'positions' => [
                ['position_id' => $this->position->id, 'required_headcount' => 0],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['positions.0.required_headcount']);
});

test('submit fails when client becomes inactive after draft save', function () {
    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post('/organization/recruitment/requirements', [
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'request_received_date' => now()->subDay()->format('Y-m-d'),
            'required_by_date' => now()->addDays(7)->format('Y-m-d'),
            'priority' => 'normal',
            'assigned_to' => $this->recruiter->id,
            'submit_for_approval' => false,
            'positions' => [
                [
                    'position_id' => $this->position->id,
                    'required_headcount' => 1,
                    'salary_min' => 4000,
                    'salary_max' => 6000,
                ],
            ],
        ])
        ->assertRedirect();

    $req = RecruitmentRequirement::query()
        ->where('company_id', $this->companyA->id)
        ->latest('id')
        ->firstOrFail();

    $this->client->update(['is_active' => false]);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/submit")
        ->assertSessionHasErrors(['client_id']);
});

test('submit fails when position belongs to another company', function () {
    $foreignPosition = Position::query()->create([
        'company_id' => $this->companyB->id,
        'title' => 'Foreign Position',
        'status' => 'active',
    ]);

    $req = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-'.now()->year.'-900003',
        'client_id' => $this->client->id,
        'project_id' => $this->project->id,
        'request_received_date' => now()->subDay(),
        'required_by_date' => now()->addDays(7),
        'priority' => 'normal',
        'status' => RequirementStatus::Draft,
        'assigned_to' => $this->recruiter->id,
        'created_by' => $this->requester->id,
        'updated_by' => $this->requester->id,
    ]);

    RecruitmentRequirementLine::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $req->id,
        'position_id' => $foreignPosition->id,
        'required_headcount' => 1,
        'salary_min' => 4000,
        'salary_max' => 6000,
        'salary_currency_code' => 'AED',
        'status' => RequirementLineStatus::Open,
    ]);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/submit")
        ->assertSessionHasErrors(['positions.0.position_id']);
});

test('submit fails when project becomes inactive after draft save', function () {
    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post('/organization/recruitment/requirements', [
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'request_received_date' => now()->subDay()->format('Y-m-d'),
            'required_by_date' => now()->addDays(7)->format('Y-m-d'),
            'priority' => 'normal',
            'assigned_to' => $this->recruiter->id,
            'submit_for_approval' => false,
            'positions' => [
                [
                    'position_id' => $this->position->id,
                    'required_headcount' => 1,
                    'salary_min' => 4000,
                    'salary_max' => 6000,
                ],
            ],
        ])
        ->assertRedirect();

    $req = RecruitmentRequirement::query()
        ->where('company_id', $this->companyA->id)
        ->latest('id')
        ->firstOrFail();

    $this->project->update(['is_active' => false]);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/submit")
        ->assertSessionHasErrors(['project_id']);

    expect($req->fresh()->status)->toBe(RequirementStatus::Draft)
        ->and($req->fresh()->submitted_at)->toBeNull();
});

test('submit fails when project is soft-deleted after draft save', function () {
    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post('/organization/recruitment/requirements', [
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'request_received_date' => now()->subDay()->format('Y-m-d'),
            'required_by_date' => now()->addDays(7)->format('Y-m-d'),
            'priority' => 'normal',
            'assigned_to' => $this->recruiter->id,
            'submit_for_approval' => false,
            'positions' => [
                [
                    'position_id' => $this->position->id,
                    'required_headcount' => 1,
                    'salary_min' => 4000,
                    'salary_max' => 6000,
                ],
            ],
        ])
        ->assertRedirect();

    $req = RecruitmentRequirement::query()
        ->where('company_id', $this->companyA->id)
        ->latest('id')
        ->firstOrFail();

    $this->project->delete();

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/submit")
        ->assertSessionHasErrors(['project_id']);

    expect($req->fresh()->status)->toBe(RequirementStatus::Draft);
});

test('submit fails when project is no longer linked to the selected client', function () {
    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post('/organization/recruitment/requirements', [
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'request_received_date' => now()->subDay()->format('Y-m-d'),
            'required_by_date' => now()->addDays(7)->format('Y-m-d'),
            'priority' => 'normal',
            'assigned_to' => $this->recruiter->id,
            'submit_for_approval' => false,
            'positions' => [
                [
                    'position_id' => $this->position->id,
                    'required_headcount' => 1,
                    'salary_min' => 4000,
                    'salary_max' => 6000,
                ],
            ],
        ])
        ->assertRedirect();

    $req = RecruitmentRequirement::query()
        ->where('company_id', $this->companyA->id)
        ->latest('id')
        ->firstOrFail();

    $this->project->clients()->sync([]);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/submit")
        ->assertSessionHasErrors(['project_id']);

    expect($req->fresh()->status)->toBe(RequirementStatus::Draft);
});

test('submit fails when position becomes inactive after draft save', function () {
    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post('/organization/recruitment/requirements', [
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'request_received_date' => now()->subDay()->format('Y-m-d'),
            'required_by_date' => now()->addDays(7)->format('Y-m-d'),
            'priority' => 'normal',
            'assigned_to' => $this->recruiter->id,
            'submit_for_approval' => false,
            'positions' => [
                [
                    'position_id' => $this->position->id,
                    'required_headcount' => 1,
                    'salary_min' => 4000,
                    'salary_max' => 6000,
                ],
            ],
        ])
        ->assertRedirect();

    $req = RecruitmentRequirement::query()
        ->where('company_id', $this->companyA->id)
        ->latest('id')
        ->firstOrFail();

    $this->position->update(['status' => 'inactive']);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/submit")
        ->assertSessionHasErrors(['positions.0.position_id']);

    expect($req->fresh()->status)->toBe(RequirementStatus::Draft);
});

test('submit fails when position is soft-deleted after draft save', function () {
    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post('/organization/recruitment/requirements', [
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'request_received_date' => now()->subDay()->format('Y-m-d'),
            'required_by_date' => now()->addDays(7)->format('Y-m-d'),
            'priority' => 'normal',
            'assigned_to' => $this->recruiter->id,
            'submit_for_approval' => false,
            'positions' => [
                [
                    'position_id' => $this->position->id,
                    'required_headcount' => 1,
                    'salary_min' => 4000,
                    'salary_max' => 6000,
                ],
            ],
        ])
        ->assertRedirect();

    $req = RecruitmentRequirement::query()
        ->where('company_id', $this->companyA->id)
        ->latest('id')
        ->firstOrFail();

    $this->position->delete();

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/submit")
        ->assertSessionHasErrors(['positions.0.position_id']);

    expect($req->fresh()->status)->toBe(RequirementStatus::Draft);
});

test('submit fails when stored line headcount is zero bypassing form validation', function () {
    $req = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-'.now()->year.'-900010',
        'client_id' => $this->client->id,
        'project_id' => $this->project->id,
        'request_received_date' => now()->subDay(),
        'required_by_date' => now()->addDays(7),
        'priority' => 'normal',
        'status' => RequirementStatus::Draft,
        'assigned_to' => $this->recruiter->id,
        'created_by' => $this->requester->id,
        'updated_by' => $this->requester->id,
    ]);

    RecruitmentRequirementLine::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $req->id,
        'position_id' => $this->position->id,
        'required_headcount' => 0,
        'salary_min' => 4000,
        'salary_max' => 6000,
        'salary_currency_code' => 'AED',
        'status' => RequirementLineStatus::Open,
    ]);

    $this->actingAs($this->requester)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/submit")
        ->assertSessionHasErrors(['positions.0.required_headcount']);

    expect($req->fresh()->status)->toBe(RequirementStatus::Draft);
});
