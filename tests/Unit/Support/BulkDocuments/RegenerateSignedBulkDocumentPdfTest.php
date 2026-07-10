<?php

use App\Enums\BulkDocumentSignatureRequestStatus;
use App\Models\BulkDocumentSignatureRequest;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\User;
use App\Services\BulkDocuments\RendersEmployeeDocumentPdf;
use App\Services\SalaryDeclaration\SalaryDeclarationPdfRenderer;
use App\Support\BulkDocuments\BulkDocumentSignatureStorage;
use App\Support\BulkDocuments\RegenerateSignedBulkDocumentPdf;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
    Storage::fake('public');
    Storage::fake('local');
});

test('regenerate signed bulk document pdf skips requests without signature image', function () {
    $user = User::factory()->create();
    $company = setupBulkDocumentsCompany($user, ['bulk_documents.signatures.review']);
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

    $signedPdfPath = "bulk-document-signatures/{$company->id}/{$employee->id}/signed.pdf";
    BulkDocumentSignatureStorage::put($signedPdfPath, '%PDF-1.4 original');

    $request = BulkDocumentSignatureRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'employee_document_id' => $document->id,
        'document_type_key' => 'salary_declaration',
        'token' => str_repeat('9', 48),
        'status' => BulkDocumentSignatureRequestStatus::Submitted,
        'signed_name' => $employee->name,
        'signed_at' => now(),
        'signature_image_path' => null,
        'signed_pdf_path' => $signedPdfPath,
        'expires_at' => now()->addDays(14),
    ]);

    $result = app(RegenerateSignedBulkDocumentPdf::class)->handle($request);

    expect($result)->toBe('skipped')
        ->and($request->fresh()->signed_pdf_path)->toBe($signedPdfPath);
});

test('regenerate signed bulk document pdf repairs via forced template render', function () {
    $user = User::factory()->create();
    $company = setupBulkDocumentsCompany($user, ['bulk_documents.signatures.review']);
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

    $signatureImagePath = "bulk-document-signatures/{$company->id}/{$employee->id}/signature.png";
    BulkDocumentSignatureStorage::put($signatureImagePath, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        true,
    ) ?: '');

    $signedPdfPath = "bulk-document-signatures/{$company->id}/{$employee->id}/signed.pdf";
    BulkDocumentSignatureStorage::put($signedPdfPath, '%PDF-1.4 original');

    $request = BulkDocumentSignatureRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'employee_document_id' => $document->id,
        'document_type_key' => 'salary_declaration',
        'token' => str_repeat('8', 48),
        'status' => BulkDocumentSignatureRequestStatus::Submitted,
        'signed_name' => $employee->name,
        'signed_at' => now()->subDay(),
        'signature_image_path' => $signatureImagePath,
        'signed_pdf_path' => $signedPdfPath,
        'expires_at' => now()->addDays(14),
    ]);

    $renderer = new class implements RendersEmployeeDocumentPdf
    {
        public function render(Employee $employee, int $companyId, ?array $signature = null, bool $showPlacementGuides = false): string
        {
            return '%PDF-1.4 unit-repaired';
        }
    };

    app()->instance(SalaryDeclarationPdfRenderer::class, $renderer);

    $result = app(RegenerateSignedBulkDocumentPdf::class)->handle($request, forceTemplateRender: true);

    expect($result)->toBe('repaired')
        ->and($request->fresh()->signed_pdf_path)->not->toBe($signedPdfPath)
        ->and(BulkDocumentSignatureStorage::disk()->get((string) $request->fresh()->signed_pdf_path))
        ->toBe('%PDF-1.4 unit-repaired');
});
