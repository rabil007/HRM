<?php

namespace App\Console\Commands;

use App\Enums\BulkDocumentSignatureRequestStatus;
use App\Models\BulkDocumentSignatureRequest;
use App\Models\EmployeeDocument;
use App\Support\BulkDocuments\BulkDocumentSignatureStorage;
use App\Support\BulkDocuments\StampSignedBulkDocumentPdf;
use App\Support\EmployeeDocuments\StoresEmployeeDocument;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class RepairSignedBulkDocumentPdfsCommand extends Command
{
    protected $signature = 'bulk-documents:repair-signed-pdfs
        {--company= : Limit to a company ID}
        {--request= : Repair a single signature request ID}
        {--dry-run : Show what would be repaired without writing files}';

    protected $description = 'Re-render signed salary declaration PDFs from stored signature images';

    public function handle(StampSignedBulkDocumentPdf $stamper, StoresEmployeeDocument $store): int
    {
        $query = BulkDocumentSignatureRequest::query()
            ->where('document_type_key', 'salary_declaration')
            ->whereIn('status', [
                BulkDocumentSignatureRequestStatus::Submitted,
                BulkDocumentSignatureRequestStatus::Approved,
            ])
            ->whereNotNull('signature_image_path')
            ->whereNotNull('signed_pdf_path');

        if ($companyId = $this->option('company')) {
            $query->where('company_id', (int) $companyId);
        }

        if ($requestId = $this->option('request')) {
            $query->whereKey((int) $requestId);
        }

        $requests = $query->with(['employee', 'employeeDocument'])->get();
        $dryRun = (bool) $this->option('dry-run');
        $repaired = 0;

        foreach ($requests as $request) {
            $employeeName = (string) ($request->employee?->name ?? 'unknown');

            if ($dryRun) {
                $this->line("Would repair request #{$request->id} ({$employeeName})");

                continue;
            }

            $signatureDataUrl = $this->signatureDataUrl((string) $request->signature_image_path);

            if ($signatureDataUrl === null) {
                $this->warn("Skipping request #{$request->id}: signature image missing.");

                continue;
            }

            $signedDate = $request->signed_at?->format('d M Y') ?? now()->format('d M Y');

            $pdf = $stamper->handle($request, [
                'signed_name' => (string) ($request->signed_name ?? $employeeName),
                'signature_data' => $signatureDataUrl,
                'consent' => true,
            ], $signedDate);

            $newPath = sprintf(
                'bulk-document-signatures/%d/%d/signed-%s.pdf',
                $request->company_id,
                $request->employee_id,
                Str::uuid(),
            );

            BulkDocumentSignatureStorage::put($newPath, $pdf);

            $request->update(['signed_pdf_path' => $newPath]);

            if ($request->status === BulkDocumentSignatureRequestStatus::Approved) {
                $this->replaceApprovedEmployeeDocument($request, $store);
            }

            $this->info("Repaired request #{$request->id} ({$employeeName})");
            $repaired++;
        }

        $this->info($dryRun
            ? "Dry run complete. {$requests->count()} request(s) matched."
            : "Repaired {$repaired} signed PDF(s).");

        return self::SUCCESS;
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

    private function replaceApprovedEmployeeDocument(
        BulkDocumentSignatureRequest $request,
        StoresEmployeeDocument $store,
    ): void {
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

        $store->replace(
            $document,
            $uploadedFile,
            $request->company_id,
            $request->employee_id,
            (int) ($request->reviewed_by ?? 0),
        );
    }
}
