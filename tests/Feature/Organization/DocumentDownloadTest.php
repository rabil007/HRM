<?php

use App\Models\EmployeeDocument;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

test('guests cannot download employee document archives', function () {
    $this->get('/organization/documents/employees/1/download')
        ->assertRedirect(route('login'));
});

test('users can download a single document file with original filename', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $path = 'employee-documents/'.$company->id.'/'.$employee->id.'/passport/report.pdf';
    Storage::disk('public')->put($path, '%PDF-1.4 sample');

    $document = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => $path,
        'original_filename' => 'Passport Copy.pdf',
        'mime_type' => 'application/pdf',
        'status' => 'valid',
    ]);

    $this->get(route('organization.documents.files.download', $document))
        ->assertOk()
        ->assertDownload('Passport_Copy.pdf');
});

test('users can download employee folder as zip with expected filename', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $employee->update([
        'name' => 'Zinat Ulla',
        'employee_no' => '1009',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $pathA = 'employee-documents/'.$company->id.'/'.$employee->id.'/passport/a.pdf';
    $pathB = 'employee-documents/'.$company->id.'/'.$employee->id.'/passport/b.pdf';
    Storage::disk('public')->put($pathA, 'file-a');
    Storage::disk('public')->put($pathB, 'file-b');

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => $pathA,
        'original_filename' => 'Visa Front.pdf',
        'mime_type' => 'application/pdf',
        'status' => 'valid',
    ]);

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => $pathB,
        'original_filename' => 'Visa Back.pdf',
        'mime_type' => 'application/pdf',
        'status' => 'valid',
    ]);

    $response = $this->get(route('organization.documents.employee.download', $employee));

    $response->assertOk()->assertDownload('ZINAT-ULLA_1009_documents.zip');

    $zipPath = tempnam(sys_get_temp_dir(), 'zip_test_');
    file_put_contents($zipPath, $response->streamedContent());

    $zip = new ZipArchive;
    expect($zip->open($zipPath))->toBeTrue();
    expect($zip->numFiles)->toBe(2);
    $zip->close();

    @unlink($zipPath);
});

test('zip download skips missing files and still returns archive', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $validPath = 'employee-documents/'.$company->id.'/'.$employee->id.'/passport/valid.pdf';
    Storage::disk('public')->put($validPath, 'valid-content');

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => $validPath,
        'original_filename' => 'Valid.pdf',
        'mime_type' => 'application/pdf',
        'status' => 'valid',
    ]);

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/'.$company->id.'/'.$employee->id.'/passport/missing.pdf',
        'original_filename' => 'Missing.pdf',
        'mime_type' => 'application/pdf',
        'status' => 'valid',
    ]);

    $response = $this->get(route('organization.documents.employee.download', $employee));

    $response->assertOk()->assertDownload();

    $zipPath = tempnam(sys_get_temp_dir(), 'zip_test_');
    file_put_contents($zipPath, $response->streamedContent());

    $zip = new ZipArchive;
    expect($zip->open($zipPath))->toBeTrue();
    expect($zip->numFiles)->toBe(1);
    $zip->close();

    @unlink($zipPath);
});

test('single document download rejects unsafe storage paths', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

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

    $this->get(route('organization.documents.files.download', $document))
        ->assertNotFound();
});

test('users cannot download documents from another company', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    ['company' => $otherCompany, 'employee' => $otherEmployee] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $path = 'employee-documents/'.$otherCompany->id.'/'.$otherEmployee->id.'/passport/other.pdf';
    Storage::disk('public')->put($path, 'secret');

    $document = EmployeeDocument::query()->create([
        'company_id' => $otherCompany->id,
        'employee_id' => $otherEmployee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => $path,
        'original_filename' => 'Other.pdf',
        'mime_type' => 'application/pdf',
        'status' => 'valid',
    ]);

    $this->get(route('organization.documents.files.download', $document))
        ->assertNotFound();

    $this->get(route('organization.documents.employee.download', $otherEmployee))
        ->assertNotFound();
});

test('legacy employee documents download route still works', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $path = 'employee-documents/'.$company->id.'/'.$employee->id.'/passport/legacy.pdf';
    Storage::disk('public')->put($path, 'legacy');

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => $path,
        'original_filename' => 'Legacy.pdf',
        'mime_type' => 'application/pdf',
        'status' => 'valid',
    ]);

    $this->get(route('organization.employees.documents.download', $employee))
        ->assertOk()
        ->assertDownload();
});
