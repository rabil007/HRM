<?php

use App\Enums\DocumentShareScope;
use App\Models\DocumentShare;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Support\EmployeeDocuments\DocumentShareLinkService;
use App\Support\EmployeeDocuments\DocumentShareService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot request document share links', function () {
    $this->postJson(route('organization.documents.employee.files.share-links', 1), [
        'document_ids' => [1],
    ])->assertUnauthorized();
});

test('users without documents share permission cannot request share links', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.view']);

    $path = "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf";
    $doc = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, $path, 'Passport.pdf');

    $this->postJson(route('organization.documents.employee.files.share-links', $employee), [
        'document_ids' => [$doc->id],
    ])->assertForbidden();
});

test('users can request one persisted share link for selected documents', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.share']);

    $pathA = "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf";
    $pathB = "employee-documents/{$company->id}/{$employee->id}/passport/b.pdf";

    $docA = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, $pathA, 'Passport.pdf');
    $docB = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, $pathB, 'Visa.pdf');

    $response = $this->postJson(route('organization.documents.employee.files.share-links', $employee), [
        'document_ids' => [$docA->id, $docB->id],
    ]);

    $response->assertOk()
        ->assertJsonCount(2, 'documents')
        ->assertJsonPath('documents.0.id', $docA->id)
        ->assertJsonPath('documents.0.name', 'Passport.pdf')
        ->assertJsonPath('documents.1.id', $docB->id)
        ->assertJsonPath('documents.1.name', 'Visa.pdf');

    $shareUrl = $response->json('share_url');

    expect($shareUrl)->toBeString()
        ->and($shareUrl)->toContain('signature=')
        ->and($shareUrl)->toContain('expires=')
        ->and($shareUrl)->toContain('/documents/shared/')
        ->and($shareUrl)->not->toContain('/storage/');

    $share = DocumentShare::query()->first();
    expect($share)->not->toBeNull()
        ->and($share->scope)->toBe(DocumentShareScope::Files)
        ->and($share->employee_document_ids)->toEqual([$docA->id, $docB->id])
        ->and($share->can_download)->toBeTrue()
        ->and($share->can_upload)->toBeFalse();
});

test('users cannot request share links for employees in another company', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'visaType' => $visaType] = makeDocumentFixtures();
    ['employee' => $otherEmployee] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.share']);

    $path = "employee-documents/{$otherEmployee->company_id}/{$otherEmployee->id}/passport/a.pdf";
    $doc = createEmployeePdfDocument(
        $otherEmployee->company_id,
        $otherEmployee->id,
        $visaType->id,
        $path,
        'Other.pdf',
    );

    $this->postJson(route('organization.documents.employee.files.share-links', $otherEmployee), [
        'document_ids' => [$doc->id],
    ])->assertNotFound();
});

test('users cannot request share links for documents belonging to another employee', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $otherEmployee = Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $employee->branch_id,
        'employee_no' => 'DOC002',
        'name' => 'Other Employee',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['documents.share']);

    $otherPath = "employee-documents/{$company->id}/{$otherEmployee->id}/passport/other.pdf";
    $otherDoc = createEmployeePdfDocument($company->id, $otherEmployee->id, $passportType->id, $otherPath, 'Other.pdf');

    $this->postJson(route('organization.documents.employee.files.share-links', $employee), [
        'document_ids' => [$otherDoc->id],
    ])->assertNotFound();
});

test('legacy signed share url downloads the document file', function () {
    Storage::fake('public');

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $path = "employee-documents/{$company->id}/{$employee->id}/passport/passport.pdf";
    $document = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, $path, 'Passport Copy.pdf');

    $shareUrl = app(DocumentShareLinkService::class)->shareUrl($document);

    $this->get($shareUrl)
        ->assertOk()
        ->assertDownload('Passport_Copy.pdf');
});

test('invalid signed share url is forbidden', function () {
    Storage::fake('public');

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $path = "employee-documents/{$company->id}/{$employee->id}/passport/passport.pdf";
    $document = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, $path, 'Passport.pdf');

    $shareUrl = URL::temporarySignedRoute(
        'organization.documents.share',
        now()->addHours(24),
        ['document' => $document->id],
    );

    $this->get($shareUrl.'&signature=invalid')
        ->assertForbidden();
});

test('expired signed share url is forbidden', function () {
    Storage::fake('public');

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $path = "employee-documents/{$company->id}/{$employee->id}/passport/passport.pdf";
    $document = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, $path, 'Passport.pdf');

    $shareUrl = URL::temporarySignedRoute(
        'organization.documents.share',
        now()->subMinute(),
        ['document' => $document->id],
    );

    $this->get($shareUrl)->assertForbidden();
});

test('share links request rejects documents with missing files', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.share']);

    $document = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => "employee-documents/{$company->id}/{$employee->id}/passport/missing.pdf",
        'original_filename' => 'Missing.pdf',
        'mime_type' => 'application/pdf',
        'status' => 'valid',
    ]);

    $this->postJson(route('organization.documents.employee.files.share-links', $employee), [
        'document_ids' => [$document->id],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['document_ids']);
});

test('users can request share links with password and custom expiration', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.share']);

    $path = "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf";
    $doc = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, $path, 'Passport.pdf');

    $expiryDate = now()->addDays(5);

    $response = $this->postJson(route('organization.documents.employee.files.share-links', $employee), [
        'document_ids' => [$doc->id],
        'password' => 'secret123',
        'expires_at' => $expiryDate->toDateTimeString(),
    ])->assertOk();

    $shareUrl = $response->json('share_url');
    expect($shareUrl)->toBeString()
        ->and($shareUrl)->toContain('expires=')
        ->and($shareUrl)->not->toContain('pwd_hash=');

    $share = DocumentShare::query()->first();
    expect($share)->not->toBeNull()
        ->and($share->hasPassword())->toBeTrue()
        ->and($share->expires_at->timestamp)->toBe($expiryDate->timestamp);
});

test('password protected legacy share link requires password input', function () {
    Storage::fake('public');

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $path = "employee-documents/{$company->id}/{$employee->id}/passport/passport.pdf";
    $document = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, $path, 'Passport Copy.pdf');

    $shareUrl = app(DocumentShareLinkService::class)->shareUrl($document, 'secret-password');

    $this->get($shareUrl)
        ->assertOk()
        ->assertViewIs('documents.share-password')
        ->assertSee('This link is password protected')
        ->assertSee('Passport Copy.pdf');

    $this->post($shareUrl, ['password' => 'wrong-pass'])
        ->assertOk()
        ->assertViewIs('documents.share-password')
        ->assertSee('Incorrect password. Please try again.');

    $this->post($shareUrl, ['password' => 'secret-password'])
        ->assertOk()
        ->assertDownload('Passport_Copy.pdf');
});

test('users can create folder share links with upload permission', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.share']);

    $path = "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf";
    createEmployeePdfDocument($company->id, $employee->id, $passportType->id, $path, 'Passport.pdf');

    $response = $this->postJson(route('organization.documents.folders.share-links'), [
        'employee_ids' => [$employee->id],
        'can_download' => true,
        'can_upload' => true,
    ])->assertOk();

    $response->assertJsonCount(1, 'shares')
        ->assertJsonPath('shares.0.employee_id', $employee->id)
        ->assertJsonPath('shares.0.name', $employee->name);

    $share = DocumentShare::query()->first();
    expect($share)->not->toBeNull()
        ->and($share->scope)->toBe(DocumentShareScope::Folder)
        ->and($share->employee_document_ids)->toBeNull()
        ->and($share->can_upload)->toBeTrue()
        ->and($share->can_download)->toBeTrue();
});

test('guest can view shared files portal and download when allowed', function () {
    Storage::fake('public');

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $path = "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf";
    $doc = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, $path, 'Passport.pdf');

    $shares = app(DocumentShareService::class);
    $share = $shares->createFilesShare($employee, [$doc->id], $company->id, null);
    $shareUrl = $shares->shareUrl($share);

    $this->get($shareUrl)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('shared/show')
            ->where('unlocked', true)
            ->where('can_download', true)
            ->where('can_upload', false)
            ->has('documents', 1)
            ->where('documents.0.id', $doc->id));

    $downloadUrl = $shares->downloadUrl($share, $doc);

    $this->get($downloadUrl)
        ->assertOk()
        ->assertDownload('Passport.pdf');
});

test('guest cannot download from share when download is disabled', function () {
    Storage::fake('public');

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $path = "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf";
    $doc = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, $path, 'Passport.pdf');

    $shares = app(DocumentShareService::class);
    $share = $shares->createFolderShare(
        $employee,
        $company->id,
        null,
        canDownload: false,
        canUpload: false,
    );
    $downloadUrl = $shares->downloadUrl($share, $doc);

    $this->get($downloadUrl)->assertForbidden();
});

test('guest can upload into folder share when allowed', function () {
    Storage::fake('public');

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $shares = app(DocumentShareService::class);
    $share = $shares->createFolderShare(
        $employee,
        $company->id,
        null,
        canDownload: true,
        canUpload: true,
    );

    $uploadUrl = $shares->uploadUrl($share);
    $file = UploadedFile::fake()->create('guest-upload.pdf', 100, 'application/pdf');

    $this->post($uploadUrl, [
        'document_type_id' => $passportType->id,
        'file' => $file,
    ])->assertRedirect();

    $this->assertDatabaseHas('employee_documents', [
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'original_filename' => 'guest-upload.pdf',
        'document_type_id' => $passportType->id,
    ]);
});

test('guest cannot download a document outside the share selection', function () {
    Storage::fake('public');

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $pathA = "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf";
    $pathB = "employee-documents/{$company->id}/{$employee->id}/passport/b.pdf";
    $docA = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, $pathA, 'Passport.pdf');
    $docB = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, $pathB, 'Visa.pdf');

    $shares = app(DocumentShareService::class);
    $share = $shares->createFilesShare($employee, [$docA->id], $company->id, null);
    $downloadUrl = $shares->downloadUrl($share, $docB);

    $this->get($downloadUrl)->assertNotFound();
});

test('password protected portal requires unlock before listing files', function () {
    Storage::fake('public');

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $path = "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf";
    $doc = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, $path, 'Passport.pdf');

    $shares = app(DocumentShareService::class);
    $share = $shares->createFilesShare(
        $employee,
        [$doc->id],
        $company->id,
        null,
        password: 'secret123',
    );
    $shareUrl = $shares->shareUrl($share);

    $this->get($shareUrl)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('shared/show')
            ->where('unlocked', false)
            ->where('requires_password', true)
            ->has('documents', 0));

    $this->post($shares->unlockUrl($share), ['password' => 'wrong'])
        ->assertSessionHasErrors('password');

    $this->post($shares->unlockUrl($share), ['password' => 'secret123'])
        ->assertRedirect();

    $this->get($shareUrl)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('unlocked', true)
            ->has('documents', 1));
});
