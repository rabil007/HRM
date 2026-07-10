<?php

namespace App\Support\BulkDocuments;

use App\Enums\BulkDocumentSignatureRequestStatus;
use App\Models\BulkDocumentSignatureRequest;
use App\Models\EmployeeDocument;
use App\Support\EmployeeDocuments\StoresEmployeeDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Throwable;

final class RegenerateSignedBulkDocumentPdf
{
    public function __construct(
        private StampSignedBulkDocumentPdf $stamper,
        private StoresEmployeeDocument $store,
    ) {}

    /**
     * @return 'repaired'|'skipped'|'failed'
     */
    public function handle(BulkDocumentSignatureRequest $request, bool $forceTemplateRender = true): string
    {
        $request->loadMissing(['employee', 'employeeDocument']);

        $signaturePath = (string) ($request->signature_image_path ?? '');

        if ($signaturePath === '') {
            return 'skipped';
        }

        $signatureDataUrl = $this->signatureDataUrl($signaturePath);

        if ($signatureDataUrl === null) {
            return 'skipped';
        }

        $employeeName = (string) ($request->employee?->name ?? 'unknown');
        $signedDate = $request->signed_at?->format('d M Y') ?? now()->format('d M Y');

        try {
            $pdf = $this->stamper->handle($request, [
                'signed_name' => (string) ($request->signed_name ?? $employeeName),
                'signature_data' => $signatureDataUrl,
                'consent' => true,
            ], $signedDate, $forceTemplateRender);
        } catch (Throwable $exception) {
            report($exception);

            return 'failed';
        }

        $newPath = sprintf(
            'bulk-document-signatures/%d/%d/signed-%s.pdf',
            $request->company_id,
            $request->employee_id,
            Str::uuid(),
        );

        BulkDocumentSignatureStorage::put($newPath, $pdf);

        $request->update(['signed_pdf_path' => $newPath]);

        if ($request->status === BulkDocumentSignatureRequestStatus::Approved) {
            $this->replaceApprovedEmployeeDocument($request);
        }

        return 'repaired';
    }

    private function signatureDataUrl(string $path): ?string
    {
        if ($path === '' || ! BulkDocumentSignatureStorage::exists($path)) {
            return null;
        }

        $binary = BulkDocumentSignatureStorage::disk()->get($path);

        if (! is_string($binary) || $binary === '') {
            return null;
        }

        $mime = str_ends_with(strtolower($path), '.jpg') || str_ends_with(strtolower($path), '.jpeg')
            ? 'jpeg'
            : 'png';

        return 'data:image/'.$mime.';base64,'.base64_encode($binary);
    }

    private function replaceApprovedEmployeeDocument(BulkDocumentSignatureRequest $request): void
    {
        $signedPath = (string) $request->signed_pdf_path;

        if ($signedPath === '' || ! BulkDocumentSignatureStorage::exists($signedPath)) {
            return;
        }

        $document = EmployeeDocument::query()->find($request->employee_document_id);

        if ($document === null) {
            return;
        }

        $absolutePath = BulkDocumentSignatureStorage::path($signedPath);
        $uploadedFile = new UploadedFile(
            $absolutePath,
            'signed-salary-declaration.pdf',
            'application/pdf',
            null,
            true,
        );

        $this->store->replace(
            $document,
            $uploadedFile,
            $request->company_id,
            $request->employee_id,
            (int) ($request->reviewed_by ?? 0),
        );
    }
}
