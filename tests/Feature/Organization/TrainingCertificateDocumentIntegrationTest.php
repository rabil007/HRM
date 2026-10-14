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
