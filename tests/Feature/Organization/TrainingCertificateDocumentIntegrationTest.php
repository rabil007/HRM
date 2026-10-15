<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Course;
use App\Models\Currency;
use App\Models\Department;
use App\Models\DocumentInstance;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeDocument;
use App\Models\EmployeeDocumentVersion;
use App\Models\EmployeeTraining;
use App\Models\EmployeeTrainingVersion;
use App\Models\User;
use App\Support\Documents\RecipientRequests\SyncSignedDocumentInstanceToLibrary;
use App\Support\EmployeeDocuments\TrainingCertificateDocumentTypeResolver;
use App\Support\EmployeeTrainings\TrainingCertificateDocumentIntegration;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

test('training certificate upload creates linked employee document with training certificate type', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'user' => $user, 'course' => $course] = trainingCertificateIntegrationFixtures();

    $this->actingAs($user);

    $this->post(route('organization.employees.training.store', $employee), [
        'course_id' => $course->id,
        'issue_date' => '2024-11-26',
        'expiry_date' => '2029-11-26',
        'institute_center' => 'MTC',
        'certificate' => UploadedFile::fake()->create('cert.pdf', 100, 'application/pdf'),
    ])->assertRedirect();

    $training = EmployeeTraining::query()->where('employee_id', $employee->id)->sole();
    $document = EmployeeDocument::query()->findOrFail($training->employee_document_id);

    $type = app(TrainingCertificateDocumentTypeResolver::class)->resolve();

    expect($training->employee_document_id)->toBe($document->id)
        ->and($document->document_type_id)->toBe($type->id)
        ->and($document->title)->toBe($course->name)
        ->and($document->expiry_date?->toDateString())->toBe('2029-11-26')
        ->and($training->certificate_path)->toBe($document->file_path);
});

test('training upload without documents.upload permission is forbidden', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'user' => $user, 'course' => $course] = trainingCertificateIntegrationFixtures(false);

    $this->actingAs($user);

    $this->post(route('organization.employees.training.store', $employee), [
        'course_id' => $course->id,
        'issue_date' => '2024-11-26',
        'institute_center' => 'MTC',
        'certificate' => UploadedFile::fake()->create('cert.pdf', 100, 'application/pdf'),
    ])->assertForbidden();
});

test('existing training certificate document can be linked to training record', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'user' => $user, 'course' => $course] = trainingCertificateIntegrationFixtures();
    $type = app(TrainingCertificateDocumentTypeResolver::class)->resolve();

    $document = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $type->id,
        'type' => 'other',
        'document_type' => (string) $type->id,
        'title' => 'Existing Cert',
        'file_path' => 'employee-documents/'.$company->id.'/'.$employee->id.'/type-'.$type->id.'/existing.pdf',
        'original_filename' => 'existing.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 100,
        'checksum' => 'abc',
        'current_version' => 1,
        'expiry_date' => '2030-01-01',
        'status' => 'valid',
    ]);

    Storage::disk('local')->put($document->file_path, 'pdf');

    $training = EmployeeTraining::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'course_id' => $course->id,
    ]);

    $this->actingAs($user);

    $this->post(route('organization.employees.training.link-document', [$employee, $training]), [
        'employee_document_id' => $document->id,
    ])->assertRedirect();

    $training->refresh();

    expect($training->employee_document_id)->toBe($document->id)
        ->and($training->certificate_path)->toBe($document->file_path);
});

test('linked certificate replacement from training uses document version history', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'user' => $user, 'course' => $course] = trainingCertificateIntegrationFixtures();

    $this->actingAs($user);

    $this->post(route('organization.employees.training.store', $employee), [
        'course_id' => $course->id,
        'issue_date' => '2024-11-26',
        'institute_center' => 'MTC',
        'certificate' => UploadedFile::fake()->create('cert.pdf', 100, 'application/pdf'),
    ])->assertRedirect();

    $training = EmployeeTraining::query()->where('employee_id', $employee->id)->sole();
    $document = EmployeeDocument::query()->findOrFail($training->employee_document_id);
    $originalPath = $document->file_path;

    $response = $this->post(route('organization.employees.training.replace', [$employee, $training]), [
        'file' => UploadedFile::fake()->create('renewed.pdf', 120, 'application/pdf'),
        'issue_date' => '2024-11-26',
        'expiry_date' => '2030-11-26',
        'institute_center' => 'MTC',
    ]);

    $response->assertRedirect()->assertSessionHasNoErrors();

    $document->refresh();
    $training->refresh();

    expect($training->employee_document_id)->not->toBeNull()
        ->and($training->usesIntegratedCertificateDocument())->toBeTrue()
        ->and($document->current_version)->toBe(2)
        ->and(EmployeeDocumentVersion::query()->where('employee_document_id', $document->id)->count())->toBe(1)
        ->and(EmployeeTrainingVersion::query()->where('employee_training_id', $training->id)->count())->toBe(0)
        ->and($training->certificate_path)->toBe($document->file_path)
        ->and($document->file_path)->not->toBe($originalPath);
});

test('document-side replacement syncs linked training certificate mirror fields', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'user' => $user, 'course' => $course] = trainingCertificateIntegrationFixtures();

    $this->actingAs($user);

    $this->post(route('organization.employees.training.store', $employee), [
        'course_id' => $course->id,
        'issue_date' => '2024-11-26',
        'institute_center' => 'MTC',
        'certificate' => UploadedFile::fake()->create('cert.pdf', 100, 'application/pdf'),
    ])->assertRedirect();

    $training = EmployeeTraining::query()->where('employee_id', $employee->id)->sole();
    $document = EmployeeDocument::query()->findOrFail($training->employee_document_id);

    grantCompanyPermissions($user, $company, ['documents.upload']);

    $this->post(route('organization.employees.documents.replace', [$employee, $document]), [
        'file' => UploadedFile::fake()->create('from-documents.pdf', 130, 'application/pdf'),
    ])->assertRedirect();

    $training->refresh();
    $document->refresh();

    expect($training->certificate_path)->toBe($document->file_path)
        ->and($document->current_version)->toBe(2);
});

test('deleting integrated training preserves linked document files and version history', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'user' => $user, 'course' => $course] = trainingCertificateIntegrationFixtures();

    $this->actingAs($user);

    $this->post(route('organization.employees.training.store', $employee), [
        'course_id' => $course->id,
        'issue_date' => '2024-11-26',
        'institute_center' => 'MTC',
        'certificate' => UploadedFile::fake()->create('cert.pdf', 100, 'application/pdf'),
    ])->assertRedirect();

    $training = EmployeeTraining::query()->where('employee_id', $employee->id)->sole();
    $document = EmployeeDocument::query()->findOrFail($training->employee_document_id);

    $this->post(route('organization.employees.training.replace', [$employee, $training]), [
        'file' => UploadedFile::fake()->create('renewed.pdf', 120, 'application/pdf'),
        'issue_date' => '2024-11-26',
        'institute_center' => 'MTC',
    ])->assertRedirect();

    $document->refresh();
    $versionPath = EmployeeDocumentVersion::query()
        ->where('employee_document_id', $document->id)
        ->value('file_path');

    expect($versionPath)->not->toBeNull();
    Storage::disk('local')->assertExists($document->file_path);
    Storage::disk('local')->assertExists($versionPath);

    $this->delete(route('organization.employees.training.destroy', [$employee, $training]))
        ->assertRedirect();

    expect(EmployeeDocument::query()->find($document->id))->not->toBeNull()
        ->and(EmployeeDocumentVersion::query()->where('employee_document_id', $document->id)->count())->toBe(1);

    Storage::disk('local')->assertExists($document->file_path);
    Storage::disk('local')->assertExists($versionPath);
});

test('bulk deleting integrated trainings preserves all linked documents and files', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'user' => $user, 'course' => $course] = trainingCertificateIntegrationFixtures();

    $this->actingAs($user);

    foreach (['one.pdf', 'two.pdf'] as $filename) {
        $this->post(route('organization.employees.training.store', $employee), [
            'course_id' => $course->id,
            'issue_date' => '2024-11-26',
            'institute_center' => 'MTC',
            'certificate' => UploadedFile::fake()->create($filename, 100, 'application/pdf'),
        ])->assertRedirect();
    }

    $trainings = EmployeeTraining::query()->where('employee_id', $employee->id)->get();
    $documentIds = $trainings->pluck('employee_document_id')->all();
    $paths = EmployeeDocument::query()->whereIn('id', $documentIds)->pluck('file_path')->all();

    $this->post(route('organization.employees.training.bulk-destroy', $employee), [
        'training_ids' => $trainings->pluck('id')->all(),
    ])->assertRedirect();

    foreach ($documentIds as $documentId) {
        expect(EmployeeDocument::query()->find($documentId))->not->toBeNull();
    }

    foreach ($paths as $path) {
        Storage::disk('local')->assertExists($path);
    }
});

test('deleting legacy training removes private certificate files when not document-owned', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'user' => $user, 'course' => $course] = trainingCertificateIntegrationFixtures();

    $path = "employees/{$company->id}/training-certificates/legacy-only.pdf";
    Storage::disk('local')->put($path, 'legacy-only');

    $training = EmployeeTraining::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'course_id' => $course->id,
        'certificate_path' => $path,
        'certificate_original_filename' => 'legacy-only.pdf',
        'certificate_mime_type' => 'application/pdf',
        'certificate_size_bytes' => 10,
        'certificate_checksum' => 'checksum',
    ]);

    $this->actingAs($user);

    $this->delete(route('organization.employees.training.destroy', [$employee, $training]))
        ->assertRedirect();

    Storage::disk('local')->assertMissing($path);
});

test('deleting training preserves linked employee document', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'user' => $user, 'course' => $course] = trainingCertificateIntegrationFixtures();

    $this->actingAs($user);

    $this->post(route('organization.employees.training.store', $employee), [
        'course_id' => $course->id,
        'issue_date' => '2024-11-26',
        'institute_center' => 'MTC',
        'certificate' => UploadedFile::fake()->create('cert.pdf', 100, 'application/pdf'),
    ])->assertRedirect();

    $training = EmployeeTraining::query()->where('employee_id', $employee->id)->sole();
    $documentId = $training->employee_document_id;

    $this->delete(route('organization.employees.training.destroy', [$employee, $training]))
        ->assertRedirect();

    expect(EmployeeDocument::query()->find($documentId))->not->toBeNull();
});

test('deleting linked employee document is blocked', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'user' => $user, 'course' => $course] = trainingCertificateIntegrationFixtures();

    $this->actingAs($user);

    $this->post(route('organization.employees.training.store', $employee), [
        'course_id' => $course->id,
        'issue_date' => '2024-11-26',
        'institute_center' => 'MTC',
        'certificate' => UploadedFile::fake()->create('cert.pdf', 100, 'application/pdf'),
    ])->assertRedirect();

    $training = EmployeeTraining::query()->where('employee_id', $employee->id)->sole();
    $document = EmployeeDocument::query()->findOrFail($training->employee_document_id);

    grantCompanyPermissions($user, $company, ['documents.delete']);

    $this->delete(route('organization.employees.documents.destroy', [$employee, $document]))
        ->assertStatus(422);
});

test('linking document imports legacy training certificate versions when paths overlap', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'user' => $user, 'course' => $course] = trainingCertificateIntegrationFixtures();
    $type = app(TrainingCertificateDocumentTypeResolver::class)->resolve();

    $currentPath = "employees/{$company->id}/training-certificates/current.pdf";
    $versionPath = "employees/{$company->id}/training-certificates/v1.pdf";
    Storage::disk('local')->put($currentPath, 'current');
    Storage::disk('local')->put($versionPath, 'version-one');

    $document = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $type->id,
        'type' => 'other',
        'document_type' => (string) $type->id,
        'title' => 'Shared Cert',
        'file_path' => $currentPath,
        'original_filename' => 'current.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 100,
        'checksum' => 'current-checksum',
        'current_version' => 2,
        'status' => 'valid',
    ]);

    $training = EmployeeTraining::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'course_id' => $course->id,
        'certificate_path' => $currentPath,
        'certificate_original_filename' => 'current.pdf',
        'certificate_mime_type' => 'application/pdf',
        'certificate_size_bytes' => 100,
        'certificate_checksum' => 'current-checksum',
        'current_version' => 2,
    ]);

    EmployeeTrainingVersion::query()->create([
        'employee_training_id' => $training->id,
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'version' => 1,
        'file_path' => $versionPath,
        'original_filename' => 'v1.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 50,
        'checksum' => 'v1-checksum',
    ]);

    $this->actingAs($user);

    $this->post(route('organization.employees.training.link-document', [$employee, $training]), [
        'employee_document_id' => $document->id,
    ])->assertRedirect();

    expect(EmployeeDocumentVersion::query()
        ->where('employee_document_id', $document->id)
        ->where('version', 1)
        ->where('file_path', $versionPath)
        ->exists())->toBeTrue();
});

test('linking rejects matching version number with different certificate files', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'user' => $user, 'course' => $course] = trainingCertificateIntegrationFixtures();
    $type = app(TrainingCertificateDocumentTypeResolver::class)->resolve();

    $currentPath = "employees/{$company->id}/training-certificates/current.pdf";
    $trainingVersionOnePath = "employees/{$company->id}/training-certificates/training-v1.pdf";
    $documentVersionOnePath = "employees/{$company->id}/training-certificates/document-v1.pdf";
    Storage::disk('local')->put($currentPath, 'current');
    Storage::disk('local')->put($trainingVersionOnePath, 'training-v1');
    Storage::disk('local')->put($documentVersionOnePath, 'document-v1');

    $document = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $type->id,
        'type' => 'other',
        'document_type' => (string) $type->id,
        'title' => 'Shared Current',
        'file_path' => $currentPath,
        'original_filename' => 'current.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 100,
        'checksum' => 'current-checksum',
        'current_version' => 2,
        'status' => 'valid',
    ]);

    EmployeeDocumentVersion::query()->create([
        'employee_document_id' => $document->id,
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'version' => 1,
        'file_path' => $documentVersionOnePath,
        'original_filename' => 'document-v1.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 50,
        'checksum' => 'document-v1-checksum',
    ]);

    $training = EmployeeTraining::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'course_id' => $course->id,
        'certificate_path' => $currentPath,
        'certificate_original_filename' => 'current.pdf',
        'certificate_mime_type' => 'application/pdf',
        'certificate_size_bytes' => 100,
        'certificate_checksum' => 'current-checksum',
        'current_version' => 2,
        'employee_document_id' => null,
    ]);

    EmployeeTrainingVersion::query()->create([
        'employee_training_id' => $training->id,
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'version' => 1,
        'file_path' => $trainingVersionOnePath,
        'original_filename' => 'training-v1.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 50,
        'checksum' => 'training-v1-checksum',
    ]);

    $documentVersionCount = EmployeeDocumentVersion::query()->where('employee_document_id', $document->id)->count();

    $this->actingAs($user);

    $this->post(route('organization.employees.training.link-document', [$employee, $training]), [
        'employee_document_id' => $document->id,
    ])->assertSessionHasErrors('employee_document_id');

    $training->refresh();
    $document->refresh();

    expect($training->employee_document_id)->toBeNull()
        ->and($document->file_path)->toBe($currentPath)
        ->and(EmployeeDocumentVersion::query()->where('employee_document_id', $document->id)->count())->toBe($documentVersionCount);
});

test('linking matching version attachments succeeds without duplicating history', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'user' => $user, 'course' => $course] = trainingCertificateIntegrationFixtures();
    $type = app(TrainingCertificateDocumentTypeResolver::class)->resolve();

    $currentPath = "employees/{$company->id}/training-certificates/current.pdf";
    $sharedVersionOnePath = "employees/{$company->id}/training-certificates/shared-v1.pdf";
    Storage::disk('local')->put($currentPath, 'current');
    Storage::disk('local')->put($sharedVersionOnePath, 'shared-v1');

    $document = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $type->id,
        'type' => 'other',
        'document_type' => (string) $type->id,
        'title' => 'Shared Cert',
        'file_path' => $currentPath,
        'original_filename' => 'current.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 100,
        'checksum' => 'current-checksum',
        'current_version' => 2,
        'status' => 'valid',
    ]);

    EmployeeDocumentVersion::query()->create([
        'employee_document_id' => $document->id,
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'version' => 1,
        'file_path' => $sharedVersionOnePath,
        'original_filename' => 'shared-v1.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 50,
        'checksum' => 'shared-v1-checksum',
    ]);

    $training = EmployeeTraining::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'course_id' => $course->id,
        'certificate_path' => $currentPath,
        'certificate_original_filename' => 'current.pdf',
        'certificate_mime_type' => 'application/pdf',
        'certificate_size_bytes' => 100,
        'certificate_checksum' => 'current-checksum',
        'current_version' => 2,
    ]);

    EmployeeTrainingVersion::query()->create([
        'employee_training_id' => $training->id,
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'version' => 1,
        'file_path' => $sharedVersionOnePath,
        'original_filename' => 'shared-v1.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 50,
        'checksum' => 'shared-v1-checksum',
    ]);

    $this->actingAs($user);

    $this->post(route('organization.employees.training.link-document', [$employee, $training]), [
        'employee_document_id' => $document->id,
    ])->assertRedirect();

    expect(EmployeeDocumentVersion::query()->where('employee_document_id', $document->id)->count())->toBe(1);

    $this->post(route('organization.employees.training.link-document', [$employee, $training->refresh()]), [
        'employee_document_id' => $document->id,
    ])->assertRedirect();

    expect(EmployeeDocumentVersion::query()->where('employee_document_id', $document->id)->count())->toBe(1);
});

test('linked compatible certificate versions remain downloadable', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'user' => $user, 'course' => $course] = trainingCertificateIntegrationFixtures();
    grantCompanyPermissions($user, $company, [
        'employees.view',
        'training.view',
        'training.create',
        'training.update',
        'training.delete',
        'documents.upload',
        'documents.view',
        'documents.download',
    ]);
    $type = app(TrainingCertificateDocumentTypeResolver::class)->resolve();

    $currentPath = "employees/{$company->id}/training-certificates/current.pdf";
    $versionPath = "employees/{$company->id}/training-certificates/v1.pdf";
    Storage::disk('local')->put($currentPath, 'current');
    Storage::disk('local')->put($versionPath, 'version-one');

    $document = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $type->id,
        'type' => 'other',
        'document_type' => (string) $type->id,
        'title' => 'Shared Cert',
        'file_path' => $currentPath,
        'original_filename' => 'current.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 100,
        'checksum' => 'current-checksum',
        'current_version' => 2,
        'status' => 'valid',
    ]);

    $training = EmployeeTraining::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'course_id' => $course->id,
        'certificate_path' => $currentPath,
        'certificate_original_filename' => 'current.pdf',
        'certificate_mime_type' => 'application/pdf',
        'certificate_size_bytes' => 100,
        'certificate_checksum' => 'current-checksum',
        'current_version' => 2,
    ]);

    EmployeeTrainingVersion::query()->create([
        'employee_training_id' => $training->id,
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'version' => 1,
        'file_path' => $versionPath,
        'original_filename' => 'v1.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 50,
        'checksum' => 'v1-checksum',
    ]);

    $this->actingAs($user);

    $this->post(route('organization.employees.training.link-document', [$employee, $training]), [
        'employee_document_id' => $document->id,
    ])->assertRedirect();

    $version = EmployeeDocumentVersion::query()
        ->where('employee_document_id', $document->id)
        ->where('version', 1)
        ->firstOrFail();

    $this->get(route('organization.documents.files.download', $document))
        ->assertOk();

    $this->get(route('organization.documents.files.versions.download', [
        'document' => $document->id,
        'version' => $version->id,
    ]))->assertOk();
});

test('backfill reports conflicting version history when an existing document shares the current file', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'course' => $course] = trainingCertificateIntegrationFixtures(null);
    $type = app(TrainingCertificateDocumentTypeResolver::class)->resolve();

    $currentPath = "employees/{$company->id}/training-certificates/current.pdf";
    $trainingVersionOnePath = "employees/{$company->id}/training-certificates/training-v1.pdf";
    $documentVersionOnePath = "employees/{$company->id}/training-certificates/document-v1.pdf";
    Storage::disk('local')->put($currentPath, 'current');
    Storage::disk('local')->put($trainingVersionOnePath, 'training-v1');
    Storage::disk('local')->put($documentVersionOnePath, 'document-v1');

    $existingDocument = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $type->id,
        'type' => 'other',
        'document_type' => (string) $type->id,
        'title' => 'Existing',
        'file_path' => $currentPath,
        'original_filename' => 'current.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 100,
        'checksum' => 'current-checksum',
        'current_version' => 2,
        'status' => 'valid',
    ]);

    EmployeeDocumentVersion::query()->create([
        'employee_document_id' => $existingDocument->id,
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'version' => 1,
        'file_path' => $documentVersionOnePath,
        'original_filename' => 'document-v1.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 50,
        'checksum' => 'document-v1-checksum',
    ]);

    $legacyTraining = EmployeeTraining::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'course_id' => $course->id,
        'certificate_path' => $currentPath,
        'certificate_original_filename' => 'current.pdf',
        'certificate_mime_type' => 'application/pdf',
        'certificate_size_bytes' => 100,
        'certificate_checksum' => 'current-checksum',
        'current_version' => 2,
    ]);

    EmployeeTrainingVersion::query()->create([
        'employee_training_id' => $legacyTraining->id,
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'version' => 1,
        'file_path' => $trainingVersionOnePath,
        'original_filename' => 'training-v1.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 50,
        'checksum' => 'training-v1-checksum',
    ]);

    $documentCountBefore = EmployeeDocument::query()->where('employee_id', $employee->id)->count();

    Artisan::call('training:backfill-certificate-documents', [
        '--company' => $company->id,
        '--dry-run' => true,
    ]);

    expect(Artisan::output())->toContain('conflicting_version_history');

    Artisan::call('training:backfill-certificate-documents', ['--company' => $company->id]);

    expect(Artisan::output())->toContain('conflicting_version_history')
        ->and(EmployeeDocument::query()->where('employee_id', $employee->id)->count())->toBe($documentCountBefore);
});

test('linking document to another employee is forbidden', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'user' => $user, 'course' => $course] = trainingCertificateIntegrationFixtures();
    $type = app(TrainingCertificateDocumentTypeResolver::class)->resolve();

    $otherEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'OT'.random_int(1000, 9999),
        'name' => 'Other Trainee',
        'status' => 'active',
    ]);

    $otherPath = 'employee-documents/'.$company->id.'/'.$otherEmployee->id.'/type-'.$type->id.'/other-employee.pdf';
    Storage::disk('local')->put($otherPath, 'other-employee');

    $document = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $otherEmployee->id,
        'document_type_id' => $type->id,
        'type' => 'other',
        'document_type' => (string) $type->id,
        'title' => 'Other Employee Cert',
        'file_path' => $otherPath,
        'original_filename' => 'other-employee.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 100,
        'checksum' => 'other',
        'current_version' => 1,
        'status' => 'valid',
    ]);

    $training = EmployeeTraining::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'course_id' => $course->id,
    ]);

    $this->actingAs($user);

    $this->post(route('organization.employees.training.link-document', [$employee, $training]), [
        'employee_document_id' => $document->id,
    ])->assertNotFound();
});

test('linking unrelated document with legacy certificate history is rejected', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'user' => $user, 'course' => $course] = trainingCertificateIntegrationFixtures();
    $type = app(TrainingCertificateDocumentTypeResolver::class)->resolve();

    $legacyPath = "employees/{$company->id}/training-certificates/legacy.pdf";
    $otherPath = 'employee-documents/'.$company->id.'/'.$employee->id.'/type-'.$type->id.'/other.pdf';
    Storage::disk('local')->put($legacyPath, 'legacy');
    Storage::disk('local')->put($otherPath, 'other');

    $document = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $type->id,
        'type' => 'other',
        'document_type' => (string) $type->id,
        'title' => 'Other Cert',
        'file_path' => $otherPath,
        'original_filename' => 'other.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 100,
        'checksum' => 'other',
        'current_version' => 1,
        'status' => 'valid',
    ]);

    $training = EmployeeTraining::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'course_id' => $course->id,
        'certificate_path' => $legacyPath,
        'certificate_original_filename' => 'legacy.pdf',
        'certificate_mime_type' => 'application/pdf',
        'certificate_size_bytes' => 10,
        'certificate_checksum' => 'legacy',
    ]);

    $this->actingAs($user);

    $this->post(route('organization.employees.training.link-document', [$employee, $training]), [
        'employee_document_id' => $document->id,
    ])->assertSessionHasErrors('employee_document_id');
});

test('backfill dry run performs no writes', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'course' => $course] = trainingCertificateIntegrationFixtures(null);

    $path = "employees/{$company->id}/training-certificates/dry-run.pdf";
    Storage::disk('local')->put($path, 'dry-run');

    EmployeeTraining::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'course_id' => $course->id,
        'certificate_path' => $path,
        'certificate_original_filename' => 'dry-run.pdf',
        'certificate_mime_type' => 'application/pdf',
        'certificate_size_bytes' => 10,
        'certificate_checksum' => 'dry-run',
    ]);

    $documentCountBefore = EmployeeDocument::query()->count();

    Artisan::call('training:backfill-certificate-documents', [
        '--company' => $company->id,
        '--dry-run' => true,
    ]);

    expect(EmployeeDocument::query()->count())->toBe($documentCountBefore)
        ->and(Artisan::output())->toContain('Dry run complete');
});

test('backfill reports missing current file without creating documents', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'course' => $course] = trainingCertificateIntegrationFixtures(null);

    $path = "employees/{$company->id}/training-certificates/missing.pdf";

    EmployeeTraining::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'course_id' => $course->id,
        'certificate_path' => $path,
        'certificate_original_filename' => 'missing.pdf',
        'certificate_mime_type' => 'application/pdf',
        'certificate_size_bytes' => 10,
        'certificate_checksum' => 'missing',
    ]);

    Artisan::call('training:backfill-certificate-documents', ['--company' => $company->id]);

    expect(Artisan::output())->toContain('missing_current_file')
        ->and(EmployeeDocument::query()->where('employee_id', $employee->id)->count())->toBe(0);
});

test('backfill rejects invalid cross tenant certificate paths', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'course' => $course] = trainingCertificateIntegrationFixtures(null);

    $otherCompany = Company::query()->create([
        'name' => 'Other Co',
        'slug' => 'other-co-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => Country::query()->first()->id,
        'currency_id' => Currency::query()->first()->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $path = "employees/{$otherCompany->id}/training-certificates/cross-tenant.pdf";
    Storage::disk('local')->put($path, 'cross');

    EmployeeTraining::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'course_id' => $course->id,
        'certificate_path' => $path,
        'certificate_original_filename' => 'cross-tenant.pdf',
        'certificate_mime_type' => 'application/pdf',
        'certificate_size_bytes' => 10,
        'certificate_checksum' => 'cross',
    ]);

    Artisan::call('training:backfill-certificate-documents', ['--company' => $company->id]);

    expect(Artisan::output())->toContain('invalid_file_path')
        ->and(EmployeeDocument::query()->where('employee_id', $employee->id)->count())->toBe(0);
});

test('backfilled training certificate documents expose preview metadata on the documents show page', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'user' => $user, 'course' => $course] = trainingCertificateIntegrationFixtures();

    $path = "employees/{$company->id}/training-certificates/hse-certificate.pdf";
    Storage::disk('local')->put($path, '%PDF-1.4 backfilled');

    EmployeeTraining::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'course_id' => $course->id,
        'certificate_path' => $path,
        'certificate_original_filename' => 'hse-certificate.pdf',
        'certificate_mime_type' => null,
        'certificate_size_bytes' => 100,
        'certificate_checksum' => 'legacy-checksum',
        'current_version' => 1,
    ]);

    Artisan::call('training:backfill-certificate-documents', ['--company' => $company->id]);

    $document = EmployeeDocument::query()->where('employee_id', $employee->id)->sole();

    expect($document->mime_type)->toBeNull()
        ->and($document->resolvedMimeType())->toBe('application/pdf')
        ->and($document->can_preview)->toBeTrue();

    grantCompanyPermissions($user, $company, [
        'employees.view',
        'training.view',
        'training.create',
        'training.update',
        'training.delete',
        'documents.upload',
        'documents.view',
        'documents.download',
    ]);

    $this->actingAs($user)
        ->get(route('organization.documents.employee.files.show', [$employee, $document]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('organization/documents/show')
            ->where('document.id', $document->id)
            ->where('document.can_preview', true)
            ->where('document.mime_type', 'application/pdf')
            ->where('document.original_filename', 'hse-certificate.pdf')
            ->where('document.file_url', route('organization.documents.files.preview', $document))
        );

    $this->get(route('organization.documents.files.preview', $document))
        ->assertOk();
});

test('legacy backfill command links training certificates idempotently', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'course' => $course] = trainingCertificateIntegrationFixtures(null);

    $path = "employees/{$company->id}/training-certificates/legacy.pdf";
    Storage::disk('local')->put($path, 'legacy');

    $training = EmployeeTraining::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'course_id' => $course->id,
        'certificate_path' => $path,
        'certificate_original_filename' => 'legacy.pdf',
        'certificate_mime_type' => 'application/pdf',
        'certificate_size_bytes' => 10,
        'certificate_checksum' => 'legacy-checksum',
        'current_version' => 1,
    ]);

    Artisan::call('training:backfill-certificate-documents', ['--company' => $company->id]);

    $training->refresh();

    expect($training->employee_document_id)->not->toBeNull();

    Artisan::call('training:backfill-certificate-documents', ['--company' => $company->id]);

    expect(EmployeeDocument::query()->where('employee_id', $employee->id)->count())->toBe(1);
});

test('updating a linked training syncs expiry onto the employee document', function () {
    fakeEmployeeFileDisks();

    ['employee' => $employee, 'user' => $user, 'course' => $course] = trainingCertificateIntegrationFixtures();

    $this->actingAs($user);

    $this->post(route('organization.employees.training.store', $employee), [
        'course_id' => $course->id,
        'issue_date' => '2024-11-26',
        'expiry_date' => '2029-11-26',
        'institute_center' => 'MTC',
        'certificate' => UploadedFile::fake()->create('cert.pdf', 100, 'application/pdf'),
    ])->assertRedirect();

    $renewal = Course::query()->create([
        'name' => 'BOSIET',
        'is_active' => true,
    ]);

    $training = EmployeeTraining::query()->where('employee_id', $employee->id)->sole();

    $this->put(route('organization.employees.training.update', [$employee, $training]), [
        'course_id' => $renewal->id,
        'issue_date' => '2020-01-01',
        'expiry_date' => '2020-06-01',
        'institute_center' => 'MTC',
    ])->assertRedirect();

    $training->refresh();
    $document = EmployeeDocument::query()->findOrFail($training->employee_document_id);

    expect($training->course_id)->toBe($renewal->id)
        ->and($training->expiry_date?->toDateString())->toBe('2020-06-01')
        ->and($document->title)->toBe('BOSIET')
        ->and($document->issue_date?->toDateString())->toBe('2020-01-01')
        ->and($document->expiry_date?->toDateString())->toBe('2020-06-01')
        ->and($document->status)->toBe('expired')
        ->and($document->file_path)->toBe($training->certificate_path);
});

test('updating a linked employee document expiry syncs the training record', function () {
    fakeEmployeeFileDisks();

    ['employee' => $employee, 'user' => $user, 'course' => $course] = trainingCertificateIntegrationFixtures();

    $this->actingAs($user);

    $this->post(route('organization.employees.training.store', $employee), [
        'course_id' => $course->id,
        'issue_date' => '2024-11-26',
        'expiry_date' => '2029-11-26',
        'institute_center' => 'MTC',
        'certificate' => UploadedFile::fake()->create('cert.pdf', 100, 'application/pdf'),
    ])->assertRedirect();

    $training = EmployeeTraining::query()->where('employee_id', $employee->id)->sole();
    $document = EmployeeDocument::query()->findOrFail($training->employee_document_id);
    $certificatePath = $document->file_path;

    $this->put(route('organization.employees.documents.update', [$employee, $document]), [
        'document_type_id' => $document->document_type_id,
        'title' => 'Renamed in documents',
        'issue_date' => '2021-02-01',
        'expiry_date' => '2021-08-01',
    ])->assertRedirect();

    $training->refresh();
    $document->refresh();

    expect($document->status)->toBe('expired')
        ->and($document->title)->toBe('Renamed in documents')
        ->and($document->file_path)->toBe($certificatePath)
        ->and($training->course_id)->toBe($course->id)
        ->and($training->issue_date?->toDateString())->toBe('2021-02-01')
        ->and($training->expiry_date?->toDateString())->toBe('2021-08-01')
        ->and($training->certificate_path)->toBe($certificatePath);
});

test('linking a document already used by another training is rejected', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'user' => $user, 'course' => $course] = trainingCertificateIntegrationFixtures();
    $type = app(TrainingCertificateDocumentTypeResolver::class)->resolve();

    $document = makeTrainingCertificateDocument($company, $employee, $type->id, [
        'title' => 'Shared Cert',
        'expiry_date' => '2030-01-01',
    ]);

    $owner = EmployeeTraining::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'course_id' => $course->id,
        'employee_document_id' => $document->id,
    ]);

    $other = EmployeeTraining::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'course_id' => $course->id,
    ]);

    $this->actingAs($user);

    $this->post(route('organization.employees.training.link-document', [$employee, $other]), [
        'employee_document_id' => $document->id,
    ])->assertSessionHasErrors('employee_document_id');

    expect($other->refresh()->employee_document_id)->toBeNull()
        ->and($owner->refresh()->employee_document_id)->toBe($document->id);
});

test('replacing a linked certificate document requires confirmation', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'user' => $user, 'course' => $course] = trainingCertificateIntegrationFixtures();
    $type = app(TrainingCertificateDocumentTypeResolver::class)->resolve();

    $current = makeTrainingCertificateDocument($company, $employee, $type->id, [
        'title' => 'Current Cert',
        'file_path' => 'employee-documents/'.$company->id.'/'.$employee->id.'/type-'.$type->id.'/current.pdf',
        'original_filename' => 'current.pdf',
        'expiry_date' => '2030-01-01',
    ]);
    $replacement = makeTrainingCertificateDocument($company, $employee, $type->id, [
        'title' => 'Replacement Cert',
        'file_path' => 'employee-documents/'.$company->id.'/'.$employee->id.'/type-'.$type->id.'/replacement.pdf',
        'original_filename' => 'replacement.pdf',
        'expiry_date' => '2031-02-02',
    ]);

    $training = EmployeeTraining::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'course_id' => $course->id,
        'employee_document_id' => $current->id,
        'certificate_path' => null,
        'expiry_date' => '2030-01-01',
    ]);

    $this->actingAs($user);

    $this->post(route('organization.employees.training.link-document', [$employee, $training]), [
        'employee_document_id' => $replacement->id,
    ])->assertSessionHasErrors('confirm_replace_link');

    expect($training->refresh()->employee_document_id)->toBe($current->id);

    $this->post(route('organization.employees.training.link-document', [$employee, $training]), [
        'employee_document_id' => $replacement->id,
        'confirm_replace_link' => true,
    ])->assertRedirect()
        ->assertSessionHasNoErrors();

    $training->refresh();

    expect($training->employee_document_id)->toBe($replacement->id)
        ->and($training->certificate_path)->toBe($replacement->file_path)
        ->and($training->expiry_date?->toDateString())->toBe('2031-02-02');
});

test('confirming a link replacement still rejects a different certificate file', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'user' => $user, 'course' => $course] = trainingCertificateIntegrationFixtures();
    $type = app(TrainingCertificateDocumentTypeResolver::class)->resolve();

    $current = makeTrainingCertificateDocument($company, $employee, $type->id, [
        'title' => 'Current Cert',
        'file_path' => 'employee-documents/'.$company->id.'/'.$employee->id.'/type-'.$type->id.'/current.pdf',
        'original_filename' => 'current.pdf',
    ]);
    $replacement = makeTrainingCertificateDocument($company, $employee, $type->id, [
        'title' => 'Replacement Cert',
        'file_path' => 'employee-documents/'.$company->id.'/'.$employee->id.'/type-'.$type->id.'/replacement.pdf',
        'original_filename' => 'replacement.pdf',
    ]);

    $training = EmployeeTraining::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'course_id' => $course->id,
        'employee_document_id' => $current->id,
        'certificate_path' => $current->file_path,
        'certificate_original_filename' => 'current.pdf',
    ]);

    $this->actingAs($user);

    $this->post(route('organization.employees.training.link-document', [$employee, $training]), [
        'employee_document_id' => $replacement->id,
        'confirm_replace_link' => true,
    ])->assertSessionHasErrors('employee_document_id');

    expect($training->refresh()->employee_document_id)->toBe($current->id)
        ->and($training->certificate_path)->toBe($current->file_path);
});

test('linking a document that is not a training certificate is rejected', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'user' => $user, 'course' => $course] = trainingCertificateIntegrationFixtures();
    app(TrainingCertificateDocumentTypeResolver::class)->resolve();

    $passportType = DocumentType::query()->create([
        'title' => 'Passport',
        'is_active' => true,
    ]);

    $document = makeTrainingCertificateDocument($company, $employee, $passportType->id, [
        'title' => 'Passport scan',
    ]);

    $training = EmployeeTraining::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'course_id' => $course->id,
    ]);

    $this->actingAs($user);

    $this->post(route('organization.employees.training.link-document', [$employee, $training]), [
        'employee_document_id' => $document->id,
    ])->assertStatus(422);

    expect($training->refresh()->employee_document_id)->toBeNull();
});

test('training certificate search stays on this employee and does not return file paths', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'user' => $user] = trainingCertificateIntegrationFixtures();
    $type = app(TrainingCertificateDocumentTypeResolver::class)->resolve();

    $passportType = DocumentType::query()->create([
        'title' => 'Passport',
        'is_active' => true,
    ]);

    $coworker = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'TC-COWORKER',
        'name' => 'Other Trainee',
        'status' => 'active',
    ]);

    $otherCompany = Company::query()->create([
        'name' => 'Other Cert Co',
        'slug' => 'other-cert-co-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $company->country_id,
        'currency_id' => $company->currency_id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $foreignEmployee = Employee::factory()->forCompany($otherCompany)->create([
        'employee_no' => 'TC-FOREIGN',
        'name' => 'Foreign Trainee',
        'status' => 'active',
    ]);

    $secretPath = 'employee-documents/'.$company->id.'/'.$employee->id.'/type-'.$type->id.'/secret-huet.pdf';

    $match = makeTrainingCertificateDocument($company, $employee, $type->id, [
        'title' => 'HUET Renewal',
        'original_filename' => 'huet-renewal.pdf',
        'file_path' => $secretPath,
        'expiry_date' => '2030-05-01',
        'created_at' => now()->subMinute(),
    ]);

    makeTrainingCertificateDocument($company, $employee, $type->id, [
        'title' => 'Medical',
        'original_filename' => 'medical.pdf',
        'file_path' => '',
        'created_at' => now()->subMinutes(2),
    ]);

    $deleted = makeTrainingCertificateDocument($company, $employee, $type->id, [
        'title' => 'Deleted HUET',
        'original_filename' => 'deleted-huet.pdf',
    ]);
    $deleted->delete();

    makeTrainingCertificateDocument($company, $employee, $passportType->id, [
        'title' => 'HUET Passport',
        'original_filename' => 'huet-passport.pdf',
    ]);

    makeTrainingCertificateDocument($company, $coworker, $type->id, [
        'title' => 'Coworker HUET',
        'original_filename' => 'coworker-huet.pdf',
    ]);

    makeTrainingCertificateDocument($otherCompany, $foreignEmployee, $type->id, [
        'title' => 'Foreign HUET',
        'original_filename' => 'foreign-huet.pdf',
        'file_path' => 'employee-documents/'.$otherCompany->id.'/'.$foreignEmployee->id.'/type-'.$type->id.'/foreign.pdf',
    ]);

    $this->actingAs($user);

    $filtered = $this->getJson(route('organization.employees.training.documents.search', $employee).'?q=HUET')
        ->assertOk()
        ->json('documents');

    expect($filtered)->toHaveCount(1)
        ->and($filtered[0]['id'])->toBe($match->id)
        ->and($filtered[0]['title'])->toBe('HUET Renewal')
        ->and($filtered[0]['original_filename'])->toBe('huet-renewal.pdf')
        ->and($filtered[0]['expiry_date'])->toBe('2030-05-01')
        ->and($filtered[0]['has_file'])->toBeTrue()
        ->and(array_keys($filtered[0]))->toBe(['id', 'title', 'original_filename', 'expiry_date', 'has_file'])
        ->and(json_encode($filtered))->not->toContain($secretPath);

    $listed = $this->getJson(route('organization.employees.training.documents.search', $employee))
        ->assertOk()
        ->json('documents');

    expect(collect($listed)->pluck('title')->all())->toBe(['HUET Renewal', 'Medical'])
        ->and(collect($listed)->firstWhere('title', 'Medical')['has_file'])->toBeFalse();

    $this->getJson(route('organization.employees.training.documents.search', $foreignEmployee))
        ->assertForbidden()
        ->assertDontSee('Foreign HUET');
});

test('training certificate search requires documents upload as well as training update', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee] = trainingCertificateIntegrationFixtures(null);

    $withoutUpload = User::factory()->create();
    grantCompanyPermissions($withoutUpload, $company, [
        'employees.view',
        'training.view',
        'training.update',
    ]);

    $withoutTrainingUpdate = User::factory()->create();
    grantCompanyPermissions($withoutTrainingUpdate, $company, [
        'employees.view',
        'documents.upload',
        'documents.view',
    ]);

    $this->actingAs($withoutUpload)
        ->getJson(route('organization.employees.training.documents.search', $employee))
        ->assertForbidden();

    $this->actingAs($withoutTrainingUpdate)
        ->getJson(route('organization.employees.training.documents.search', $employee))
        ->assertForbidden();
});

test('training certificate search hides employees outside the user departments', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'user' => $user] = trainingCertificateIntegrationFixtures();
    $type = app(TrainingCertificateDocumentTypeResolver::class)->resolve();

    $deck = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Deck',
        'code' => 'DCK',
        'status' => 'active',
    ]);
    $engine = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Engine',
        'code' => 'ENG',
        'status' => 'active',
    ]);

    $employee->update(['department_id' => $deck->id]);

    $hidden = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'TC-ENGINE',
        'name' => 'Hidden Engineer',
        'status' => 'active',
        'department_id' => $engine->id,
    ]);

    makeTrainingCertificateDocument($company, $employee, $type->id, [
        'title' => 'Visible HUET',
    ]);
    makeTrainingCertificateDocument($company, $hidden, $type->id, [
        'title' => 'Secret HUET',
    ]);

    restrictUserToDepartments($user, $company, [$deck->id]);

    $this->actingAs($user);

    $visible = $this->getJson(route('organization.employees.training.documents.search', $employee))
        ->assertOk()
        ->json('documents');

    expect(collect($visible)->pluck('title')->all())->toBe(['Visible HUET']);

    $this->getJson(route('organization.employees.training.documents.search', $hidden))
        ->assertNotFound()
        ->assertDontSee('Secret HUET');
});

test('backfill links only the first training when another row shares the certificate path', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'course' => $course] = trainingCertificateIntegrationFixtures(null);

    $otherEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'TC-SHARE',
        'name' => 'Shared Path Trainee',
        'status' => 'active',
    ]);

    $path = "employees/{$company->id}/training-certificates/shared-legacy.pdf";
    Storage::disk('local')->put($path, 'shared-legacy');

    $first = EmployeeTraining::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'course_id' => $course->id,
        'certificate_path' => $path,
        'certificate_original_filename' => 'shared-legacy.pdf',
        'certificate_mime_type' => 'application/pdf',
        'certificate_size_bytes' => 10,
        'certificate_checksum' => 'shared-legacy',
        'issue_date' => '2024-01-01',
        'expiry_date' => '2024-06-01',
        'current_version' => 1,
    ]);

    $second = EmployeeTraining::factory()->forEmployee($otherEmployee)->create([
        'company_id' => $company->id,
        'course_id' => $course->id,
        'certificate_path' => $path,
        'certificate_original_filename' => 'shared-legacy.pdf',
        'certificate_mime_type' => 'application/pdf',
        'certificate_size_bytes' => 10,
        'certificate_checksum' => 'shared-legacy',
        'current_version' => 1,
    ]);

    Artisan::call('training:backfill-certificate-documents', ['--company' => $company->id]);

    $first->refresh();
    $second->refresh();

    expect($first->employee_document_id)->not->toBeNull()
        ->and($second->employee_document_id)->toBeNull()
        ->and(Artisan::output())->toContain('duplicate_conflicting_reference')
        ->and(EmployeeDocument::query()->where('file_path', $path)->count())->toBe(1);

    $document = EmployeeDocument::query()->findOrFail($first->employee_document_id);

    expect($document->employee_id)->toBe($employee->id)
        ->and($document->company_id)->toBe($company->id)
        ->and($document->expiry_date?->toDateString())->toBe('2024-06-01');
});

test('backfill does not create a document when a historical certificate file is missing', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'course' => $course] = trainingCertificateIntegrationFixtures(null);

    $currentPath = "employees/{$company->id}/training-certificates/current-with-gap.pdf";
    $missingPath = "employees/{$company->id}/training-certificates/missing-v1.pdf";
    Storage::disk('local')->put($currentPath, 'current');

    $training = EmployeeTraining::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'course_id' => $course->id,
        'certificate_path' => $currentPath,
        'certificate_original_filename' => 'current-with-gap.pdf',
        'certificate_mime_type' => 'application/pdf',
        'certificate_size_bytes' => 10,
        'certificate_checksum' => 'current-with-gap',
        'current_version' => 2,
    ]);

    EmployeeTrainingVersion::query()->create([
        'employee_training_id' => $training->id,
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'version' => 1,
        'file_path' => $missingPath,
        'original_filename' => 'missing-v1.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 10,
        'checksum' => 'missing-v1',
    ]);

    Artisan::call('training:backfill-certificate-documents', ['--company' => $company->id]);

    expect(Artisan::output())->toContain('missing_historical_version')
        ->and($training->refresh()->employee_document_id)->toBeNull()
        ->and(EmployeeDocument::query()->where('employee_id', $employee->id)->count())->toBe(0);
});

test('training certificate document type resolver restores inactive and soft deleted types', function () {
    $type = DocumentType::query()->create([
        'title' => TrainingCertificateDocumentTypeResolver::TITLE,
        'is_active' => false,
    ]);

    $type->delete();

    $resolved = app(TrainingCertificateDocumentTypeResolver::class)->resolve();

    expect($resolved->id)->toBe($type->id)
        ->and($resolved->trashed())->toBeFalse()
        ->and($resolved->is_active)->toBeTrue();
});

test('training upload without documents.upload leaves no partial training row', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'user' => $user, 'course' => $course] = trainingCertificateIntegrationFixtures(false);

    $this->actingAs($user);

    $this->post(route('organization.employees.training.store', $employee), [
        'course_id' => $course->id,
        'issue_date' => '2024-11-26',
        'institute_center' => 'MTC',
        'certificate' => UploadedFile::fake()->create('cert.pdf', 100, 'application/pdf'),
    ])->assertForbidden();

    expect(EmployeeTraining::query()->where('employee_id', $employee->id)->count())->toBe(0)
        ->and(EmployeeDocument::query()->where('employee_id', $employee->id)->count())->toBe(0);
});

test('bulk training upload failure in a later row leaves no partial records', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'user' => $user, 'course' => $course] = trainingCertificateIntegrationFixtures();

    $this->actingAs($user);

    $integration = Mockery::mock(TrainingCertificateDocumentIntegration::class);
    $integration->shouldReceive('attachUploadedCertificate')
        ->once()
        ->andThrow(new RuntimeException('Simulated storage failure'));
    app()->instance(TrainingCertificateDocumentIntegration::class, $integration);

    $this->post(route('organization.employees.training.bulk-store', $employee), [
        'trainings' => [
            [
                'course_id' => $course->id,
                'issue_date' => '2024-11-26',
                'institute_center' => 'MTC',
            ],
            [
                'course_id' => $course->id,
                'issue_date' => '2024-12-01',
                'institute_center' => 'MTC',
                'certificate' => UploadedFile::fake()->create('cert.pdf', 100, 'application/pdf'),
            ],
        ],
    ])->assertStatus(500);

    expect(EmployeeTraining::query()->where('employee_id', $employee->id)->count())->toBe(0)
        ->and(EmployeeDocument::query()->where('employee_id', $employee->id)->count())->toBe(0);
});

test('compatible backfill reuses an existing training certificate document instead of creating a duplicate owner', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'course' => $course] = trainingCertificateIntegrationFixtures(null);
    $type = app(TrainingCertificateDocumentTypeResolver::class)->resolve();

    $path = "employees/{$company->id}/training-certificates/shared-cert.pdf";
    Storage::disk('local')->put($path, 'shared-pdf');

    $existing = makeTrainingCertificateDocument($company, $employee, $type->id, [
        'file_path' => $path,
        'original_filename' => 'shared-cert.pdf',
        'checksum' => 'shared-sum',
        'title' => 'Existing HUET',
        'expiry_date' => '2030-01-01',
        'status' => 'valid',
    ]);

    $training = EmployeeTraining::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'course_id' => $course->id,
        'certificate_path' => $path,
        'certificate_original_filename' => 'shared-cert.pdf',
        'certificate_mime_type' => 'application/pdf',
        'certificate_size_bytes' => 10,
        'certificate_checksum' => 'shared-sum',
        'current_version' => 1,
        'expiry_date' => '2029-11-26',
    ]);

    Artisan::call('training:backfill-certificate-documents', [
        '--company' => $company->id,
        '--dry-run' => true,
    ]);

    expect(Artisan::output())->toContain('would_reuse')
        ->and($training->refresh()->employee_document_id)->toBeNull()
        ->and(EmployeeDocument::query()->where('employee_id', $employee->id)->count())->toBe(1);

    Artisan::call('training:backfill-certificate-documents', ['--company' => $company->id]);

    expect(Artisan::output())->toContain('reused')
        ->and($training->refresh()->employee_document_id)->toBe($existing->id)
        ->and(EmployeeDocument::query()->where('file_path', $path)->count())->toBe(1);
});

test('conflicting backfill with mismatched document type makes no writes', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'course' => $course] = trainingCertificateIntegrationFixtures(null);

    $passportType = DocumentType::query()->create([
        'title' => 'Passport',
        'is_active' => true,
    ]);

    $path = "employees/{$company->id}/training-certificates/conflict-cert.pdf";
    Storage::disk('local')->put($path, 'conflict-pdf');

    makeTrainingCertificateDocument($company, $employee, $passportType->id, [
        'file_path' => $path,
        'title' => 'Passport Copy',
    ]);

    $training = EmployeeTraining::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'course_id' => $course->id,
        'certificate_path' => $path,
        'certificate_original_filename' => 'conflict-cert.pdf',
        'certificate_mime_type' => 'application/pdf',
        'certificate_size_bytes' => 10,
        'certificate_checksum' => 'conflict-sum',
        'current_version' => 1,
    ]);

    Artisan::call('training:backfill-certificate-documents', ['--company' => $company->id]);

    expect(Artisan::output())->toContain('duplicate_conflicting_reference')
        ->and($training->refresh()->employee_document_id)->toBeNull()
        ->and(EmployeeDocument::query()->where('employee_id', $employee->id)->count())->toBe(1);
});

test('deleting a duplicate document owner cannot destroy an active shared certificate file', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'user' => $user, 'course' => $course] = trainingCertificateIntegrationFixtures();
    grantCompanyPermissions($user, $company, ['documents.delete']);
    $type = app(TrainingCertificateDocumentTypeResolver::class)->resolve();

    $path = "employee-documents/{$company->id}/{$employee->id}/type-{$type->id}/shared.pdf";
    Storage::disk('local')->put($path, 'shared-bytes');

    $active = makeTrainingCertificateDocument($company, $employee, $type->id, [
        'file_path' => $path,
        'title' => 'Active Cert',
    ]);

    $duplicate = makeTrainingCertificateDocument($company, $employee, $type->id, [
        'file_path' => $path,
        'title' => 'Duplicate Owner',
    ]);

    $training = EmployeeTraining::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'course_id' => $course->id,
        'employee_document_id' => $active->id,
        'certificate_path' => $path,
    ]);

    $this->actingAs($user);

    $this->delete(route('organization.employees.documents.destroy', [$employee, $duplicate]))
        ->assertRedirect();

    expect(Storage::disk('local')->exists($path))->toBeTrue()
        ->and($training->refresh()->certificate_path)->toBe($path)
        ->and(EmployeeDocument::query()->whereKey($active->id)->exists())->toBeTrue()
        ->and(EmployeeDocument::withTrashed()->find($duplicate->id)?->trashed())->toBeTrue();
});

test('linked training certificate cannot be reclassified to an unrelated document type', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'user' => $user, 'course' => $course] = trainingCertificateIntegrationFixtures();

    $this->actingAs($user);

    $this->post(route('organization.employees.training.store', $employee), [
        'course_id' => $course->id,
        'issue_date' => '2024-11-26',
        'institute_center' => 'MTC',
        'certificate' => UploadedFile::fake()->create('cert.pdf', 100, 'application/pdf'),
    ])->assertRedirect();

    $training = EmployeeTraining::query()->where('employee_id', $employee->id)->sole();
    $document = EmployeeDocument::query()->findOrFail($training->employee_document_id);
    $passportType = DocumentType::query()->create([
        'title' => 'Passport',
        'is_active' => true,
    ]);

    $this->put(route('organization.employees.documents.update', [$employee, $document]), [
        'document_type_id' => $passportType->id,
        'title' => $document->title,
        'issue_date' => $document->issue_date?->toDateString(),
        'expiry_date' => $document->expiry_date?->toDateString(),
    ])->assertSessionHasErrors('document_type_id');

    expect($document->refresh()->document_type_id)->toBe(
        app(TrainingCertificateDocumentTypeResolver::class)->resolve()->id
    );
});

test('first certificate attachment on an existing training uses integrated document storage', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'user' => $user, 'course' => $course] = trainingCertificateIntegrationFixtures();

    $training = EmployeeTraining::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'course_id' => $course->id,
        'certificate_path' => null,
        'employee_document_id' => null,
    ]);

    $this->actingAs($user);

    $this->post(route('organization.employees.training.replace', [$employee, $training]), [
        'file' => UploadedFile::fake()->create('first-cert.pdf', 100, 'application/pdf'),
        'issue_date' => '2024-11-26',
        'expiry_date' => '2029-11-26',
        'institute_center' => 'MTC',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $training->refresh();
    $document = EmployeeDocument::query()->findOrFail($training->employee_document_id);

    expect($training->usesIntegratedCertificateDocument())->toBeTrue()
        ->and($training->certificate_path)->toBe($document->file_path)
        ->and($document->title)->toBe($course->name);
});

test('legacy certificate replacement integrates history before replacing through documents', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'user' => $user, 'course' => $course] = trainingCertificateIntegrationFixtures();

    $legacyPath = "employees/{$company->id}/training-certificates/legacy.pdf";
    Storage::disk('local')->put($legacyPath, 'legacy-bytes');

    $training = EmployeeTraining::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'course_id' => $course->id,
        'certificate_path' => $legacyPath,
        'certificate_original_filename' => 'legacy.pdf',
        'certificate_mime_type' => 'application/pdf',
        'certificate_size_bytes' => 12,
        'certificate_checksum' => 'legacy-sum',
        'current_version' => 1,
        'employee_document_id' => null,
    ]);

    $this->actingAs($user);

    $this->post(route('organization.employees.training.replace', [$employee, $training]), [
        'file' => UploadedFile::fake()->create('renewed.pdf', 120, 'application/pdf'),
        'issue_date' => '2025-01-01',
        'expiry_date' => '2030-01-01',
        'institute_center' => 'MTC',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $training->refresh();
    $document = EmployeeDocument::query()->findOrFail($training->employee_document_id);

    expect($training->usesIntegratedCertificateDocument())->toBeTrue()
        ->and($document->current_version)->toBe(2)
        ->and(EmployeeDocumentVersion::query()->where('employee_document_id', $document->id)->count())->toBe(1)
        ->and(EmployeeDocumentVersion::query()->where('employee_document_id', $document->id)->value('file_path'))->toBe($legacyPath)
        ->and($training->certificate_path)->toBe($document->file_path)
        ->and($training->certificate_path)->not->toBe($legacyPath);
});

test('signing a linked training certificate document syncs training downloads and preserves history', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'course' => $course] = trainingCertificateIntegrationFixtures(null);
    $type = app(TrainingCertificateDocumentTypeResolver::class)->resolve();

    $oldPath = "employee-documents/{$company->id}/{$employee->id}/type-{$type->id}/unsigned.pdf";
    Storage::disk('local')->put($oldPath, 'unsigned-pdf');

    $document = makeTrainingCertificateDocument($company, $employee, $type->id, [
        'file_path' => $oldPath,
        'original_filename' => 'unsigned.pdf',
        'checksum' => 'unsigned-sum',
        'current_version' => 1,
        'title' => $course->name,
    ]);

    $training = EmployeeTraining::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'course_id' => $course->id,
        'employee_document_id' => $document->id,
        'certificate_path' => $oldPath,
        'certificate_checksum' => 'unsigned-sum',
        'current_version' => 1,
    ]);

    $tempSigned = tempnam(sys_get_temp_dir(), 'signed_');
    file_put_contents($tempSigned, 'signed-pdf-bytes');

    $instance = new DocumentInstance([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'employee_document_id' => $document->id,
        'title_snapshot' => $course->name,
        'status' => 'generated',
    ]);

    $syncLibrary = app(SyncSignedDocumentInstanceToLibrary::class);
    $replacement = DB::transaction(
        fn () => $syncLibrary->prepareReplacement($instance, $tempSigned, $company->id),
    );

    expect($replacement)->not->toBeNull();

    $syncLibrary->finalizeReplacement($replacement);

    $document->refresh();
    $training->refresh();

    expect($training->certificate_path)->toBe($document->file_path)
        ->and($training->certificate_path)->not->toBe($oldPath)
        ->and($document->current_version)->toBe(2)
        ->and(EmployeeDocumentVersion::query()->where('employee_document_id', $document->id)->value('file_path'))->toBe($oldPath)
        ->and(Storage::disk('local')->exists($oldPath))->toBeTrue()
        ->and(Storage::disk('local')->exists($document->file_path))->toBeTrue();

    @unlink($tempSigned);
});

test('crew training resync updates linked document issue date and title without clearing expiry', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'course' => $course] = trainingCertificateIntegrationFixtures(null);
    $type = app(TrainingCertificateDocumentTypeResolver::class)->resolve();

    $document = makeTrainingCertificateDocument($company, $employee, $type->id, [
        'title' => 'Old Course Title',
        'issue_date' => '2024-03-01',
        'expiry_date' => '2029-03-01',
        'status' => 'valid',
    ]);

    $training = EmployeeTraining::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'course_id' => $course->id,
        'issue_date' => '2024-03-01',
        'expiry_date' => '2029-03-01',
        'employee_document_id' => $document->id,
        'certificate_path' => $document->file_path,
    ]);

    $training->update([
        'issue_date' => '2024-03-06',
        'course_id' => $course->id,
    ]);

    app(TrainingCertificateDocumentIntegration::class)
        ->syncTrainingDatesToLinkedDocument($training->refresh(), syncExpiry: false);

    $document->refresh();

    expect($document->issue_date?->toDateString())->toBe('2024-03-06')
        ->and($document->expiry_date?->toDateString())->toBe('2029-03-01')
        ->and($document->title)->toBe($course->name);
});

test('linking the same document to two trainings is rejected under lock', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'user' => $user, 'course' => $course] = trainingCertificateIntegrationFixtures();
    $type = app(TrainingCertificateDocumentTypeResolver::class)->resolve();

    $document = makeTrainingCertificateDocument($company, $employee, $type->id, [
        'title' => 'Shared Link Target',
    ]);
    Storage::disk('local')->put($document->file_path, 'pdf');

    $first = EmployeeTraining::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'course_id' => $course->id,
    ]);
    $second = EmployeeTraining::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'course_id' => $course->id,
    ]);

    $this->actingAs($user);

    $this->post(route('organization.employees.training.link-document', [$employee, $first]), [
        'employee_document_id' => $document->id,
    ])->assertRedirect();

    $this->post(route('organization.employees.training.link-document', [$employee, $second]), [
        'employee_document_id' => $document->id,
    ])->assertSessionHasErrors('employee_document_id');

    expect($first->refresh()->employee_document_id)->toBe($document->id)
        ->and($second->refresh()->employee_document_id)->toBeNull();
});

test('documents manager can update linked certificate metadata without training.update', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'course' => $course] = trainingCertificateIntegrationFixtures(null);
    $type = app(TrainingCertificateDocumentTypeResolver::class)->resolve();

    $documentsManager = User::factory()->create();
    grantCompanyPermissions($documentsManager, $company, [
        'employees.view',
        'documents.view',
        'documents.upload',
    ]);

    $document = makeTrainingCertificateDocument($company, $employee, $type->id, [
        'title' => $course->name,
        'issue_date' => '2024-11-26',
        'expiry_date' => '2029-11-26',
        'status' => 'valid',
    ]);
    Storage::disk('local')->put($document->file_path, 'pdf');

    $training = EmployeeTraining::factory()->forEmployee($employee)->create([
        'company_id' => $company->id,
        'course_id' => $course->id,
        'employee_document_id' => $document->id,
        'certificate_path' => $document->file_path,
        'issue_date' => '2024-11-26',
        'expiry_date' => '2029-11-26',
    ]);

    $this->actingAs($documentsManager);

    $this->put(route('organization.employees.documents.update', [$employee, $document]), [
        'document_type_id' => $type->id,
        'title' => 'Updated Certificate Title',
        'issue_date' => '2024-12-01',
        'expiry_date' => '2030-12-01',
        'notes' => 'Updated by documents manager',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($document->refresh()->title)->toBe('Updated Certificate Title')
        ->and($document->expiry_date?->toDateString())->toBe('2030-12-01')
        ->and($training->refresh()->issue_date?->toDateString())->toBe('2024-12-01')
        ->and($training->expiry_date?->toDateString())->toBe('2030-12-01');
});

test('two training certificates for the same employee remain independently eligible for expiry alerts', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'user' => $user, 'course' => $course] = trainingCertificateIntegrationFixtures();
    $secondCourse = Course::query()->create([
        'name' => 'BOSIET',
        'is_active' => true,
    ]);
    $type = app(TrainingCertificateDocumentTypeResolver::class)->resolve();

    $this->actingAs($user);

    $this->post(route('organization.employees.training.store', $employee), [
        'course_id' => $course->id,
        'issue_date' => '2024-01-01',
        'expiry_date' => now()->addDays(5)->toDateString(),
        'institute_center' => 'MTC',
        'certificate' => UploadedFile::fake()->create('huet.pdf', 100, 'application/pdf'),
    ])->assertRedirect();

    $this->post(route('organization.employees.training.store', $employee), [
        'course_id' => $secondCourse->id,
        'issue_date' => '2024-02-01',
        'expiry_date' => now()->addDays(5)->toDateString(),
        'institute_center' => 'MTC',
        'certificate' => UploadedFile::fake()->create('bosiet.pdf', 100, 'application/pdf'),
    ])->assertRedirect();

    $documents = EmployeeDocument::query()
        ->where('employee_id', $employee->id)
        ->where('document_type_id', $type->id)
        ->get();

    expect($documents)->toHaveCount(2)
        ->and($documents->pluck('expiry_date')->map->toDateString()->unique()->count())->toBe(1)
        ->and($documents->pluck('id')->unique()->count())->toBe(2);
});

/**
 * @return array{
 *     company: Company,
 *     employee: Employee,
 *     user: User|null,
 *     course: Course
 * }
 */
function trainingCertificateIntegrationFixtures(?bool $withDocumentUpload = true): array
{
    $country = Country::query()->create([
        'code' => 'TCI',
        'name' => 'Training Cert Integration',
        'dial_code' => '+993',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TCI',
        'name' => 'TCI Currency',
        'symbol' => 'C$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Training Cert Co',
        'slug' => 'training-cert-co-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $employee = Employee::factory()
        ->forCompany($company)
        ->create([
            'employee_no' => 'TC'.random_int(1000, 9999),
            'name' => 'Cert Trainee',
            'status' => 'active',
        ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'start_date' => '2026-01-01',
        'end_date' => null,
        'labor_contract_id' => null,
        'status' => 'active',
    ]);

    $course = Course::query()->create([
        'name' => 'HUET',
        'is_active' => true,
    ]);

    $user = null;

    if ($withDocumentUpload !== false) {
        $user = User::factory()->create();
        grantCompanyPermissions($user, $company, [
            'employees.view',
            'training.view',
            'training.create',
            'training.update',
            'training.delete',
            'documents.upload',
            'documents.view',
        ]);
    } elseif ($withDocumentUpload === false) {
        $user = User::factory()->create();
        grantCompanyPermissions($user, $company, [
            'employees.view',
            'training.view',
            'training.create',
            'training.update',
            'training.delete',
        ]);
    }

    return [
        'company' => $company,
        'employee' => $employee,
        'user' => $user,
        'course' => $course,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function makeTrainingCertificateDocument(Company $company, Employee $employee, int $typeId, array $overrides = []): EmployeeDocument
{
    return EmployeeDocument::query()->create(array_merge([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $typeId,
        'type' => 'other',
        'document_type' => (string) $typeId,
        'title' => 'Certificate',
        'file_path' => 'employee-documents/'.$company->id.'/'.$employee->id.'/type-'.$typeId.'/file.pdf',
        'original_filename' => 'file.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 10,
        'checksum' => 'sum',
        'current_version' => 1,
        'status' => 'valid',
    ], $overrides));
}
