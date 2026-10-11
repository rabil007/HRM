<?php

use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementPriority;
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
use App\Support\Recruitment\RequirementAttachmentStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

function createAttachmentTestCompany(string $name, string $code): Company
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

function createAttachmentTestUser(Company $company, array $permissions = []): User
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
    Storage::fake(RequirementAttachmentStorage::DISK);
    $this->companyA = createAttachmentTestCompany('Alpha Marine', 'ALM');
    $this->companyB = createAttachmentTestCompany('Beta Logistics', 'BLM');

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

    $this->userCreator = createAttachmentTestUser($this->companyA, $permissions);
    $this->userOther = createAttachmentTestUser($this->companyA, $permissions);
    $this->userNoPerm = createAttachmentTestUser($this->companyA, ['recruitment.requirements.view']);
    $this->userCompanyB = createAttachmentTestUser($this->companyB, $permissions);

    $this->client = Client::query()->create([
        'name' => 'Test Client',
        'is_active' => true,
    ]);

    $this->project = Project::query()->create([
        'title' => 'Alpha Vessel Repair',
        'is_active' => true,
    ]);
    $this->project->clients()->sync([$this->client->id]);

    $this->position = Position::query()->create([
        'company_id' => $this->companyA->id,
        'title' => 'Chief Engineer',
        'status' => 'active',
    ]);
});

test('authorized creator can upload attachment via dedicated endpoint and leaves requirement fields unchanged', function () {
    $requirement = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-2026-000100',
        'client_id' => $this->client->id,
        'project_id' => $this->project->id,
        'request_received_date' => '2026-10-01',
        'required_by_date' => '2026-10-20',
        'priority' => RequirementPriority::Urgent,
        'status' => RequirementStatus::Draft,
        'created_by' => $this->userCreator->id,
        'notes' => 'Original requirement notes',
        'lock_version' => 1,
    ]);

    $line = RecruitmentRequirementLine::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $requirement->id,
        'position_id' => $this->position->id,
        'required_headcount' => 3,
        'status' => RequirementLineStatus::Open,
        'lock_version' => 1,
    ]);

    $file = UploadedFile::fake()->create('contract.pdf', 500, 'application/pdf');

    $response = $this->actingAs($this->userCreator)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$requirement->id}/attachments", [
            'attachment' => $file,
        ]);

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();
    $response->assertSessionHas('success', 'Attachment uploaded successfully.');

    // Assert attachment created in DB and stored on disk
    $this->assertDatabaseHas('recruitment_requirement_attachments', [
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $requirement->id,
        'original_file_name' => 'contract.pdf',
        'mime_type' => 'application/pdf',
        'uploaded_by' => $this->userCreator->id,
    ]);

    $attachment = RecruitmentRequirementAttachment::query()
        ->where('recruitment_requirement_id', $requirement->id)
        ->first();
    expect($attachment)->not->toBeNull();
    Storage::disk(RequirementAttachmentStorage::DISK)->assertExists($attachment->file_path);

    // Assert requirement fields are untouched
    $requirement->refresh();
    expect($requirement->priority)->toBe(RequirementPriority::Urgent)
        ->and($requirement->notes)->toBe('Original requirement notes')
        ->and($requirement->client_id)->toBe($this->client->id)
        ->and($requirement->project_id)->toBe($this->project->id)
        ->and($requirement->status)->toBe(RequirementStatus::Draft)
        ->and($requirement->created_by)->toBe($this->userCreator->id)
        ->and($requirement->assigned_to)->toBeNull();

    $line->refresh();
    expect($line->required_headcount)->toBe(3)
        ->and($line->status)->toBe(RequirementLineStatus::Open);
});

test('upload rejects invalid file format or size exceeding 20MB with 422', function () {
    $requirement = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-2026-000101',
        'client_id' => $this->client->id,
        'priority' => RequirementPriority::Normal,
        'status' => RequirementStatus::Draft,
        'created_by' => $this->userCreator->id,
        'lock_version' => 1,
    ]);

    // 1. Invalid mime (executable)
    $badFile = UploadedFile::fake()->create('malicious.exe', 100, 'application/x-msdownload');
    $this->actingAs($this->userCreator)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$requirement->id}/attachments", [
            'attachment' => $badFile,
        ])
        ->assertStatus(302)
        ->assertSessionHasErrors(['attachment']);

    // 2. Too large (> 20MB = 20480 KB)
    $tooBig = UploadedFile::fake()->create('huge.pdf', 25000, 'application/pdf');
    $this->actingAs($this->userCreator)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$requirement->id}/attachments", [
            'attachment' => $tooBig,
        ])
        ->assertStatus(302)
        ->assertSessionHasErrors(['attachment']);
});

test('upload is denied for non-owner, missing permission, non-editable status and cross-company', function () {
    $draftReq = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-2026-000102',
        'client_id' => $this->client->id,
        'priority' => RequirementPriority::Normal,
        'status' => RequirementStatus::Draft,
        'created_by' => $this->userCreator->id,
        'lock_version' => 1,
    ]);

    $openReq = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-2026-000103',
        'client_id' => $this->client->id,
        'priority' => RequirementPriority::Normal,
        'status' => RequirementStatus::Open,
        'created_by' => $this->userCreator->id,
        'lock_version' => 1,
    ]);

    $file = UploadedFile::fake()->create('doc.pdf', 200, 'application/pdf');

    // 1. Another requester denied (403)
    $this->actingAs($this->userOther)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$draftReq->id}/attachments", [
            'attachment' => $file,
        ])
        ->assertForbidden();

    // 2. Missing update permission denied (403)
    $this->actingAs($this->userNoPerm)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$draftReq->id}/attachments", [
            'attachment' => $file,
        ])
        ->assertForbidden();

    // 3. Non-editable status denied (403)
    $this->actingAs($this->userCreator)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->post("/organization/recruitment/requirements/{$openReq->id}/attachments", [
            'attachment' => $file,
        ])
        ->assertForbidden();

    // 4. Cross-company access returns 404
    $this->actingAs($this->userCompanyB)
        ->withSession(['current_company_id' => $this->companyB->id])
        ->post("/organization/recruitment/requirements/{$draftReq->id}/attachments", [
            'attachment' => $file,
        ])
        ->assertNotFound();
});

test('upload rolls back file storage if transaction fails', function () {
    $requirement = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-2026-000104',
        'client_id' => $this->client->id,
        'priority' => RequirementPriority::Normal,
        'status' => RequirementStatus::Draft,
        'created_by' => $this->userCreator->id,
        'lock_version' => 1,
    ]);

    // Force failure during attachment creation by listening on creating event
    RecruitmentRequirementAttachment::creating(function () {
        throw new RuntimeException('Simulated database failure during attachment upload');
    });

    $file = UploadedFile::fake()->create('fail.pdf', 300, 'application/pdf');

    try {
        $this->actingAs($this->userCreator)
            ->withSession(['current_company_id' => $this->companyA->id])
            ->post("/organization/recruitment/requirements/{$requirement->id}/attachments", [
                'attachment' => $file,
            ]);
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('Simulated database failure during attachment upload');
    }

    // Verify disk has no leaked files for this requirement
    $allFiles = Storage::disk(RequirementAttachmentStorage::DISK)->allFiles();
    expect($allFiles)->toBeEmpty();
});

test('authorized creator can delete attachment from draft or returned requirement and file is removed post-commit', function () {
    $requirement = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-2026-000105',
        'client_id' => $this->client->id,
        'priority' => RequirementPriority::Normal,
        'status' => RequirementStatus::Draft,
        'created_by' => $this->userCreator->id,
        'lock_version' => 1,
    ]);

    $storagePath = "recruitment/requirements/{$this->companyA->id}/{$requirement->id}/to-delete.pdf";
    Storage::disk(RequirementAttachmentStorage::DISK)->put($storagePath, 'file content');

    $attachment = RecruitmentRequirementAttachment::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $requirement->id,
        'file_path' => $storagePath,
        'original_file_name' => 'to-delete.pdf',
        'mime_type' => 'application/pdf',
        'file_size_bytes' => 12,
        'file_checksum' => hash('sha256', 'file content'),
        'uploaded_by' => $this->userCreator->id,
    ]);

    Storage::disk(RequirementAttachmentStorage::DISK)->assertExists($storagePath);

    $response = $this->actingAs($this->userCreator)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->delete("/organization/recruitment/requirements/{$requirement->id}/attachments/{$attachment->id}");

    $response->assertSessionHasNoErrors();
    $response->assertRedirect();
    $response->assertSessionHas('success', 'Attachment removed successfully.');

    // DB record removed
    $this->assertDatabaseMissing('recruitment_requirement_attachments', [
        'id' => $attachment->id,
    ]);

    // File removed from disk
    Storage::disk(RequirementAttachmentStorage::DISK)->assertMissing($storagePath);
});

test('attachment deletion is denied for another requester, missing permission, non-editable requirement and cross-company', function () {
    $draftReq = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-2026-000106',
        'client_id' => $this->client->id,
        'priority' => RequirementPriority::Normal,
        'status' => RequirementStatus::Draft,
        'created_by' => $this->userCreator->id,
        'lock_version' => 1,
    ]);

    $openReq = RecruitmentRequirement::query()->create([
        'company_id' => $this->companyA->id,
        'requirement_number' => 'REQ-2026-000107',
        'client_id' => $this->client->id,
        'priority' => RequirementPriority::Normal,
        'status' => RequirementStatus::Open,
        'created_by' => $this->userCreator->id,
        'lock_version' => 1,
    ]);

    $pathDraft = "recruitment/requirements/{$this->companyA->id}/{$draftReq->id}/draft.pdf";
    Storage::disk(RequirementAttachmentStorage::DISK)->put($pathDraft, 'draft content');
    $attDraft = RecruitmentRequirementAttachment::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $draftReq->id,
        'file_path' => $pathDraft,
        'original_file_name' => 'draft.pdf',
        'mime_type' => 'application/pdf',
        'file_size_bytes' => 13,
        'file_checksum' => hash('sha256', 'draft content'),
        'uploaded_by' => $this->userCreator->id,
    ]);

    $pathOpen = "recruitment/requirements/{$this->companyA->id}/{$openReq->id}/open.pdf";
    Storage::disk(RequirementAttachmentStorage::DISK)->put($pathOpen, 'open content');
    $attOpen = RecruitmentRequirementAttachment::query()->create([
        'company_id' => $this->companyA->id,
        'recruitment_requirement_id' => $openReq->id,
        'file_path' => $pathOpen,
        'original_file_name' => 'open.pdf',
        'mime_type' => 'application/pdf',
        'file_size_bytes' => 12,
        'file_checksum' => hash('sha256', 'open content'),
        'uploaded_by' => $this->userCreator->id,
    ]);

    // 1. Another requester denied (403)
    $this->actingAs($this->userOther)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->delete("/organization/recruitment/requirements/{$draftReq->id}/attachments/{$attDraft->id}")
        ->assertForbidden();

    // 2. User without update permission denied (403)
    $this->actingAs($this->userNoPerm)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->delete("/organization/recruitment/requirements/{$draftReq->id}/attachments/{$attDraft->id}")
        ->assertForbidden();

    // 3. Non-editable requirement denied (403)
    $this->actingAs($this->userCreator)
        ->withSession(['current_company_id' => $this->companyA->id])
        ->delete("/organization/recruitment/requirements/{$openReq->id}/attachments/{$attOpen->id}")
        ->assertForbidden();

    // 4. Cross-company access returns 404
    $this->actingAs($this->userCompanyB)
        ->withSession(['current_company_id' => $this->companyB->id])
        ->delete("/organization/recruitment/requirements/{$draftReq->id}/attachments/{$attDraft->id}")
        ->assertNotFound();

    // Verify records and files were not deleted
    $this->assertDatabaseHas('recruitment_requirement_attachments', ['id' => $attDraft->id]);
    $this->assertDatabaseHas('recruitment_requirement_attachments', ['id' => $attOpen->id]);
    Storage::disk(RequirementAttachmentStorage::DISK)->assertExists($pathDraft);
    Storage::disk(RequirementAttachmentStorage::DISK)->assertExists($pathOpen);
});
