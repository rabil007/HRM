<?php

use App\Models\BulkDocumentSignatureRequest;
use App\Models\Company;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\BulkDocuments\StampSignedBulkDocumentPdf;
use Illuminate\Support\Facades\Storage;
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

test('stamp signed bulk document pdf embeds signature on source pdf', function () {
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

    $sourceSize = strlen((string) Storage::disk('public')->get($document->file_path));
    $signatureData = 'data:image/png;base64,'.base64_encode(minimalSignaturePngBytes());

    $output = app(StampSignedBulkDocumentPdf::class)->handle($request, [
        'signed_name' => $employee->name,
        'signature_data' => $signatureData,
        'consent' => true,
    ]);

    expect(strlen($output))->toBeGreaterThan($sourceSize);

    $tempPath = tempnam(sys_get_temp_dir(), 'stamped_pdf_');
    expect($tempPath)->not->toBeFalse();

    file_put_contents($tempPath, $output);

    $pdf = new Fpdi;
    expect($pdf->setSourceFile($tempPath))->toBe(1);

    @unlink($tempPath);
});

function minimalSignaturePngBytes(): string
{
    return base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        true,
    ) ?: '';
}
