<?php

use App\Models\Course;
use App\Models\EmployeeDocument;
use App\Models\EmployeeDocumentVersion;
use App\Models\EmployeeTraining;
use App\Models\EmployeeTrainingVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

function privateEmployeeUploadPdf(string $name = 'passport.pdf'): UploadedFile
{
    return UploadedFile::fake()->create($name, 100, 'application/pdf');
}

test('new employee documents are stored on the private disk not the public disk', function () {
    fakeEmployeeFileDisks();

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.upload', 'documents.view']);

    $this->post("/organization/employees/{$employee->id}/documents", [
        'document_type_id' => $passportType->id,
        'title' => 'Private Passport',
        'file' => privateEmployeeUploadPdf(),
    ])->assertRedirect();

    $document = EmployeeDocument::query()->where('employee_id', $employee->id)->sole();

    expect($document->file_path)->toStartWith("employee-documents/{$company->id}/{$employee->id}/")
        ->and($document->file_url)->toContain('/organization/documents/files/'.$document->id.'/preview')
        ->and($document->file_url)->not->toContain('/storage/');

    Storage::disk('local')->assertExists($document->file_path);
    Storage::disk('public')->assertMissing($document->file_path);
});

test('new training certificates are stored on the private disk not the public disk', function () {
    fakeEmployeeFileDisks();

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['training.create', 'training.view']);

    $course = Course::query()->create([
        'name' => 'STCW Basic Safety',
        'is_active' => true,
    ]);

    $this->post(route('organization.employees.training.store', $employee), [
        'course_id' => $course->id,
        'issue_date' => '2024-11-26',
        'expiry_date' => '2029-11-26',
        'institute_center' => 'BINA SENA MTC',
        'certificate' => privateEmployeeUploadPdf('cert.pdf'),
    ])->assertRedirect();

    $training = EmployeeTraining::query()->where('employee_id', $employee->id)->sole();

    expect($training->certificate_path)->toStartWith("employees/{$company->id}/training-certificates/")
        ->and($training->certificate_url)->toContain('/organization/employees/'.$employee->id.'/training/'.$training->id.'/certificate')
        ->and($training->certificate_url)->not->toContain('/storage/');

    Storage::disk('local')->assertExists($training->certificate_path);
    Storage::disk('public')->assertMissing($training->certificate_path);
});

test('authorized same-company users can download employee documents and training certificates', function () {
    fakeEmployeeFileDisks();

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, [
        'documents.download',
        'documents.view',
        'training.view',
        'employees.view',
    ]);

    $documentPath = "employee-documents/{$company->id}/{$employee->id}/passport/current.pdf";
    Storage::disk('local')->put($documentPath, '%PDF-1.4 private-document');

    $document = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => $documentPath,
        'original_filename' => 'Passport Copy.pdf',
        'mime_type' => 'application/pdf',
        'status' => 'valid',
    ]);

    $training = EmployeeTraining::factory()->forEmployee($employee)->create([
        'certificate_path' => "employees/{$company->id}/training-certificates/current.pdf",
        'certificate_original_filename' => 'Certificate.pdf',
        'certificate_mime_type' => 'application/pdf',
    ]);
    Storage::disk('local')->put($training->certificate_path, '%PDF-1.4 private-certificate');

    $this->get(route('organization.documents.files.download', $document))
        ->assertOk()
        ->assertDownload('Passport_Copy.pdf');

    $this->get(route('organization.employees.training.certificate', [$employee, $training]))
        ->assertOk()
        ->assertDownload('Certificate.pdf');
});

test('users without download permission cannot download employee documents', function () {
    fakeEmployeeFileDisks();

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view']);

    $path = "employee-documents/{$company->id}/{$employee->id}/passport/secret.pdf";
    Storage::disk('local')->put($path, 'secret');

    $document = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => $path,
        'original_filename' => 'Secret.pdf',
        'mime_type' => 'application/pdf',
        'status' => 'valid',
    ]);

    $this->get(route('organization.documents.files.download', $document))
        ->assertForbidden();

    $this->get(route('organization.documents.files.preview', $document))
        ->assertOk();
});

test('users without training or employee view cannot download training certificates', function () {
    fakeEmployeeFileDisks();

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view']);

    $training = EmployeeTraining::factory()->forEmployee($employee)->create([
        'certificate_path' => "employees/{$company->id}/training-certificates/secret.pdf",
        'certificate_original_filename' => 'Secret.pdf',
        'certificate_mime_type' => 'application/pdf',
    ]);
    Storage::disk('local')->put($training->certificate_path, 'secret');

    $this->get(route('organization.employees.training.certificate', [$employee, $training]))
        ->assertForbidden();
});

test('cross-company users cannot access another company employee files', function () {
    fakeEmployeeFileDisks();

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    ['company' => $otherCompany, 'employee' => $otherEmployee] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, [
        'documents.download',
        'documents.view',
        'training.view',
        'employees.view',
    ]);

    $documentPath = "employee-documents/{$otherCompany->id}/{$otherEmployee->id}/passport/other.pdf";
    Storage::disk('local')->put($documentPath, 'other-company-secret');

    $document = EmployeeDocument::query()->create([
        'company_id' => $otherCompany->id,
        'employee_id' => $otherEmployee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => $documentPath,
        'original_filename' => 'Other.pdf',
        'mime_type' => 'application/pdf',
        'status' => 'valid',
    ]);

    $training = EmployeeTraining::factory()->forEmployee($otherEmployee)->create([
        'certificate_path' => "employees/{$otherCompany->id}/training-certificates/other.pdf",
        'certificate_original_filename' => 'Other.pdf',
        'certificate_mime_type' => 'application/pdf',
    ]);
    Storage::disk('local')->put($training->certificate_path, 'other-company-secret');

    $this->get(route('organization.documents.files.download', $document))
        ->assertNotFound();
    $this->get(route('organization.documents.files.preview', $document))
        ->assertNotFound();
    $this->get(route('organization.employees.training.certificate', [$otherEmployee, $training]))
        ->assertNotFound();
});

test('document versions remain downloadable through authorized routes', function () {
    fakeEmployeeFileDisks();

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.download', 'documents.view']);

    $currentPath = "employee-documents/{$company->id}/{$employee->id}/passport/current.pdf";
    $versionPath = "employee-documents/{$company->id}/{$employee->id}/passport/version-one.pdf";
    Storage::disk('local')->put($currentPath, 'current-bytes');
    Storage::disk('local')->put($versionPath, 'version-bytes');

    $document = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => $currentPath,
        'original_filename' => 'Current.pdf',
        'mime_type' => 'application/pdf',
        'current_version' => 2,
        'status' => 'valid',
    ]);

    $version = EmployeeDocumentVersion::query()->create([
        'employee_document_id' => $document->id,
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'version' => 1,
        'file_path' => $versionPath,
        'original_filename' => 'Version One.pdf',
        'mime_type' => 'application/pdf',
    ]);

    $this->get(route('organization.documents.files.versions.download', [
        'document' => $document,
        'version' => $version,
    ]))
        ->assertOk()
        ->assertDownload('Version_One.pdf');

    $this->get(route('organization.documents.files.versions.preview', [
        'document' => $document,
        'version' => $version,
    ]))->assertOk();

    expect($version->file_url)
        ->toContain('/organization/documents/files/'.$document->id.'/versions/'.$version->id.'/preview')
        ->and($version->file_url)->not->toContain('/storage/');
});

test('training certificate versions remain protected and downloadable with authorization', function () {
    fakeEmployeeFileDisks();

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['training.view']);

    $training = EmployeeTraining::factory()->forEmployee($employee)->create([
        'certificate_path' => "employees/{$company->id}/training-certificates/current.pdf",
        'certificate_original_filename' => 'Current.pdf',
        'certificate_mime_type' => 'application/pdf',
        'current_version' => 2,
    ]);
    Storage::disk('local')->put($training->certificate_path, 'current-cert');

    $version = EmployeeTrainingVersion::query()->create([
        'employee_training_id' => $training->id,
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'version' => 1,
        'file_path' => "employees/{$company->id}/training-certificates/version-one.pdf",
        'original_filename' => 'Version One.pdf',
        'mime_type' => 'application/pdf',
    ]);
    Storage::disk('local')->put($version->file_path, 'version-cert');

    $this->get(route('organization.employees.training.certificate.version', [
        'employee' => $employee,
        'training' => $training,
        'version' => $version,
    ]))
        ->assertOk()
        ->assertDownload('Version_One.pdf');

    $outsider = User::factory()->create();
    $this->actingAs($outsider)
        ->get(route('organization.employees.training.certificate.version', [
            'employee' => $employee,
            'training' => $training,
            'version' => $version,
        ]))
        ->assertForbidden();

    expect($version->file_url)->not->toContain('/storage/');
});

test('replacing a document keeps the previous private file as a downloadable version', function () {
    fakeEmployeeFileDisks();

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.upload', 'documents.download', 'documents.view']);

    $oldPath = "employee-documents/{$company->id}/{$employee->id}/passport/old.pdf";
    Storage::disk('local')->put($oldPath, 'old-bytes');

    $document = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => $oldPath,
        'original_filename' => 'old.pdf',
        'mime_type' => 'application/pdf',
        'current_version' => 1,
        'status' => 'valid',
    ]);

    $this->post("/organization/employees/{$employee->id}/documents/{$document->id}/replace", [
        'file' => privateEmployeeUploadPdf('new.pdf'),
    ])->assertRedirect();

    $document->refresh();
    $version = EmployeeDocumentVersion::query()->where('employee_document_id', $document->id)->sole();

    expect($document->current_version)->toBe(2)
        ->and($document->file_path)->not->toBe($oldPath)
        ->and($version->file_path)->toBe($oldPath);

    Storage::disk('local')->assertExists($oldPath);
    Storage::disk('local')->assertExists($document->file_path);
    Storage::disk('public')->assertMissing($document->file_path);

    $this->get(route('organization.documents.files.versions.download', [
        'document' => $document,
        'version' => $version,
    ]))->assertOk();
});

test('deleting a document removes current and historical private files without touching unrelated public files', function () {
    fakeEmployeeFileDisks();

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.delete']);

    $currentPath = "employee-documents/{$company->id}/{$employee->id}/passport/current.pdf";
    $versionPath = "employee-documents/{$company->id}/{$employee->id}/passport/historical.pdf";
    $unrelated = "employees/{$company->id}/images/avatar.jpg";

    Storage::disk('local')->put($currentPath, 'current');
    Storage::disk('local')->put($versionPath, 'historical');
    Storage::disk('public')->put($unrelated, 'avatar');

    $document = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => $currentPath,
        'original_filename' => 'current.pdf',
        'mime_type' => 'application/pdf',
        'status' => 'valid',
    ]);

    EmployeeDocumentVersion::query()->create([
        'employee_document_id' => $document->id,
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'version' => 1,
        'file_path' => $versionPath,
        'original_filename' => 'historical.pdf',
        'mime_type' => 'application/pdf',
    ]);

    $this->delete("/organization/employees/{$employee->id}/documents/{$document->id}")
        ->assertRedirect();

    $this->assertSoftDeleted($document);
    Storage::disk('local')->assertMissing($currentPath);
    Storage::disk('local')->assertMissing($versionPath);
    Storage::disk('public')->assertExists($unrelated);
});

test('legacy public employee files remain downloadable only through authorized controllers', function () {
    fakeEmployeeFileDisks();

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.download', 'training.view']);

    $documentPath = "employee-documents/{$company->id}/{$employee->id}/passport/legacy.pdf";
    Storage::disk('public')->put($documentPath, 'legacy-document');

    $document = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => $documentPath,
        'original_filename' => 'Legacy.pdf',
        'mime_type' => 'application/pdf',
        'status' => 'valid',
    ]);

    $training = EmployeeTraining::factory()->forEmployee($employee)->create([
        'certificate_path' => "employees/{$company->id}/training-certificates/legacy.pdf",
        'certificate_original_filename' => 'Legacy.pdf',
        'certificate_mime_type' => 'application/pdf',
    ]);
    Storage::disk('public')->put($training->certificate_path, 'legacy-certificate');

    $this->get(route('organization.documents.files.download', $document))
        ->assertOk()
        ->assertDownload('Legacy.pdf');

    $this->get(route('organization.employees.training.certificate', [$employee, $training]))
        ->assertOk()
        ->assertDownload('Legacy.pdf');

    Storage::disk('public')->assertExists($documentPath);
    Storage::disk('local')->assertMissing($documentPath);
    expect($document->file_url)->not->toContain('/storage/')
        ->and($training->certificate_url)->not->toContain('/storage/');
});

test('document show props do not expose public storage urls', function () {
    fakeEmployeeFileDisks();

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
        'file_path' => "employee-documents/{$company->id}/{$employee->id}/passport/show.pdf",
        'original_filename' => 'Show.pdf',
        'mime_type' => 'application/pdf',
        'status' => 'valid',
    ]);

    $this->get("/organization/documents/employees/{$employee->id}/files/{$document->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/show')
            ->where('document.file_url', fn ($url) => is_string($url)
                && str_contains($url, '/organization/documents/files/'.$document->id.'/preview')
                && ! str_contains($url, '/storage/'))
        );
});
