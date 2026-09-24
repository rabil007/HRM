<?php

use App\Models\EmployeeDocument;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('users with permission can upload a document', function () {
    fakeEmployeeFileDisks();

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.upload']);

    $this->post("/organization/employees/{$employee->id}/documents", [
        'document_type_id' => $passportType->id,
        'title' => 'My Passport',
        'file' => UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf'),
        'issue_date' => '2020-01-01',
        'expiry_date' => '2030-01-01',
        'document_number' => 'P9876543',
        'notes' => 'Renewed in 2020',
    ])->assertRedirect();

    $this->assertDatabaseHas('employee_documents', [
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'document_type' => (string) $passportType->id,
        'original_filename' => 'passport.pdf',
        'mime_type' => 'application/pdf',
        'title' => 'My Passport',
        'document_number' => 'P9876543',
        'status' => 'valid',
    ]);

    $document = EmployeeDocument::query()->where('employee_id', $employee->id)->first();
    expect($document)->not->toBeNull();
    Storage::disk('local')->assertExists($document->file_path);
    Storage::disk('public')->assertMissing($document->file_path);
    expect($document->file_url)->not->toContain('/storage/');
});

test('upload rejects inactive or unknown document types and unsupported files', function () {
    fakeEmployeeFileDisks();

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'visaType' => $visaType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.upload']);

    $this->post("/organization/employees/{$employee->id}/documents", [
        'document_type_id' => 999_999,
        'file' => UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf'),
    ])->assertSessionHasErrors('document_type_id');

    $this->post("/organization/employees/{$employee->id}/documents", [
        'document_type_id' => $visaType->id,
        'file' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
    ])->assertSessionHasErrors('file');
});

test('users with permission can bulk upload documents', function () {
    fakeEmployeeFileDisks();

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType, 'visaType' => $visaType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.upload']);

    $this->post("/organization/employees/{$employee->id}/documents/bulk", [
        'documents' => [
            [
                'document_type_id' => $passportType->id,
                'title' => 'Passport',
                'file' => UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf'),
            ],
            [
                'document_type_id' => $visaType->id,
                'title' => 'Visa',
                'file' => UploadedFile::fake()->image('visa.jpg'),
                'expiry_date' => now()->addDays(20)->toDateString(),
            ],
        ],
    ])->assertRedirect();

    expect(EmployeeDocument::query()->where('employee_id', $employee->id)->count())->toBe(2);
    $this->assertDatabaseHas('employee_documents', [
        'employee_id' => $employee->id,
        'document_type_id' => $visaType->id,
        'status' => 'expiring_soon',
    ]);
});

test('bulk upload persists distinct metadata per document index', function () {
    fakeEmployeeFileDisks();

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType, 'visaType' => $visaType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.upload']);

    $this->post("/organization/employees/{$employee->id}/documents/bulk", [
        'documents' => [
            [
                'document_type_id' => $passportType->id,
                'title' => 'Passport Copy',
                'file' => UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf'),
                'document_number' => 'P-111',
                'issue_date' => '2019-06-01',
                'expiry_date' => '2029-06-01',
                'notes' => 'Primary travel document',
            ],
            [
                'document_type_id' => $visaType->id,
                'title' => 'UAE Residence Visa',
                'file' => UploadedFile::fake()->image('visa.jpg'),
                'document_number' => 'V-222',
                'expiry_date' => now()->addDays(45)->toDateString(),
                'notes' => 'Work permit visa',
            ],
        ],
    ])->assertRedirect();

    $this->assertDatabaseHas('employee_documents', [
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'title' => 'Passport Copy',
        'document_number' => 'P-111',
        'notes' => 'Primary travel document',
    ]);

    $this->assertDatabaseHas('employee_documents', [
        'employee_id' => $employee->id,
        'document_type_id' => $visaType->id,
        'title' => 'UAE Residence Visa',
        'document_number' => 'V-222',
        'notes' => 'Work permit visa',
    ]);
});

test('users without permission cannot upload a document', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $this->post("/organization/employees/{$employee->id}/documents", [
        'document_type_id' => $passportType->id,
        'file' => UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf'),
    ])->assertForbidden();
});

test('document status is derived correctly from expiry date', function () {
    fakeEmployeeFileDisks();

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'visaType' => $visaType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.upload']);

    $this->post("/organization/employees/{$employee->id}/documents", [
        'document_type_id' => $visaType->id,
        'file' => UploadedFile::fake()->create('visa.pdf', 100, 'application/pdf'),
        'expiry_date' => now()->subDay()->toDateString(),
    ])->assertRedirect();

    $this->assertDatabaseHas('employee_documents', [
        'employee_id' => $employee->id,
        'status' => 'expired',
    ]);
});

test('users with permission can edit document metadata', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.upload']);

    $doc = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/test/file.pdf',
        'status' => 'valid',
    ]);

    $this->put("/organization/employees/{$employee->id}/documents/{$doc->id}", [
        'document_type_id' => $passportType->id,
        'title' => 'Updated Title',
        'document_number' => 'P111',
        'expiry_date' => now()->addYears(5)->toDateString(),
    ])->assertRedirect();

    $this->assertDatabaseHas('employee_documents', [
        'id' => $doc->id,
        'title' => 'Updated Title',
        'document_number' => 'P111',
        'status' => 'valid',
    ]);
});

test('users with permission can delete a document', function () {
    fakeEmployeeFileDisks();

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.delete']);

    $doc = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/test/file.pdf',
        'status' => 'valid',
    ]);

    $this->delete("/organization/employees/{$employee->id}/documents/{$doc->id}")
        ->assertRedirect();

    $this->assertSoftDeleted('employee_documents', ['id' => $doc->id]);
});

test('users with permission can replace a document file and keep version history', function () {
    fakeEmployeeFileDisks();

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.upload']);

    $doc = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/test/old.pdf',
        'original_filename' => 'old.pdf',
        'mime_type' => 'application/pdf',
        'current_version' => 1,
        'status' => 'valid',
        'uploaded_by' => $user->id,
    ]);

    $this->post("/organization/employees/{$employee->id}/documents/{$doc->id}/replace", [
        'file' => UploadedFile::fake()->create('new.pdf', 100, 'application/pdf'),
    ])->assertRedirect();

    $doc->refresh();

    expect($doc->current_version)->toBe(2);
    expect($doc->uploaded_by)->toBe($user->id);
    $this->assertDatabaseHas('employee_document_versions', [
        'employee_document_id' => $doc->id,
        'version' => 1,
        'file_path' => 'employee-documents/test/old.pdf',
        'uploaded_by' => $user->id,
        'replaced_by' => $user->id,
    ]);
    Storage::disk('local')->assertExists($doc->file_path);
    Storage::disk('public')->assertMissing($doc->file_path);
});

test('document replacement preserves per-version upload provenance across multiple replacements', function () {
    fakeEmployeeFileDisks();

    $userA = User::factory()->create(['name' => 'Jan Andrei Cabanatan']);
    $userB = User::factory()->create(['name' => 'Suranga Galkissage']);
    $userC = User::factory()->create(['name' => 'User Charlie']);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    foreach ([$userA, $userB, $userC] as $actor) {
        grantCompanyPermissions($actor, $company, ['documents.upload', 'documents.view']);
    }

    Carbon::setTestNow('2026-06-11 10:00:00');

    $this->actingAs($userA)
        ->post("/organization/employees/{$employee->id}/documents", [
            'document_type_id' => $passportType->id,
            'title' => 'Passport',
            'file' => UploadedFile::fake()->create('passport-v1.pdf', 100, 'application/pdf'),
        ])
        ->assertRedirect();

    $document = EmployeeDocument::query()->where('employee_id', $employee->id)->firstOrFail();

    expect($document->current_version)->toBe(1)
        ->and($document->uploaded_by)->toBe($userA->id)
        ->and($document->currentUploadedAt()?->toDateTimeString())->toBe('2026-06-11 10:00:00');

    Carbon::setTestNow('2026-09-24 14:30:00');

    $this->actingAs($userB)
        ->post("/organization/employees/{$employee->id}/documents/{$document->id}/replace", [
            'file' => UploadedFile::fake()->create('passport-v2.pdf', 120, 'application/pdf'),
        ])
        ->assertRedirect();

    $document->refresh();

    expect($document->current_version)->toBe(2)
        ->and($document->uploaded_by)->toBe($userB->id)
        ->and($document->currentUploadedAt()?->toDateTimeString())->toBe('2026-09-24 14:30:00');

    $v1 = $document->versions()->where('version', 1)->firstOrFail();

    expect($v1->uploaded_by)->toBe($userA->id)
        ->and($v1->uploaded_at?->toDateTimeString())->toBe('2026-06-11 10:00:00')
        ->and($v1->replaced_by)->toBe($userB->id);

    Carbon::setTestNow('2026-10-01 09:00:00');

    $this->actingAs($userC)
        ->post("/organization/employees/{$employee->id}/documents/{$document->id}/replace", [
            'file' => UploadedFile::fake()->create('passport-v3.pdf', 140, 'application/pdf'),
        ])
        ->assertRedirect();

    $document->refresh()->load(['uploader:id,name', 'versions.uploader:id,name', 'versions.replacer:id,name']);

    expect($document->current_version)->toBe(3)
        ->and($document->uploaded_by)->toBe($userC->id)
        ->and($document->currentUploadedAt()?->toDateTimeString())->toBe('2026-10-01 09:00:00');

    $v2 = $document->versions()->where('version', 2)->firstOrFail();
    $v1 = $document->versions()->where('version', 1)->firstOrFail();

    expect($v2->uploaded_by)->toBe($userB->id)
        ->and($v2->uploaded_at?->toDateTimeString())->toBe('2026-09-24 14:30:00')
        ->and($v2->replaced_by)->toBe($userC->id)
        ->and($v1->uploaded_by)->toBe($userA->id)
        ->and($v1->uploaded_at?->toDateTimeString())->toBe('2026-06-11 10:00:00')
        ->and($v1->replaced_by)->toBe($userB->id);

    $history = $document->toVersionHistoryArray();

    expect($history)->toHaveCount(3)
        ->and($history[0]['version'])->toBe(3)
        ->and($history[0]['is_current'])->toBeTrue()
        ->and($history[0]['uploaded_by'])->toBe($userC->name)
        ->and($history[0]['key'])->toStartWith('current-')
        ->and($history[1]['version'])->toBe(2)
        ->and($history[1]['is_current'])->toBeFalse()
        ->and($history[1]['uploaded_by'])->toBe($userB->name)
        ->and($history[1]['key'])->toStartWith('version-')
        ->and($history[2]['version'])->toBe(1)
        ->and($history[2]['is_current'])->toBeFalse()
        ->and($history[2]['uploaded_by'])->toBe($userA->name);

    $browse = $document->toBrowseArray();
    expect($browse['uploaded_by'])->toBe($userC->name)
        ->and($browse['uploaded_at'])->not->toBeNull();

    $this->actingAs($userC)
        ->get("/organization/documents/employees/{$employee->id}/files/{$document->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/show')
            ->where('document.uploaded_by', $userC->name)
            ->where('document.current_version', 3)
            ->has('document.versions', 3)
            ->where('document.versions.0.version', 3)
            ->where('document.versions.0.is_current', true)
            ->where('document.versions.0.uploaded_by', $userC->name)
            ->where('document.versions.1.version', 2)
            ->where('document.versions.1.is_current', false)
            ->where('document.versions.1.uploaded_by', $userB->name)
            ->where('document.versions.2.version', 1)
            ->where('document.versions.2.is_current', false)
            ->where('document.versions.2.uploaded_by', $userA->name)
        );

    $this->actingAs($userC)
        ->getJson("/organization/employees/{$employee->id}/documents/{$document->id}/versions")
        ->assertOk()
        ->assertJsonCount(3, 'versions')
        ->assertJsonPath('versions.0.version', 3)
        ->assertJsonPath('versions.0.is_current', true)
        ->assertJsonPath('versions.0.uploaded_by', $userC->name)
        ->assertJsonPath('versions.1.version', 2)
        ->assertJsonPath('versions.1.is_current', false)
        ->assertJsonPath('versions.1.uploaded_by', $userB->name)
        ->assertJsonPath('versions.2.version', 1)
        ->assertJsonPath('versions.2.is_current', false)
        ->assertJsonPath('versions.2.uploaded_by', $userA->name);

    $this->actingAs($userC)
        ->get("/organization/documents/employees/{$employee->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('documents.0.uploaded_by', $userC->name)
            ->where('documents.0.uploaded_at', fn ($value) => $value !== null)
        );

    Carbon::setTestNow();
});

test('version history for v1-only documents marks the current file without fabricating archive rows', function () {
    fakeEmployeeFileDisks();

    $user = User::factory()->create(['name' => 'Solo Uploader']);
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.view']);

    $document = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/test/only-v1.pdf',
        'original_filename' => 'only-v1.pdf',
        'mime_type' => 'application/pdf',
        'current_version' => 1,
        'status' => 'valid',
        'uploaded_by' => $user->id,
    ]);

    $document->load('uploader:id,name');
    $history = $document->toVersionHistoryArray();

    expect($history)->toHaveCount(1)
        ->and($history[0]['is_current'])->toBeTrue()
        ->and($history[0]['version'])->toBe(1)
        ->and($history[0]['uploaded_by'])->toBe($user->name)
        ->and($history[0]['replaced_by'])->toBeNull();
});

test('nullable historical uploader data does not break version history serialization', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.view']);

    $document = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/test/passport.pdf',
        'original_filename' => 'Passport.pdf',
        'mime_type' => 'application/pdf',
        'current_version' => 2,
        'status' => 'valid',
        'uploaded_by' => null,
        'replaced_at' => now(),
    ]);

    $document->versions()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'version' => 1,
        'file_path' => 'employee-documents/test/passport-v1.pdf',
        'original_filename' => 'Passport-v1.pdf',
        'mime_type' => 'application/pdf',
        'uploaded_by' => null,
        'uploaded_at' => null,
        'replaced_by' => null,
    ]);

    $document->load(['uploader:id,name', 'versions.uploader:id,name', 'versions.replacer:id,name']);

    $history = $document->toVersionHistoryArray();

    expect($history)->toHaveCount(2)
        ->and($history[0]['uploaded_by'])->toBeNull()
        ->and($history[1]['uploaded_by'])->toBeNull()
        ->and($history[1]['is_current'])->toBeFalse();

    $this->get("/organization/documents/employees/{$employee->id}/files/{$document->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('document.versions', 2)
            ->where('document.versions.0.is_current', true)
            ->where('document.versions.1.is_current', false)
        );
});

test('legacy version provenance can be reconstructed from replacement chain', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $userC = User::factory()->create();

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $createdAt = '2026-06-11 10:00:00';
    $t2 = '2026-09-24 14:30:00';
    $t3 = '2026-10-01 09:00:00';

    // Simulate pre-fix legacy state: document.uploaded_by still V1 uploader after replacements.
    $documentId = DB::table('employee_documents')->insertGetId([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/test/current.pdf',
        'original_filename' => 'current.pdf',
        'mime_type' => 'application/pdf',
        'current_version' => 3,
        'status' => 'valid',
        'uploaded_by' => $userA->id,
        'replaced_at' => $t3,
        'created_at' => $createdAt,
        'updated_at' => $t3,
    ]);

    $v1Id = DB::table('employee_document_versions')->insertGetId([
        'employee_document_id' => $documentId,
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'version' => 1,
        'file_path' => 'employee-documents/test/v1.pdf',
        'original_filename' => 'v1.pdf',
        'mime_type' => 'application/pdf',
        'uploaded_by' => null,
        'uploaded_at' => null,
        'replaced_by' => $userB->id,
        'created_at' => $t2,
        'updated_at' => $t2,
    ]);

    $v2Id = DB::table('employee_document_versions')->insertGetId([
        'employee_document_id' => $documentId,
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'version' => 2,
        'file_path' => 'employee-documents/test/v2.pdf',
        'original_filename' => 'v2.pdf',
        'mime_type' => 'application/pdf',
        'uploaded_by' => null,
        'uploaded_at' => null,
        'replaced_by' => $userC->id,
        'created_at' => $t3,
        'updated_at' => $t3,
    ]);

    $document = (object) DB::table('employee_documents')->where('id', $documentId)->first();
    $versions = DB::table('employee_document_versions')
        ->where('employee_document_id', $documentId)
        ->orderBy('version')
        ->orderBy('id')
        ->get();

    $previousUploader = $document->uploaded_by;
    $previousUploadedAt = $document->created_at;

    foreach ($versions as $version) {
        DB::table('employee_document_versions')
            ->where('id', $version->id)
            ->update([
                'uploaded_by' => $previousUploader,
                'uploaded_at' => $previousUploadedAt,
            ]);

        $previousUploader = $version->replaced_by;
        $previousUploadedAt = $version->created_at;
    }

    if ($previousUploader !== null) {
        DB::table('employee_documents')
            ->where('id', $documentId)
            ->update(['uploaded_by' => $previousUploader]);
    }

    $this->assertDatabaseHas('employee_document_versions', [
        'id' => $v1Id,
        'uploaded_by' => $userA->id,
        'uploaded_at' => $createdAt,
        'replaced_by' => $userB->id,
    ]);
    $this->assertDatabaseHas('employee_document_versions', [
        'id' => $v2Id,
        'uploaded_by' => $userB->id,
        'uploaded_at' => $t2,
        'replaced_by' => $userC->id,
    ]);
    $this->assertDatabaseHas('employee_documents', [
        'id' => $documentId,
        'uploaded_by' => $userC->id,
    ]);
});

test('users with permission can replace a document file and update document number, issue date and expiry date', function () {
    fakeEmployeeFileDisks();

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.upload']);

    $doc = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/test/old.pdf',
        'original_filename' => 'old.pdf',
        'mime_type' => 'application/pdf',
        'current_version' => 1,
        'document_number' => 'OLD-NUM-123',
        'issue_date' => '2026-01-01',
        'expiry_date' => '2027-01-01',
        'status' => 'valid',
    ]);

    $this->post("/organization/employees/{$employee->id}/documents/{$doc->id}/replace", [
        'file' => UploadedFile::fake()->create('new.pdf', 100, 'application/pdf'),
        'document_number' => 'NEW-NUM-456',
        'issue_date' => '2026-06-01',
        'expiry_date' => '2028-02-02',
    ])->assertRedirect();

    $doc->refresh();

    expect($doc->current_version)->toBe(2);
    expect($doc->document_number)->toBe('NEW-NUM-456');
    expect($doc->issue_date->toDateString())->toBe('2026-06-01');
    expect($doc->expiry_date->toDateString())->toBe('2028-02-02');
    expect($doc->status)->toBe('valid');
});

test('documents folder index lists employees with uploads', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'visaType' => $visaType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.view']);

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $visaType->id,
        'type' => 'other',
        'document_type' => (string) $visaType->id,
        'file_path' => 'employee-documents/test/visa.pdf',
        'expiry_date' => now()->subDay()->toDateString(),
        'status' => 'expired',
    ]);

    $this->get('/organization/documents/library')
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/index')
            ->has('employees', 1)
            ->where('employees.0.employee_id', $employee->id)
            ->where('employees.0.document_count', 1)
        );
});

test('dashboard includes document compliance stats', function () {
    Carbon::setTestNow('2026-05-20 12:00:00');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'visaType' => $visaType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.view']);

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $visaType->id,
        'type' => 'other',
        'document_type' => (string) $visaType->id,
        'file_path' => 'employee-documents/test/visa.pdf',
        'expiry_date' => '2026-05-10',
        'status' => 'expired',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('document_compliance.total_documents', 1)
            ->where('document_compliance.expired', 1)
        );

    Carbon::setTestNow();
});

test('users cannot manage documents for employees in another company', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'visaType' => $visaType] = makeDocumentFixtures();
    ['employee' => $otherEmployee] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.upload', 'documents.delete']);

    $this->post("/organization/employees/{$otherEmployee->id}/documents", [
        'document_type_id' => $visaType->id,
        'file' => UploadedFile::fake()->create('visa.pdf', 100, 'application/pdf'),
    ])->assertForbidden();
});

test('employee profile documents include unified expiry serialization fields', function () {
    Carbon::setTestNow('2026-05-20');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.view', 'documents.view', 'documents.download']);

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'title' => 'Passport Copy',
        'file_path' => 'employee-documents/test/passport.pdf',
        'original_filename' => 'passport.pdf',
        'expiry_date' => '2026-05-25',
        'status' => 'expiring_soon',
    ]);

    $this->get("/organization/employees/{$employee->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => assertEmployeeProfileRecords(
            $page->component('organization/employee'),
            fn (Assert $page) => $page
                ->where('documents.0.title', 'Passport Copy')
                ->where('documents.0.expiry_date', '2026-05-25')
                ->where('documents.0.expiry_status', 'expiring_7')
                ->where('documents.0.expiry_label', 'Expires in 5 days')
                ->where('documents.0.remaining_days', 5),
        ));

    Carbon::setTestNow();
});
