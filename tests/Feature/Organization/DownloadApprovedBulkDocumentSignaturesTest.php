<?php

use App\Enums\BulkDocumentSignatureRequestStatus;
use App\Models\BulkDocumentSignatureRequest;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    Storage::fake('public');
    Storage::fake('local');
});

function createApprovedSignedRequest(
    $company,
    Employee $employee,
    string $tokenSuffix,
): BulkDocumentSignatureRequest {
    $documentType = DocumentType::query()->firstOrCreate(
        ['title' => 'Salary Declaration'],
        ['is_active' => true],
    );

    $document = createEmployeePdfDocument(
        $company->id,
        $employee->id,
        $documentType->id,
        "employee-documents/{$company->id}/{$employee->id}/declaration.pdf",
        'declaration.pdf',
    );

    $signedPath = "bulk-document-signatures/{$company->id}/{$employee->id}/signed-{$tokenSuffix}.pdf";
    Storage::disk('local')->put($signedPath, minimalPdfBytes());

    return BulkDocumentSignatureRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'employee_document_id' => $document->id,
        'document_type_key' => 'salary_declaration',
        'token' => 'approved-download-'.$tokenSuffix,
        'status' => BulkDocumentSignatureRequestStatus::Approved,
        'signed_name' => $employee->name,
        'signed_pdf_path' => $signedPath,
        'signed_at' => now(),
        'reviewed_at' => now(),
        'expires_at' => now()->addDays(14),
    ]);
}

test('users can download selected approved signatures as zip', function () {
    Carbon::setTestNow('2026-07-10 12:00:00');

    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['documents.download']);

    $firstEmployee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Alice Approved',
        'employee_no' => 'A001',
    ]);
    $secondEmployee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Bob Approved',
        'employee_no' => 'B002',
    ]);
    $awaitingEmployee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Carol Awaiting',
    ]);

    $first = createApprovedSignedRequest($company, $firstEmployee, 'one');
    $second = createApprovedSignedRequest($company, $secondEmployee, 'two');

    $documentType = DocumentType::query()->firstOrCreate(
        ['title' => 'Salary Declaration'],
        ['is_active' => true],
    );
    $awaitingDocument = createEmployeePdfDocument(
        $company->id,
        $awaitingEmployee->id,
        $documentType->id,
        "employee-documents/{$company->id}/{$awaitingEmployee->id}/declaration.pdf",
        'declaration.pdf',
    );
    $awaiting = BulkDocumentSignatureRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $awaitingEmployee->id,
        'employee_document_id' => $awaitingDocument->id,
        'document_type_key' => 'salary_declaration',
        'token' => 'approved-download-awaiting',
        'status' => BulkDocumentSignatureRequestStatus::AwaitingSignature,
        'expires_at' => now()->addDays(14),
    ]);

    $response = $this->postJson(route('organization.documents.bulk.signatures.download-zip'), [
        'document_type_key' => 'salary_declaration',
        'signature_request_ids' => [$first->id, $second->id, $awaiting->id],
    ]);

    $response->assertOk()->assertDownload('salary-declaration-approved-signed-2026-07-10.zip');

    $zipPath = tempnam(sys_get_temp_dir(), 'approved_zip_');
    file_put_contents($zipPath, $response->streamedContent());

    $zip = new ZipArchive;
    expect($zip->open($zipPath))->toBeTrue()
        ->and($zip->numFiles)->toBe(2);
    $zip->close();

    @unlink($zipPath);
    Carbon::setTestNow();
});

test('users can download selected approved signatures as one pdf', function () {
    Carbon::setTestNow('2026-07-10 12:00:00');

    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['documents.download']);

    $firstEmployee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Alice Approved',
    ]);
    $secondEmployee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Bob Approved',
    ]);

    $first = createApprovedSignedRequest($company, $firstEmployee, 'pdf-one');
    $second = createApprovedSignedRequest($company, $secondEmployee, 'pdf-two');

    $response = $this->postJson(route('organization.documents.bulk.signatures.download-pdf'), [
        'document_type_key' => 'salary_declaration',
        'signature_request_ids' => [$first->id, $second->id],
    ]);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
    $response->assertDownload('salary-declaration-approved-signed-20260710.pdf');
    expect(str_starts_with($response->streamedContent(), '%PDF'))->toBeTrue();

    Carbon::setTestNow();
});

test('users can download a single approved signature as pdf', function () {
    Carbon::setTestNow('2026-07-10 12:00:00');

    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['documents.download']);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Solo Approved',
    ]);
    $request = createApprovedSignedRequest($company, $employee, 'solo');

    $response = $this->postJson(route('organization.documents.bulk.signatures.download-pdf'), [
        'document_type_key' => 'salary_declaration',
        'signature_request_ids' => [$request->id],
    ]);

    $response->assertOk()->assertDownload('salary-declaration-approved-signed-20260710.pdf');
    expect(str_starts_with($response->streamedContent(), '%PDF'))->toBeTrue();

    Carbon::setTestNow();
});

test('approved signature downloads require documents download permission', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    setupBulkDocumentsCompany($user, ['bulk_documents.signatures.review']);

    $this->postJson(route('organization.documents.bulk.signatures.download-zip'), [
        'document_type_key' => 'salary_declaration',
        'signature_request_ids' => [1],
    ])->assertForbidden();

    $this->postJson(route('organization.documents.bulk.signatures.download-pdf'), [
        'document_type_key' => 'salary_declaration',
        'signature_request_ids' => [1],
    ])->assertForbidden();
});

test('approved signature downloads return not found for ineligible selection', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['documents.download']);

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $documentType = DocumentType::query()->firstOrCreate(
        ['title' => 'Salary Declaration'],
        ['is_active' => true],
    );
    $document = createEmployeePdfDocument(
        $company->id,
        $employee->id,
        $documentType->id,
        "employee-documents/{$company->id}/{$employee->id}/declaration.pdf",
        'declaration.pdf',
    );

    $request = BulkDocumentSignatureRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'employee_document_id' => $document->id,
        'document_type_key' => 'salary_declaration',
        'token' => 'approved-download-none',
        'status' => BulkDocumentSignatureRequestStatus::Submitted,
        'signed_pdf_path' => "bulk-document-signatures/{$company->id}/{$employee->id}/signed.pdf",
        'signed_at' => now(),
        'expires_at' => now()->addDays(14),
    ]);

    Storage::disk('local')->put((string) $request->signed_pdf_path, minimalPdfBytes());

    $this->postJson(route('organization.documents.bulk.signatures.download-zip'), [
        'document_type_key' => 'salary_declaration',
        'signature_request_ids' => [$request->id],
    ])->assertNotFound();

    $this->postJson(route('organization.documents.bulk.signatures.download-pdf'), [
        'document_type_key' => 'salary_declaration',
        'signature_request_ids' => [$request->id],
    ])->assertNotFound();
});
