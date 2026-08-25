<?php

use App\Models\EmployeeDocument;
use App\Models\EmployeeDocumentVersion;
use App\Models\EmployeeTraining;
use App\Models\EmployeeTrainingVersion;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

test('migration command copies legacy public employee files to private storage and is idempotent', function () {
    fakeEmployeeFileDisks();

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $documentPath = "employee-documents/{$company->id}/{$employee->id}/passport/legacy.pdf";
    $versionPath = "employee-documents/{$company->id}/{$employee->id}/passport/legacy-v1.pdf";
    $trainingPath = "employees/{$company->id}/training-certificates/legacy.pdf";
    $trainingVersionPath = "employees/{$company->id}/training-certificates/legacy-v1.pdf";
    $unrelated = "employees/{$company->id}/images/avatar.jpg";

    Storage::disk('public')->put($documentPath, 'document-bytes');
    Storage::disk('public')->put($versionPath, 'version-bytes');
    Storage::disk('public')->put($trainingPath, 'training-bytes');
    Storage::disk('public')->put($trainingVersionPath, 'training-version-bytes');
    Storage::disk('public')->put($unrelated, 'avatar-bytes');

    $document = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => $documentPath,
        'original_filename' => 'legacy.pdf',
        'mime_type' => 'application/pdf',
        'status' => 'valid',
    ]);

    EmployeeDocumentVersion::query()->create([
        'employee_document_id' => $document->id,
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'version' => 1,
        'file_path' => $versionPath,
        'original_filename' => 'legacy-v1.pdf',
        'mime_type' => 'application/pdf',
    ]);

    $training = EmployeeTraining::factory()->forEmployee($employee)->create([
        'certificate_path' => $trainingPath,
        'certificate_original_filename' => 'legacy.pdf',
        'certificate_mime_type' => 'application/pdf',
    ]);

    EmployeeTrainingVersion::query()->create([
        'employee_training_id' => $training->id,
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'version' => 1,
        'file_path' => $trainingVersionPath,
        'original_filename' => 'legacy-v1.pdf',
        'mime_type' => 'application/pdf',
    ]);

    $this->artisan('employee-files:migrate-to-private', ['--dry-run' => true])
        ->assertSuccessful()
        ->expectsOutputToContain('Would move 4 file(s)');

    Storage::disk('public')->assertExists($documentPath);
    Storage::disk('local')->assertMissing($documentPath);

    $this->artisan('employee-files:migrate-to-private')
        ->assertSuccessful()
        ->expectsOutputToContain('Moved 4 file(s)');

    Storage::disk('local')->assertExists($documentPath);
    Storage::disk('local')->assertExists($versionPath);
    Storage::disk('local')->assertExists($trainingPath);
    Storage::disk('local')->assertExists($trainingVersionPath);
    Storage::disk('public')->assertMissing($documentPath);
    Storage::disk('public')->assertMissing($versionPath);
    Storage::disk('public')->assertMissing($trainingPath);
    Storage::disk('public')->assertMissing($trainingVersionPath);
    Storage::disk('public')->assertExists($unrelated);
    expect(Storage::disk('local')->get($documentPath))->toBe('document-bytes')
        ->and($document->fresh()->file_path)->toBe($documentPath)
        ->and($training->fresh()->certificate_path)->toBe($trainingPath);

    $this->artisan('employee-files:migrate-to-private')
        ->assertSuccessful()
        ->expectsOutputToContain('Already private: 4');
});

test('migration command does not log sensitive filenames and skips unsafe paths', function () {
    fakeEmployeeFileDisks();
    Log::spy();

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => '../../../etc/passwd',
        'original_filename' => 'unsafe.pdf',
        'mime_type' => 'application/pdf',
        'status' => 'valid',
    ]);

    $this->artisan('employee-files:migrate-to-private')
        ->assertSuccessful()
        ->expectsOutputToContain('Skipped:');

    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
});

test('authorized users can still download a file after the migration command moves it', function () {
    fakeEmployeeFileDisks();

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.download']);

    $path = "employee-documents/{$company->id}/{$employee->id}/passport/migrated.pdf";
    Storage::disk('public')->put($path, 'migrated-bytes');

    $document = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => $path,
        'original_filename' => 'Migrated.pdf',
        'mime_type' => 'application/pdf',
        'status' => 'valid',
    ]);

    $this->artisan('employee-files:migrate-to-private')->assertSuccessful();

    Storage::disk('local')->assertExists($path);
    Storage::disk('public')->assertMissing($path);

    $this->get(route('organization.documents.files.download', $document))
        ->assertOk()
        ->assertDownload('Migrated.pdf');
});
