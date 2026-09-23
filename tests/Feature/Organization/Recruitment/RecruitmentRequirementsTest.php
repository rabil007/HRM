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
use App\Models\RecruitmentRequirementAttachment;
use App\Models\RecruitmentRequirementLine;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function createRecruitmentTestCompany(string $name, string $code): Company
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
        'slug' => strtolower(str_replace(' ', '-', $name)),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
}

function createRecruitmentTestUser(Company $company, array $permissions = []): User
{
    $user = User::factory()->create([
        'company_id' => $company->id,
    ]);

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
    Storage::fake('local');
    $this->companyA = createRecruitmentTestCompany('Alpha Marine', 'ALM');
    $this->companyB = createRecruitmentTestCompany('Beta Logistics', 'BLM');

    $allRecruitmentPermissions = [
        'recruitment.requirements.view',
        'recruitment.requirements.create',
        'recruitment.requirements.update',
        'recruitment.requirements.close',
        'recruitment.requirements.cancel',
        'recruitment.requirements.reopen',
        'recruitment.requirements.attachments.download',
    ];

    $this->adminUserA = createRecruitmentTestUser($this->companyA, $allRecruitmentPermissions);
    $this->adminUserB = createRecruitmentTestUser($this->companyB, $allRecruitmentPermissions);

    // Master data
    $this->client = Client::query()->create([
        'name' => 'Aramco Offshore',
        'is_active' => true,
    ]);

    $this->project = Project::query()->create([
        'client_id' => $this->client->id,
        'title' => 'Safaniya Rig 4',
        'is_active' => true,
    ]);

    $this->positionChiefEng = Position::query()->create([
        'company_id' => $this->companyA->id,
        'title' => 'Chief Engineer',
        'grade' => 'Officer',
        'status' => 'active',
    ]);

    $this->positionCaptain = Position::query()->create([
        'company_id' => $this->companyA->id,
        'title' => 'Master / Captain',
        'grade' => 'Master',
        'status' => 'active',
    ]);
});

test('guests are redirected to login', function () {
    $this->get('/organization/recruitment/requirements')->assertRedirect(route('login'));
});

test('users without recruitment view permission receive 403', function () {
    $userWithoutPerm = createRecruitmentTestUser($this->companyA, []);

    $this->actingAs($userWithoutPerm)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get('/organization/recruitment/requirements')
        ->assertForbidden();
});

test('authorized users can view requirements index with inertia props', function () {
    $this->actingAs($this->adminUserA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get('/organization/recruitment/requirements')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/recruitment/requirements/index')
            ->has('requirements.data')
            ->has('summary')
            ->has('tab_counts')
            ->has('filters')
            ->has('options.clients')
            ->has('options.positions')
            ->has('can')
        );
});

test('can create a multi-line recruitment requirement with automatic sequence generation and attachment', function () {
    $file = UploadedFile::fake()->create('client_specification.pdf', 500, 'application/pdf');

    $payload = [
        'client_id' => $this->client->id,
        'project_id' => $this->project->id,
        'client_reference_number' => 'PO-OFFSHORE-901',
        'location' => 'Safaniya Field',
        'request_received_date' => now()->subDay()->format('Y-m-d'),
        'required_by_date' => now()->addDays(14)->format('Y-m-d'),
        'priority' => 'urgent',
        'notes' => 'Need valid HUET and offshore medicals.',
        'positions' => [
            [
                'position_id' => $this->positionChiefEng->id,
                'required_headcount' => 2,
                'line_notes' => 'DP maintenance certified',
            ],
            [
                'position_id' => $this->positionCaptain->id,
                'required_headcount' => 1,
                'line_notes' => 'Unlimited master license',
            ],
        ],
        'attachment' => $file,
    ];

    $response = $this->actingAs($this->adminUserA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post('/organization/recruitment/requirements', $payload);

    $response->assertRedirect('/organization/recruitment/requirements');

    $year = now()->year;
    $expectedNumber = "REQ-{$year}-000001";

    $requirement = RecruitmentRequirement::query()
        ->where('company_id', $this->companyA->id)
        ->where('requirement_number', $expectedNumber)
        ->first();

    expect($requirement)->not->toBeNull()
        ->and($requirement->client_id)->toBe($this->client->id)
        ->and($requirement->project_id)->toBe($this->project->id)
        ->and($requirement->priority->value)->toBe('urgent')
        ->and($requirement->status->value)->toBe('draft');

    expect($requirement->lines()->count())->toBe(2);

    $attachment = $requirement->attachments()->first();
    expect($attachment)->not->toBeNull()
        ->and($attachment->original_file_name)->toBe('client_specification.pdf');

    Storage::disk('local')->assertExists($attachment->file_path);
});

test('database rolls back completely if an invalid position line is provided', function () {
    $payload = [
        'client_id' => $this->client->id,
        'required_by_date' => now()->addDays(7)->format('Y-m-d'),
        'positions' => [
            [
                'position_id' => 99999, // non-existent position
                'required_headcount' => 1,
            ],
        ],
    ];

    $this->actingAs($this->adminUserA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson('/organization/recruitment/requirements', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['positions.0.position_id']);

    expect(RecruitmentRequirement::query()->count())->toBe(0)
        ->and(RecruitmentRequirementLine::query()->count())->toBe(0);
});

test('tenant isolation ensures Company B cannot view or modify Company A requirement', function () {
    // Create requirement under Company A
    $reqA = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-2026-000001',
        'client_id' => $this->client->id,
        'request_received_date' => now(),
        'required_by_date' => now()->addDays(10),
        'priority' => 'normal',
        'status' => RequirementStatus::Open,
        'created_by' => $this->adminUserA->id,
    ]);

    // Admin B in Company B attempts to access Company A requirement
    $this->actingAs($this->adminUserB)
        ->withSession(['current_company_id' => $this->companyB->id])
        ->get("/organization/recruitment/requirements/{$reqA->id}")
        ->assertNotFound();

    // Admin B in Company B attempts to put Company A requirement on hold
    $this->actingAs($this->adminUserB)
        ->withSession(['current_company_id' => $this->companyB->id])
        ->post("/organization/recruitment/requirements/{$reqA->id}/hold")
        ->assertNotFound();
});

test('check-similar endpoint detects active duplicate requirement', function () {
    // Create open requirement for client + positionChiefEng
    $req = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-2026-000001',
        'client_id' => $this->client->id,
        'project_id' => $this->project->id,
        'request_received_date' => now(),
        'required_by_date' => now()->addDays(10),
        'priority' => 'normal',
        'status' => RequirementStatus::Open,
        'created_by' => $this->adminUserA->id,
    ]);

    RecruitmentRequirementLine::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $req->id,
        'position_id' => $this->positionChiefEng->id,
        'required_headcount' => 3,
        'status' => RequirementLineStatus::Open,
    ]);

    // Check similarity for same client and position
    $response = $this->actingAs($this->adminUserA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson('/organization/recruitment/requirements/check-similar', [
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'positions' => [
                ['position_id' => $this->positionChiefEng->id],
            ],
        ]);

    $response->assertSuccessful()
        ->assertJson([
            'has_duplicates' => true,
        ])
        ->assertJsonFragment([
            'requirement_number' => 'REQ-2026-000001',
        ]);
});

test('can add headcount to an existing requirement via add-headcount action', function () {
    $req = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-2026-000001',
        'client_id' => $this->client->id,
        'request_received_date' => now(),
        'required_by_date' => now()->addDays(10),
        'priority' => 'normal',
        'status' => RequirementStatus::Open,
        'created_by' => $this->adminUserA->id,
    ]);

    $line = RecruitmentRequirementLine::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $req->id,
        'position_id' => $this->positionChiefEng->id,
        'required_headcount' => 2,
        'status' => RequirementLineStatus::Open,
    ]);

    $this->actingAs($this->adminUserA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/add-headcount", [
            'positions' => [
                [
                    'position_id' => $this->positionChiefEng->id,
                    'added_headcount' => 3,
                ],
            ],
            'reason' => 'Client requested 3 additional engineers.',
        ])
        ->assertRedirect();

    expect($line->fresh()->required_headcount)->toBe(5);
});

test('lifecycle transitions: draft -> open -> hold -> resume -> fill', function () {
    $req = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-2026-000001',
        'client_id' => $this->client->id,
        'request_received_date' => now(),
        'required_by_date' => now()->addDays(5),
        'priority' => 'normal',
        'status' => RequirementStatus::Draft,
        'created_by' => $this->adminUserA->id,
    ]);

    $line = RecruitmentRequirementLine::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $req->id,
        'position_id' => $this->positionChiefEng->id,
        'required_headcount' => 2,
        'status' => RequirementLineStatus::Open,
    ]);

    // 1. Open
    $this->actingAs($this->adminUserA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/open")
        ->assertRedirect();

    expect($req->fresh()->status)->toBe(RequirementStatus::Open)
        ->and($req->fresh()->opened_at)->not->toBeNull();

    // 2. Hold
    $this->actingAs($this->adminUserA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/hold")
        ->assertRedirect();

    expect($req->fresh()->status)->toBe(RequirementStatus::OnHold)
        ->and($line->fresh()->status)->toBe(RequirementLineStatus::OnHold);

    // 3. Resume
    $this->actingAs($this->adminUserA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/resume")
        ->assertRedirect();

    expect($req->fresh()->status)->toBe(RequirementStatus::Open)
        ->and($line->fresh()->status)->toBe(RequirementLineStatus::Open);

    // 4. Fill (Complete)
    $this->actingAs($this->adminUserA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/fill")
        ->assertRedirect();

    expect($req->fresh()->status)->toBe(RequirementStatus::Completed)
        ->and($req->fresh()->completed_at)->not->toBeNull()
        ->and($line->fresh()->status)->toBe(RequirementLineStatus::Filled);
});

test('cancelling a requirement sets status, marks lines cancelled, and records cancellation reason', function () {
    $req = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-2026-000001',
        'client_id' => $this->client->id,
        'request_received_date' => now(),
        'required_by_date' => now()->addDays(5),
        'priority' => 'normal',
        'status' => RequirementStatus::Open,
        'created_by' => $this->adminUserA->id,
    ]);

    $line = RecruitmentRequirementLine::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $req->id,
        'position_id' => $this->positionChiefEng->id,
        'required_headcount' => 1,
        'status' => RequirementLineStatus::Open,
    ]);

    $this->actingAs($this->adminUserA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/cancel", [
            'cancellation_reason' => 'Client postponed drilling operations until next quarter.',
        ])
        ->assertRedirect();

    $fresh = $req->fresh();
    expect($fresh->status)->toBe(RequirementStatus::Cancelled)
        ->and($fresh->cancellation_reason)->toBe('Client postponed drilling operations until next quarter.')
        ->and($fresh->cancelled_at)->not->toBeNull()
        ->and($line->fresh()->status)->toBe(RequirementLineStatus::Cancelled);
});

test('reopening a cancelled requirement restores it to open with a new deadline', function () {
    $req = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-2026-000001',
        'client_id' => $this->client->id,
        'request_received_date' => now()->subMonth(),
        'required_by_date' => now()->subDays(10),
        'priority' => 'normal',
        'status' => RequirementStatus::Cancelled,
        'cancellation_reason' => 'Cancelled earlier',
        'cancelled_at' => now()->subDays(5),
        'created_by' => $this->adminUserA->id,
    ]);

    $line = RecruitmentRequirementLine::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $req->id,
        'position_id' => $this->positionChiefEng->id,
        'required_headcount' => 1,
        'status' => RequirementLineStatus::Cancelled,
    ]);

    $newDeadline = now()->addDays(20)->format('Y-m-d');

    $this->actingAs($this->adminUserA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/reopen", [
            'new_required_by_date' => $newDeadline,
            'reason' => 'Client reinstated operations.',
        ])
        ->assertRedirect();

    $fresh = $req->fresh();
    expect($fresh->status)->toBe(RequirementStatus::Open)
        ->and($fresh->required_by_date->format('Y-m-d'))->toBe($newDeadline)
        ->and($line->fresh()->status)->toBe(RequirementLineStatus::Open);
});

test('repeating a requirement creates a new requisition linked via repeated_from_id', function () {
    $sourceReq = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-2026-000001',
        'client_id' => $this->client->id,
        'project_id' => $this->project->id,
        'location' => 'Safaniya Rig 4',
        'request_received_date' => now()->subMonths(2),
        'required_by_date' => now()->subMonth(),
        'priority' => 'normal',
        'status' => RequirementStatus::Completed,
        'completed_at' => now()->subMonth(),
        'created_by' => $this->adminUserA->id,
    ]);

    RecruitmentRequirementLine::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $sourceReq->id,
        'position_id' => $this->positionChiefEng->id,
        'required_headcount' => 2,
        'status' => RequirementLineStatus::Filled,
    ]);

    $response = $this->actingAs($this->adminUserA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$sourceReq->id}/repeat", [
            'request_received_date' => now()->format('Y-m-d'),
            'required_by_date' => now()->addDays(30)->format('Y-m-d'),
            'priority' => 'urgent',
            'reason' => 'Repeat requisition for next crew batch.',
        ]);

    $response->assertRedirect('/organization/recruitment/requirements');

    $repeated = RecruitmentRequirement::query()
        ->where('company_id', $this->companyA->id)
        ->where('repeated_from_id', $sourceReq->id)
        ->first();

    expect($repeated)->not->toBeNull()
        ->and($repeated->requirement_number)->toBe('REQ-2026-000002')
        ->and($repeated->client_id)->toBe($sourceReq->client_id)
        ->and($repeated->project_id)->toBe($sourceReq->project_id)
        ->and($repeated->status)->toBe(RequirementStatus::Draft)
        ->and($repeated->priority->value)->toBe('urgent')
        ->and($repeated->lines()->count())->toBe(1);
});

test('extending deadline updates required_by_date and changes deadline health', function () {
    $req = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-2026-000001',
        'client_id' => $this->client->id,
        'request_received_date' => now()->subDays(10),
        'required_by_date' => now()->subDay(), // currently overdue
        'priority' => 'normal',
        'status' => RequirementStatus::Open,
        'created_by' => $this->adminUserA->id,
    ]);

    $newDate = now()->addDays(25)->format('Y-m-d');

    $this->actingAs($this->adminUserA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/extend-deadline", [
            'new_required_by_date' => $newDate,
            'reason' => 'Client extended drilling mobilization window.',
        ])
        ->assertRedirect();

    expect($req->fresh()->required_by_date->format('Y-m-d'))->toBe($newDate);
});

test('revising line headcount target updates line count and recalculates total requirement headcount', function () {
    $req = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-2026-000001',
        'client_id' => $this->client->id,
        'request_received_date' => now(),
        'required_by_date' => now()->addDays(15),
        'priority' => 'normal',
        'status' => RequirementStatus::Open,
        'created_by' => $this->adminUserA->id,
    ]);

    $line = RecruitmentRequirementLine::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $req->id,
        'position_id' => $this->positionChiefEng->id,
        'required_headcount' => 2,
        'status' => RequirementLineStatus::Open,
    ]);

    $this->actingAs($this->adminUserA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/change-headcount", [
            'requirement_line_id' => $line->id,
            'new_headcount' => 7,
            'reason' => 'Vessel expansion requires 5 more engineers.',
        ])
        ->assertRedirect();

    expect($line->fresh()->required_headcount)->toBe(7);
});

test('private attachment download requires download permission and tenant authorization', function () {
    $req = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-2026-000001',
        'client_id' => $this->client->id,
        'request_received_date' => now(),
        'required_by_date' => now()->addDays(15),
        'priority' => 'normal',
        'status' => RequirementStatus::Open,
        'created_by' => $this->adminUserA->id,
    ]);

    Storage::disk('local')->put("recruitment/requirements/{$this->companyA->id}/{$req->id}/spec.pdf", 'test pdf content');

    $att = RecruitmentRequirementAttachment::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $req->id,
        'file_path' => "recruitment/requirements/{$this->companyA->id}/{$req->id}/spec.pdf",
        'original_file_name' => 'spec.pdf',
        'mime_type' => 'application/pdf',
        'file_size_bytes' => 15,
        'uploaded_by' => $this->adminUserA->id,
    ]);

    // Authorized download in Company A
    $this->actingAs($this->adminUserA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get("/organization/recruitment/requirements/{$req->id}/attachments/{$att->id}/download")
        ->assertSuccessful()
        ->assertHeader('content-disposition', 'attachment; filename=spec.pdf');

    // Cross-tenant download from Company B returns 404
    $this->actingAs($this->adminUserB)
        ->withSession(['current_company_id' => $this->companyB->id])
        ->get("/organization/recruitment/requirements/{$req->id}/attachments/{$att->id}/download")
        ->assertNotFound();
});
