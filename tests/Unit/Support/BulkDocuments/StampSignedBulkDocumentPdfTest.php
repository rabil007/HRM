<?php

use App\Models\BulkDocumentSignatureRequest;
use App\Models\Company;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Services\BulkDocuments\RendersEmployeeDocumentPdf;
use App\Services\SalaryDeclaration\SalaryDeclarationPdfRenderer;
use App\Support\BulkDocuments\StampSignedBulkDocumentPdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Fpdi;

beforeEach(function () {
    Storage::fake('public');
});

function createStampTestDocument(Company $company, Employee $employee): EmployeeDocument
{
    $documentType = DocumentType::query()->firstOrCreate(
        ['title' => 'Salary Declaration'],
        ['is_active' => true],
    );

    $path = "employee-documents/{$company->id}/{$employee->id}/declaration.pdf";
    Storage::disk('public')->put($path, minimalPdfBytes());

    return EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $documentType->id,
        'type' => 'other',
        'document_type' => (string) $documentType->id,
        'file_path' => $path,
        'original_filename' => 'declaration.pdf',
        'mime_type' => 'application/pdf',
        'status' => 'valid',
    ]);
}

test('stamp signed bulk document pdf stamps placements onto source document', function () {
    $fixtures = makeDocumentFixtures();
    $company = $fixtures['company'];
    $employee = $fixtures['employee'];
    $document = createStampTestDocument($company, $employee);

    $request = BulkDocumentSignatureRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'employee_document_id' => $document->id,
        'document_type_key' => 'salary_declaration',
        'token' => str_repeat('a', 48),
        'status' => 'awaiting_signature',
        'expires_at' => now()->addDays(14),
    ]);

    $renderer = new class implements RendersEmployeeDocumentPdf
    {
        public bool $called = false;

        public function render(Employee $employee, int $companyId, ?array $signature = null, bool $showPlacementGuides = false): string
        {
            $this->called = true;

            return '%PDF-1.4 signed-with-template';
        }
    };

    app()->instance(SalaryDeclarationPdfRenderer::class, $renderer);

    $signatureData = 'data:image/png;base64,'.base64_encode(minimalSignaturePngBytes());

    $output = app(StampSignedBulkDocumentPdf::class)->handle($request, [
        'signed_name' => $employee->name,
        'signature_data' => $signatureData,
        'consent' => true,
    ]);

    expect($renderer->called)->toBeFalse()
        ->and(strlen($output))->toBeGreaterThan(strlen(minimalPdfBytes()));

    $tempPath = tempnam(sys_get_temp_dir(), 'stamped_pdf_');
    expect($tempPath)->not->toBeFalse();
    file_put_contents($tempPath, $output);

    $pdf = new Fpdi;
    expect($pdf->setSourceFile($tempPath))->toBe(1);

    @unlink($tempPath);
});

test('stamp signed bulk document pdf falls back to renderer when source stamp fails', function () {
    $fixtures = makeDocumentFixtures();
    $company = $fixtures['company'];
    $employee = $fixtures['employee'];
    $document = createStampTestDocument($company, $employee);

    Storage::disk('public')->put(
        (string) $document->file_path,
        '%PDF-1.4 corrupt-source',
    );

    $request = BulkDocumentSignatureRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'employee_document_id' => $document->id,
        'document_type_key' => 'salary_declaration',
        'token' => str_repeat('b', 48),
        'status' => 'awaiting_signature',
        'expires_at' => now()->addDays(14),
    ]);

    $renderer = new class implements RendersEmployeeDocumentPdf
    {
        /** @var array{signed_name?: string, signature_image_url?: string, signed_date?: string}|null */
        public ?array $capturedSignature = null;

        public function render(Employee $employee, int $companyId, ?array $signature = null, bool $showPlacementGuides = false): string
        {
            $this->capturedSignature = $signature;

            return '%PDF-1.4 signed-with-template';
        }
    };

    app()->instance(SalaryDeclarationPdfRenderer::class, $renderer);

    $signatureData = 'data:image/png;base64,'.base64_encode(minimalSignaturePngBytes());

    $output = app(StampSignedBulkDocumentPdf::class)->handle($request, [
        'signed_name' => $employee->name,
        'signature_data' => $signatureData,
        'consent' => true,
    ]);

    expect($output)->toBe('%PDF-1.4 signed-with-template')
        ->and($renderer->capturedSignature)->toMatchArray([
            'signed_name' => $employee->name,
            'signature_image_url' => $signatureData,
        ])
        ->and($renderer->capturedSignature['signed_date'] ?? null)->not->toBeNull();
});

test('stamp signed bulk document pdf rejects invalid signature image', function () {
    $fixtures = makeDocumentFixtures();
    $company = $fixtures['company'];
    $employee = $fixtures['employee'];
    $document = createStampTestDocument($company, $employee);

    $request = BulkDocumentSignatureRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'employee_document_id' => $document->id,
        'document_type_key' => 'salary_declaration',
        'token' => str_repeat('b', 48),
        'status' => 'awaiting_signature',
        'expires_at' => now()->addDays(14),
    ]);

    app(StampSignedBulkDocumentPdf::class)->handle($request, [
        'signed_name' => $employee->name,
        'signature_data' => 'not-an-image',
        'consent' => true,
    ]);
})->throws(ValidationException::class);

function minimalSignaturePngBytes(): string
{
    return base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        true,
    ) ?: '';
}
