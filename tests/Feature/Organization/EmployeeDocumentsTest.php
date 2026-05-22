<?php

use App\Models\EmployeeDocument;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

test('users with permission can upload a document', function () {
    Storage::fake('public');

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
});

test('upload rejects inactive or unknown document types and unsupported files', function () {
    Storage::fake('public');

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
    Storage::fake('public');

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
    Storage::fake('public');

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
    Storage::fake('public');

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
    Storage::fake('public');

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

    $this->assertDatabaseMissing('employee_documents', ['id' => $doc->id]);
});

test('users with permission can replace a document file and keep version history', function () {
    Storage::fake('public');

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
    ]);

    $this->post("/organization/employees/{$employee->id}/documents/{$doc->id}/replace", [
        'file' => UploadedFile::fake()->create('new.pdf', 100, 'application/pdf'),
    ])->assertRedirect();

    $doc->refresh();

    expect($doc->current_version)->toBe(2);
    $this->assertDatabaseHas('employee_document_versions', [
        'employee_document_id' => $doc->id,
        'version' => 1,
        'file_path' => 'employee-documents/test/old.pdf',
    ]);
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

    $this->get('/organization/documents')
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

    grantCompanyPermissions($user, $company, ['employees.view']);

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
