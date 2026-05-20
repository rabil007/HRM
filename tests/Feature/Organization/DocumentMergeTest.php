<?php

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

test('guests cannot merge employee pdfs', function () {
    $this->postJson(route('organization.documents.employee.files.merge-pdf', 1), [
        'document_ids' => [1, 2],
    ])->assertUnauthorized();
});

test('users can merge selected employee pdfs into one download', function () {
    Carbon::setTestNow('2026-05-20 12:00:00');
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $employee->update(['name' => 'Test Employee']);

    $pathA = "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf";
    $pathB = "employee-documents/{$company->id}/{$employee->id}/passport/b.pdf";

    $docA = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, $pathA, 'Visa Front.pdf');
    $docB = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, $pathB, 'Emirates ID.pdf');

    $response = $this->postJson(route('organization.documents.employee.files.merge-pdf', $employee), [
        'document_ids' => [$docA->id, $docB->id],
    ]);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
    $response->assertDownload('TEST-EMPLOYEE_DOCUMENTS_20260520.pdf');
    expect(str_starts_with($response->streamedContent(), '%PDF'))->toBeTrue();
    expect(Storage::disk('public')->allFiles())->toHaveCount(2);

    Carbon::setTestNow();
});

test('merge requires at least two document ids', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $path = "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf";
    $doc = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, $path, 'Visa Front.pdf');

    $this->postJson(route('organization.documents.employee.files.merge-pdf', $employee), [
        'document_ids' => [$doc->id],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['document_ids']);
});

test('merge rejects non pdf selections', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $pdfPath = "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf";
    $imagePath = "employee-documents/{$company->id}/{$employee->id}/passport/b.jpg";
    Storage::disk('public')->put($imagePath, 'image-bytes');

    $pdf = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, $pdfPath, 'Visa Front.pdf');

    $image = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => $imagePath,
        'original_filename' => 'Photo.jpg',
        'mime_type' => 'image/jpeg',
        'status' => 'valid',
    ]);

    $this->postJson(route('organization.documents.employee.files.merge-pdf', $employee), [
        'document_ids' => [$pdf->id, $image->id],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['document_ids']);
});

test('users cannot merge documents for employees in another company', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'passportType' => $passportType] = makeDocumentFixtures();
    ['employee' => $otherEmployee] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $pathA = "employee-documents/{$otherEmployee->company_id}/{$otherEmployee->id}/passport/a.pdf";
    $pathB = "employee-documents/{$otherEmployee->company_id}/{$otherEmployee->id}/passport/b.pdf";

    $docA = createEmployeePdfDocument($otherEmployee->company_id, $otherEmployee->id, $passportType->id, $pathA, 'A.pdf');
    $docB = createEmployeePdfDocument($otherEmployee->company_id, $otherEmployee->id, $passportType->id, $pathB, 'B.pdf');

    $this->postJson(route('organization.documents.employee.files.merge-pdf', $otherEmployee), [
        'document_ids' => [$docA->id, $docB->id],
    ])->assertNotFound();
});

test('users cannot merge documents belonging to another employee', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    $otherEmployee = Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $employee->branch_id,
        'employee_no' => 'DOC999',
        'name' => 'Other Employee',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $ownPath = "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf";
    $otherPath = "employee-documents/{$company->id}/{$otherEmployee->id}/passport/b.pdf";

    $ownDoc = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, $ownPath, 'Own.pdf');
    $otherDoc = createEmployeePdfDocument($company->id, $otherEmployee->id, $passportType->id, $otherPath, 'Other.pdf');

    $this->postJson(route('organization.documents.employee.files.merge-pdf', $employee), [
        'document_ids' => [$ownDoc->id, $otherDoc->id],
    ])->assertNotFound();
});

test('merge returns validation error for unsupported pdf compression instead of server error', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $pathA = "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf";
    $pathB = "employee-documents/{$company->id}/{$employee->id}/passport/b.pdf";

    Storage::disk('public')->put($pathA, '%PDF-1.7 unsupported-by-fpdi');
    Storage::disk('public')->put($pathB, '%PDF-1.7 unsupported-by-fpdi');

    $docA = EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => $pathA,
        'original_filename' => 'Broken A.pdf',
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
        'original_filename' => 'Broken B.pdf',
        'mime_type' => 'application/pdf',
        'status' => 'valid',
    ]);

    $this->postJson(route('organization.documents.employee.files.merge-pdf', $employee), [
        'document_ids' => [$docA->id, $docB->id],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['document_ids']);
});

test('bulk zip download still works after merge endpoint is available', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['employees.view']);

    $path = "employee-documents/{$company->id}/{$employee->id}/passport/a.pdf";
    $doc = createEmployeePdfDocument($company->id, $employee->id, $passportType->id, $path, 'Visa Front.pdf');

    $this->postJson(route('organization.documents.files.bulk-download'), [
        'document_ids' => [$doc->id],
    ])->assertOk()->assertDownload('documents_export.zip');
});
