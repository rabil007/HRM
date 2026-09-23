<?php

use App\Actions\Recruitment\CreateRequirementAction;
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
use App\Support\Recruitment\RequirementPresenter;
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

    $this->positionCraneOpB = Position::query()->create([
        'company_id' => $this->companyB->id,
        'title' => 'Crane Operator',
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

test('check-similar returns canonical duplicate DTO contract', function () {
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
        ->assertJsonStructure([
            'has_duplicates',
            'duplicates' => [
                '*' => [
                    'id',
                    'requirement_number',
                    'client_id',
                    'client_name',
                    'project_id',
                    'project_title',
                    'status',
                    'status_label',
                    'required_by_date',
                    'required_by_date_formatted',
                    'total_headcount',
                    'matching_positions',
                    'positions',
                ],
            ],
            'similar',
        ]);
});

test('store detects duplicates and rejects creation unless ignore_duplicate_warning is set', function () {
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
        'required_headcount' => 2,
        'status' => RequirementLineStatus::Open,
    ]);

    // 1. JSON request with duplicate returns 422 and does NOT create a new requirement
    $responseJson = $this->actingAs($this->adminUserA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson('/organization/recruitment/requirements', [
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'request_received_date' => now()->format('Y-m-d'),
            'required_by_date' => now()->addDays(15)->format('Y-m-d'),
            'priority' => 'normal',
            'lines' => [
                [
                    'position_id' => $this->positionChiefEng->id,
                    'required_headcount' => 1,
                ],
            ],
        ]);

    $responseJson->assertStatus(422)
        ->assertJson([
            'has_duplicates' => true,
        ]);

    expect(RecruitmentRequirement::query()->where('company_id', $this->companyA->id)->count())->toBe(1);

    // 2. Inertia/Web request with duplicate redirects back with error and does NOT create record
    $responseWeb = $this->actingAs($this->adminUserA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->from('/organization/recruitment/requirements')
        ->post('/organization/recruitment/requirements', [
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'request_received_date' => now()->format('Y-m-d'),
            'required_by_date' => now()->addDays(15)->format('Y-m-d'),
            'priority' => 'normal',
            'lines' => [
                [
                    'position_id' => $this->positionChiefEng->id,
                    'required_headcount' => 1,
                ],
            ],
        ]);

    $responseWeb->assertRedirect('/organization/recruitment/requirements')
        ->assertSessionHasErrors(['duplicate']);

    expect(RecruitmentRequirement::query()->where('company_id', $this->companyA->id)->count())->toBe(1);

    // 3. Request with ignore_duplicate_warning = true succeeds
    $responseIgnored = $this->actingAs($this->adminUserA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post('/organization/recruitment/requirements', [
            'client_id' => $this->client->id,
            'project_id' => $this->project->id,
            'request_received_date' => now()->format('Y-m-d'),
            'required_by_date' => now()->addDays(15)->format('Y-m-d'),
            'priority' => 'normal',
            'ignore_duplicate_warning' => true,
            'lines' => [
                [
                    'position_id' => $this->positionChiefEng->id,
                    'required_headcount' => 1,
                ],
            ],
        ]);

    $responseIgnored->assertRedirect('/organization/recruitment/requirements')
        ->assertSessionHasNoErrors();

    expect(RecruitmentRequirement::query()->where('company_id', $this->companyA->id)->count())->toBe(2);
});

test('add-headcount validates reason length and rejects foreign company positions', function () {
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

    // Reason too short (< 3 chars)
    $this->actingAs($this->adminUserA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/add-headcount", [
            'positions' => [
                [
                    'position_id' => $this->positionChiefEng->id,
                    'added_headcount' => 2,
                ],
            ],
            'reason' => 'ok',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);

    // Position belonging to Company B
    $this->actingAs($this->adminUserA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/add-headcount", [
            'positions' => [
                [
                    'position_id' => $this->positionCraneOpB->id,
                    'added_headcount' => 2,
                ],
            ],
            'reason' => 'Adding crane operator from another company',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lines.0.position_id']);
});

test('recruiter assignment rejects users not belonging to current company', function () {
    // Attempt to store requirement with recruiter from Company B
    $this->actingAs($this->adminUserA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson('/organization/recruitment/requirements', [
            'client_id' => $this->client->id,
            'request_received_date' => now()->format('Y-m-d'),
            'required_by_date' => now()->addDays(10)->format('Y-m-d'),
            'priority' => 'normal',
            'assigned_to' => $this->adminUserB->id,
            'lines' => [
                [
                    'position_id' => $this->positionChiefEng->id,
                    'required_headcount' => 1,
                ],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['assigned_to']);

    // Attempt to update requirement with recruiter from Company B
    $req = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-2026-000001',
        'client_id' => $this->client->id,
        'request_received_date' => now(),
        'required_by_date' => now()->addDays(10),
        'priority' => 'normal',
        'status' => RequirementStatus::Draft,
        'created_by' => $this->adminUserA->id,
    ]);

    $this->actingAs($this->adminUserA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->putJson("/organization/recruitment/requirements/{$req->id}", [
            'client_id' => $this->client->id,
            'request_received_date' => now()->format('Y-m-d'),
            'priority' => 'normal',
            'assigned_to' => $this->adminUserB->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['assigned_to']);
});

test('generic edit cannot alter required_by_date or lines and enforces date order', function () {
    $req = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-2026-000001',
        'client_id' => $this->client->id,
        'request_received_date' => now()->startOfDay(),
        'required_by_date' => now()->addDays(10)->startOfDay(),
        'priority' => 'normal',
        'status' => RequirementStatus::Draft,
        'created_by' => $this->adminUserA->id,
    ]);

    $line = RecruitmentRequirementLine::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $req->id,
        'position_id' => $this->positionChiefEng->id,
        'required_headcount' => 3,
        'status' => RequirementLineStatus::Open,
    ]);

    $originalDeadline = $req->required_by_date->format('Y-m-d');

    // 1. Generic edit with altered client reference number succeeds, but ignoring required_by_date and lines
    $this->actingAs($this->adminUserA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->put("/organization/recruitment/requirements/{$req->id}", [
            'client_id' => $this->client->id,
            'client_reference_number' => 'REF-MODIFIED-99',
            'request_received_date' => now()->format('Y-m-d'),
            'required_by_date' => now()->addDays(50)->format('Y-m-d'), // should NOT be applied
            'priority' => 'urgent',
            'lines' => [ // should NOT alter existing lines
                [
                    'position_id' => $this->positionChiefEng->id,
                    'required_headcount' => 99,
                ],
            ],
        ])
        ->assertRedirect();

    $fresh = $req->fresh();
    expect($fresh->client_reference_number)->toBe('REF-MODIFIED-99')
        ->and($fresh->priority->value)->toBe('urgent')
        ->and($fresh->required_by_date->format('Y-m-d'))->toBe($originalDeadline)
        ->and($line->fresh()->required_headcount)->toBe(3);

    // 2. Request received date after existing deadline fails validation
    $this->actingAs($this->adminUserA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->putJson("/organization/recruitment/requirements/{$req->id}", [
            'client_id' => $this->client->id,
            'request_received_date' => now()->addDays(20)->format('Y-m-d'),
            'priority' => 'normal',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['request_received_date']);
});

test('extend-deadline rejects invalid dates and reasons and accepts strictly later dates', function () {
    $req = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-2026-000001',
        'client_id' => $this->client->id,
        'request_received_date' => now()->startOfDay(),
        'required_by_date' => now()->addDays(10)->startOfDay(),
        'priority' => 'normal',
        'status' => RequirementStatus::Open,
        'created_by' => $this->adminUserA->id,
    ]);

    // Same date as current deadline is rejected
    $this->actingAs($this->adminUserA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/extend-deadline", [
            'new_date' => now()->addDays(10)->format('Y-m-d'),
            'reason' => 'Valid extension reason',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['new_date']);

    // Earlier date than current deadline is rejected
    $this->actingAs($this->adminUserA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/extend-deadline", [
            'new_date' => now()->addDays(5)->format('Y-m-d'),
            'reason' => 'Valid extension reason',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['new_date']);

    // Reason too short
    $this->actingAs($this->adminUserA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$req->id}/extend-deadline", [
            'new_date' => now()->addDays(20)->format('Y-m-d'),
            'reason' => 'no',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);

    // Valid extension succeeds
    $this->actingAs($this->adminUserA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/extend-deadline", [
            'new_date' => now()->addDays(20)->format('Y-m-d'),
            'reason' => 'Mobilization window delayed by port authority.',
        ])
        ->assertRedirect();

    expect($req->fresh()->required_by_date->format('Y-m-d'))->toBe(now()->addDays(20)->format('Y-m-d'));
});

test('repeat requirement rejects active requirements and accepts completed or cancelled', function () {
    // 1. Open requirement cannot be repeated
    $openReq = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-2026-000001',
        'client_id' => $this->client->id,
        'request_received_date' => now(),
        'required_by_date' => now()->addDays(10),
        'priority' => 'normal',
        'status' => RequirementStatus::Open,
        'created_by' => $this->adminUserA->id,
    ]);

    RecruitmentRequirementLine::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $openReq->id,
        'position_id' => $this->positionChiefEng->id,
        'required_headcount' => 1,
        'status' => RequirementLineStatus::Open,
    ]);

    $this->actingAs($this->adminUserA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->postJson("/organization/recruitment/requirements/{$openReq->id}/repeat", [
            'request_received_date' => now()->format('Y-m-d'),
            'required_by_date' => now()->addDays(30)->format('Y-m-d'),
            'priority' => 'normal',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);

    // 2. Cancelled requirement can be repeated
    $cancelledReq = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-2026-000002',
        'client_id' => $this->client->id,
        'request_received_date' => now()->subMonth(),
        'required_by_date' => now()->subDays(5),
        'priority' => 'normal',
        'status' => RequirementStatus::Cancelled,
        'created_by' => $this->adminUserA->id,
    ]);

    RecruitmentRequirementLine::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $cancelledReq->id,
        'position_id' => $this->positionChiefEng->id,
        'required_headcount' => 2,
        'status' => RequirementLineStatus::Cancelled,
    ]);

    $this->actingAs($this->adminUserA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$cancelledReq->id}/repeat", [
            'request_received_date' => now()->format('Y-m-d'),
            'required_by_date' => now()->addDays(30)->format('Y-m-d'),
            'priority' => 'normal',
            'reason' => 'Repeat after cancelled project revival',
        ])
        ->assertRedirect('/organization/recruitment/requirements');
});

test('view-only users receive false for all mutation actions in presenter', function () {
    $viewOnlyUser = createRecruitmentTestUser($this->companyA, ['recruitment.requirements.view']);

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

    $req->load(['client', 'project', 'assignedRecruiter', 'lines.position']);

    $showData = RequirementPresenter::toShow($req, today: null, user: $viewOnlyUser);

    expect($showData['can_edit'])->toBeFalse()
        ->and($showData['can_open'])->toBeFalse()
        ->and($showData['can_hold'])->toBeFalse()
        ->and($showData['can_resume'])->toBeFalse()
        ->and($showData['can_extend_deadline'])->toBeFalse()
        ->and($showData['can_change_headcount'])->toBeFalse()
        ->and($showData['can_fill'])->toBeFalse()
        ->and($showData['can_cancel'])->toBeFalse()
        ->and($showData['can_reopen'])->toBeFalse()
        ->and($showData['can_repeat'])->toBeFalse();
});

test('attachment file is deleted from disk if transaction fails during creation', function () {
    Storage::fake('local');
    $file = UploadedFile::fake()->create('spec.pdf', 100, 'application/pdf');

    $action = app(CreateRequirementAction::class);

    try {
        $action->execute(
            $this->companyA->id,
            $this->adminUserA->id,
            [
                'client_id' => $this->client->id,
                'request_received_date' => now()->format('Y-m-d'),
                'required_by_date' => now()->addDays(10)->format('Y-m-d'),
                'priority' => 'normal',
                'lines' => [
                    ['position_id' => null, 'required_headcount' => 1],
                ],
            ],
            $file,
        );
        $this->fail('Expected exception was not thrown');
    } catch (Throwable $e) {
        // Expected exception
    }

    expect(Storage::disk('local')->allFiles())->toBeEmpty();
});

test('parent recruitment route redirects authorized user to requirements', function () {
    $this->actingAs($this->adminUserA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get('/organization/recruitment')
        ->assertRedirect(route('organization.recruitment.requirements.index'));
});

test('parent recruitment route denies unauthorized user', function () {
    $unauthorizedUser = createRecruitmentTestUser($this->companyA, []);

    $this->actingAs($unauthorizedUser)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->get('/organization/recruitment')
        ->assertForbidden();
});

test('parent recruitment route requires authentication', function () {
    $this->get('/organization/recruitment')
        ->assertRedirect(route('login'));
});

test('adding headcount to an OnHold requirement keeps existing and newly created lines OnHold until resumed', function () {
    $req = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-2026-000001',
        'client_id' => $this->client->id,
        'request_received_date' => now(),
        'required_by_date' => now()->addDays(15),
        'priority' => 'normal',
        'status' => RequirementStatus::OnHold,
        'created_by' => $this->adminUserA->id,
    ]);

    $existingLine = RecruitmentRequirementLine::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $req->id,
        'position_id' => $this->positionChiefEng->id,
        'required_headcount' => 2,
        'status' => RequirementLineStatus::OnHold,
    ]);

    // 1. Headcount can be added to an OnHold requirement
    $this->actingAs($this->adminUserA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/add-headcount", [
            'positions' => [
                [
                    'position_id' => $this->positionChiefEng->id,
                    'added_headcount' => 3,
                ],
                [
                    'position_id' => $this->positionCaptain->id,
                    'added_headcount' => 1,
                ],
            ],
            'reason' => 'Client added positions while requisition is on hold.',
        ])
        ->assertRedirect(route('organization.recruitment.requirements.show', $req));

    // 2. Existing and newly created lines remain OnHold
    $existingLine->refresh();
    expect($existingLine->required_headcount)->toBe(5)
        ->and($existingLine->status)->toBe(RequirementLineStatus::OnHold);

    $newLine = $req->lines()->where('position_id', $this->positionCaptain->id)->firstOrFail();
    expect($newLine->required_headcount)->toBe(1)
        ->and($newLine->status)->toBe(RequirementLineStatus::OnHold);

    // 3. Resuming the requirement changes those OnHold lines to Open
    $this->actingAs($this->adminUserA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$req->id}/resume")
        ->assertRedirect();

    expect($req->fresh()->status)->toBe(RequirementStatus::Open)
        ->and($existingLine->fresh()->status)->toBe(RequirementLineStatus::Open)
        ->and($newLine->fresh()->status)->toBe(RequirementLineStatus::Open);
});

test('adding headcount to Draft and Open requirements sets lines to Open status', function () {
    $draftReq = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-2026-000002',
        'client_id' => $this->client->id,
        'request_received_date' => now(),
        'required_by_date' => now()->addDays(15),
        'priority' => 'normal',
        'status' => RequirementStatus::Draft,
        'created_by' => $this->adminUserA->id,
    ]);

    $draftLine = RecruitmentRequirementLine::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $draftReq->id,
        'position_id' => $this->positionChiefEng->id,
        'required_headcount' => 1,
        'status' => RequirementLineStatus::Open,
    ]);

    $this->actingAs($this->adminUserA)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$draftReq->id}/add-headcount", [
            'positions' => [
                [
                    'position_id' => $this->positionChiefEng->id,
                    'added_headcount' => 2,
                ],
                [
                    'position_id' => $this->positionCaptain->id,
                    'added_headcount' => 1,
                ],
            ],
            'reason' => 'Draft requisition additions.',
        ])
        ->assertRedirect();

    expect($draftLine->fresh()->required_headcount)->toBe(3)
        ->and($draftLine->fresh()->status)->toBe(RequirementLineStatus::Open);

    $draftNewLine = $draftReq->lines()->where('position_id', $this->positionCaptain->id)->firstOrFail();
    expect($draftNewLine->required_headcount)->toBe(1)
        ->and($draftNewLine->status)->toBe(RequirementLineStatus::Open);
});
