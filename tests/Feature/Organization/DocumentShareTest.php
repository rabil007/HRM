<?php

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Support\EmployeeDocuments\DocumentShareLinkService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

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

test('users can request signed share links for selected documents', function () {
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

    $shareUrl = $response->json('documents.0.share_url');

    expect($shareUrl)->toBeString()
        ->and($shareUrl)->toContain('signature=')
        ->and($shareUrl)->toContain('expires=')
        ->and($shareUrl)->not->toContain('/storage/');
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

test('signed share url downloads the document file', function () {
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

    $expiryDate = now()->addDays(5)->toDateTimeString();

    $response = $this->postJson(route('organization.documents.employee.files.share-links', $employee), [
        'document_ids' => [$doc->id],
        'password' => 'secret123',
        'expires_at' => $expiryDate,
    ])->assertOk();

    $shareUrl = $response->json('documents.0.share_url');
    expect($shareUrl)->toBeString()
        ->and($shareUrl)->toContain('pwd_hash=')
        ->and($shareUrl)->toContain('expires=');
});

test('password protected share link requires password input', function () {
    Storage::fake('public');

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $path = "employee-documents/{$company->id}/{$employee->id}/passport/passport.pdf";
    $document = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, $path, 'Passport Copy.pdf');

    $shareUrl = app(DocumentShareLinkService::class)->shareUrl($document, 'secret-password');

    // GET request without password renders password prompt view
    $this->get($shareUrl)
        ->assertOk()
        ->assertViewIs('documents.share-password')
        ->assertSee('This link is password protected')
        ->assertSee('Passport Copy.pdf');

    // POST request with incorrect password shows error
    $this->post($shareUrl, ['password' => 'wrong-pass'])
        ->assertOk()
        ->assertViewIs('documents.share-password')
        ->assertSee('Incorrect password. Please try again.');

    // POST request with correct password downloads file
    $this->post($shareUrl, ['password' => 'secret-password'])
        ->assertOk()
        ->assertDownload('Passport_Copy.pdf');
});
