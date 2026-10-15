<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Course;
use App\Models\Currency;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeDocument;
use App\Models\EmployeeDocumentVersion;
use App\Models\EmployeeTraining;
use App\Models\EmployeeTrainingVersion;
use App\Models\User;
use App\Support\EmployeeDocuments\TrainingCertificateDocumentTypeResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

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
