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
        ->expectsOutputToContain('Would move 4 file(s)')
        ->expectsOutputToContain('Already private: 0')
        ->expectsOutputToContain('Safe skipped: 0')
        ->expectsOutputToContain('Needs review: 0')
        ->expectsOutputToContain('Failed: 0');

    Storage::disk('public')->assertExists($documentPath);
    Storage::disk('local')->assertMissing($documentPath);

    $this->artisan('employee-files:migrate-to-private')
        ->assertSuccessful()
        ->expectsOutputToContain('Moved 4 file(s)')
        ->expectsOutputToContain('Safe skipped: 0')
        ->expectsOutputToContain('Needs review: 0')
        ->expectsOutputToContain('Failed: 0');

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
        ->expectsOutputToContain('Already private: 4')
        ->expectsOutputToContain('Needs review: 0')
        ->expectsOutputToContain('Failed: 0');
});

test('safe remote URL rows do not fail the migration command', function () {
    fakeEmployeeFileDisks();
    Log::spy();

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'https://files.example.test/passport.pdf',
        'original_filename' => 'passport.pdf',
        'mime_type' => 'application/pdf',
        'status' => 'valid',
    ]);

    $this->artisan('employee-files:migrate-to-private')
        ->assertSuccessful()
        ->expectsOutputToContain('Safe skipped: 1')
        ->expectsOutputToContain('remote_url=1')
        ->expectsOutputToContain('Needs review: 0')
        ->expectsOutputToContain('Failed: 0');

    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
        $encoded = (string) json_encode($context);

        return $context['reason'] === 'remote_url'
            && $context['record_type'] === 'employee_document'
            && ! str_contains($encoded, 'passport.pdf')
            && ! str_contains($encoded, 'files.example.test')
            && ! array_key_exists('path', $context)
            && ! array_key_exists('file_path', $context);
    });
});

test('invalid-prefix public rows are flagged and cause a non-zero exit without deleting the file', function () {
    fakeEmployeeFileDisks();
    Log::spy();

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $leakedPath = "documents/{$company->id}/leaked-passport.pdf";
    Storage::disk('public')->put($leakedPath, 'still-public');

    $document = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => $leakedPath,
        'original_filename' => 'leaked-passport.pdf',
        'mime_type' => 'application/pdf',
        'status' => 'valid',
    ]);

    $this->artisan('employee-files:migrate-to-private', ['--dry-run' => true])
        ->assertFailed()
        ->expectsOutputToContain('Needs review: 1')
        ->expectsOutputToContain('invalid_prefix=1')
        ->expectsOutputToContain("Needs review (invalid_prefix): employee_document #{$document->id} (company {$company->id}). Public leftover: yes.")
        ->expectsOutputToContain('Do not treat this run as complete');

    Storage::disk('public')->assertExists($leakedPath);

    $this->artisan('employee-files:migrate-to-private')
        ->assertFailed()
        ->expectsOutputToContain('invalid_prefix');

    Storage::disk('public')->assertExists($leakedPath);
    Storage::disk('local')->assertMissing($leakedPath);

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) use ($document): bool {
        $encoded = (string) json_encode($context);

        return $context['reason'] === 'invalid_prefix'
            && $context['public_leftover'] === true
            && $context['record_id'] === $document->id
            && ! str_contains($encoded, 'leaked-passport.pdf')
            && ! array_key_exists('path', $context)
            && ! array_key_exists('file_path', $context);
    });
});

test('traversal paths are reported without checking or deleting files and without logging the path', function () {
    fakeEmployeeFileDisks();
    Log::spy();

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $document = EmployeeDocument::query()->create([
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
        ->expectsOutputToContain('Needs review: 1')
        ->expectsOutputToContain('invalid_prefix=1')
        ->expectsOutputToContain("Needs review (invalid_prefix): employee_document #{$document->id} (company {$company->id}).");

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
        $encoded = (string) json_encode($context);

        return $context['reason'] === 'invalid_prefix'
            && $context['public_leftover'] === false
            && ! str_contains($encoded, 'passwd')
            && ! str_contains($encoded, '../');
    });
});

test('missing-both-disks rows are clearly reported without failing a clean public disk', function () {
    fakeEmployeeFileDisks();
    Log::spy();

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $document = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => "employee-documents/{$company->id}/{$employee->id}/passport/missing.pdf",
        'original_filename' => 'missing.pdf',
        'mime_type' => 'application/pdf',
        'status' => 'valid',
    ]);

    $this->artisan('employee-files:migrate-to-private')
        ->assertSuccessful()
        ->expectsOutputToContain('Needs review: 1')
        ->expectsOutputToContain('missing_both_disks=1')
        ->expectsOutputToContain("Needs review (missing_both_disks): employee_document #{$document->id} (company {$company->id}).");

    Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) use ($document): bool {
        return $context['reason'] === 'missing_both_disks'
            && $context['public_leftover'] === false
            && $context['record_id'] === $document->id
            && ! array_key_exists('path', $context);
    });
});

test('orphan public files under controlled prefixes are reported and not deleted', function () {
    fakeEmployeeFileDisks();

    ['company' => $company] = makeDocumentFixtures();

    $orphan = "employee-documents/{$company->id}/orphan.pdf";
    $unrelated = "employees/{$company->id}/images/avatar.jpg";
    Storage::disk('public')->put($orphan, 'orphan-bytes');
    Storage::disk('public')->put($unrelated, 'avatar-bytes');

    $this->artisan('employee-files:migrate-to-private')
        ->assertFailed()
        ->expectsOutputToContain('Needs review: 1')
        ->expectsOutputToContain('orphan_public_file=1')
        ->expectsOutputToContain("Needs review (orphan_public_file): 1 file(s) in company {$company->id} prefix (filenames omitted). Public leftover: yes.")
        ->expectsOutputToContain('Do not treat this run as complete');

    Storage::disk('public')->assertExists($orphan);
    Storage::disk('public')->assertExists($unrelated);
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
