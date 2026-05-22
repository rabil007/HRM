<?php

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

test('guests cannot bulk download employee folders', function () {
    $this->post(route('organization.documents.folders.bulk-download'), [
        'employee_ids' => [1],
    ])->assertRedirect(route('login'));
});

test('users can bulk download multiple employee folders into one zip', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employeeA, 'passportType' => $passportType] = makeDocumentFixtures();

    $employeeB = Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $employeeA->branch_id,
        'employee_no' => 'DOC002',
        'name' => 'Second Employee',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['documents.download']);

    $pathA = 'employee-documents/'.$company->id.'/'.$employeeA->id.'/passport/a.pdf';
    $pathB = 'employee-documents/'.$company->id.'/'.$employeeB->id.'/passport/b.pdf';
    Storage::disk('public')->put($pathA, 'file-a');
    Storage::disk('public')->put($pathB, 'file-b');

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employeeA->id,
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
        'employee_id' => $employeeB->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => $pathB,
        'original_filename' => 'Emirates ID.pdf',
        'mime_type' => 'application/pdf',
        'status' => 'valid',
    ]);

    $response = $this->postJson(route('organization.documents.folders.bulk-download'), [
        'employee_ids' => [$employeeA->id, $employeeB->id],
    ]);

    $response->assertOk()->assertDownload('documents_export.zip');

    $zipPath = tempnam(sys_get_temp_dir(), 'bulk_zip_');
    file_put_contents($zipPath, $response->streamedContent());

    $zip = new ZipArchive;
    expect($zip->open($zipPath))->toBeTrue();
    expect($zip->numFiles)->toBe(2);
    $zip->close();

    @unlink($zipPath);
});

test('users can bulk download selected files as zip', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.download']);

    $pathA = 'employee-documents/'.$company->id.'/'.$employee->id.'/passport/a.pdf';
    $pathB = 'employee-documents/'.$company->id.'/'.$employee->id.'/passport/b.pdf';
    Storage::disk('public')->put($pathA, 'file-a');
    Storage::disk('public')->put($pathB, 'file-b');

    $docA = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => $pathA,
        'original_filename' => 'Passport.pdf',
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
        'original_filename' => 'Visa.pdf',
        'mime_type' => 'application/pdf',
        'status' => 'valid',
    ]);

    $response = $this->postJson(route('organization.documents.files.bulk-download'), [
        'document_ids' => [$docA->id],
    ]);

    $response->assertOk()->assertDownload('documents_export.zip');

    $zipPath = tempnam(sys_get_temp_dir(), 'bulk_files_');
    file_put_contents($zipPath, $response->streamedContent());

    $zip = new ZipArchive;
    expect($zip->open($zipPath))->toBeTrue();
    expect($zip->numFiles)->toBe(1);
    $zip->close();

    @unlink($zipPath);
});

test('users with permission can bulk delete selected employee documents', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.delete']);

    $pathA = 'employee-documents/'.$company->id.'/'.$employee->id.'/passport/a.pdf';
    $pathB = 'employee-documents/'.$company->id.'/'.$employee->id.'/passport/b.pdf';
    Storage::disk('public')->put($pathA, 'file-a');
    Storage::disk('public')->put($pathB, 'file-b');

    $docA = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => $pathA,
        'original_filename' => 'Passport.pdf',
        'mime_type' => 'application/pdf',
        'status' => 'valid',
    ]);

    $docB = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => $pathB,
        'original_filename' => 'Visa.pdf',
        'mime_type' => 'application/pdf',
        'status' => 'valid',
    ]);

    $this->from("/organization/documents/employees/{$employee->id}")
        ->delete(route('organization.documents.employee.files.bulk-destroy', $employee), [
            'document_ids' => [$docA->id],
        ])
        ->assertRedirect("/organization/documents/employees/{$employee->id}")
        ->assertSessionHas('success');

    expect(EmployeeDocument::query()->whereKey($docA->id)->exists())->toBeFalse();
    expect(EmployeeDocument::query()->whereKey($docB->id)->exists())->toBeTrue();
    Storage::disk('public')->assertMissing($pathA);
    Storage::disk('public')->assertExists($pathB);
});

test('bulk delete rejects documents from another company', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    ['company' => $otherCompany, 'employee' => $otherEmployee] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['documents.delete']);

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

    $this->delete(route('organization.documents.employee.files.bulk-destroy', $employee), [
        'document_ids' => [$document->id],
    ])->assertNotFound();

    expect(EmployeeDocument::query()->whereKey($document->id)->exists())->toBeTrue();
});

test('users without delete permission cannot bulk delete documents', function () {
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
        'file_path' => 'employee-documents/'.$company->id.'/'.$employee->id.'/passport/a.pdf',
        'original_filename' => 'Passport.pdf',
        'status' => 'valid',
    ]);

    $this->delete(route('organization.documents.employee.files.bulk-destroy', $employee), [
        'document_ids' => [$document->id],
    ])->assertForbidden();
});
