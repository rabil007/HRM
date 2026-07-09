<?php

namespace App\Support\BulkDocuments;

use App\Enums\BulkDocumentSignatureRequestStatus;
use App\Models\BulkDocumentSignatureRequest;
use App\Models\EmployeeDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class UploadManualSignedDocument
{
    public function __construct(
        private CreateBulkDocumentSignatureRequest $createRequest,
    ) {}

    public function handle(
        BulkDocumentSignatureRequest $request,
        UploadedFile $file,
        int $userId,
    ): BulkDocumentSignatureRequest {
        if (! in_array($request->status, [
            BulkDocumentSignatureRequestStatus::AwaitingSignature,
            BulkDocumentSignatureRequestStatus::Rejected,
        ], true)) {
            throw ValidationException::withMessages([
                'request' => 'This request cannot accept a manual upload in its current state.',
            ]);
        }

        if ($file->getMimeType() !== 'application/pdf') {
            throw ValidationException::withMessages([
                'file' => 'The signed document must be a PDF file.',
            ]);
        }

        $path = sprintf(
            'bulk-document-signatures/%d/%d/manual-%s.pdf',
            $request->company_id,
            $request->employee_id,
            Str::uuid(),
        );

        Storage::disk('public')->put($path, $file->get());

        $request->update([
            'status' => BulkDocumentSignatureRequestStatus::Submitted,
            'signed_name' => null,
            'signature_image_path' => null,
            'signed_pdf_path' => $path,
            'signed_at' => now(),
            'submitted_ip' => null,
            'user_agent' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'rejection_reason' => null,
        ]);

        return $request->refresh();
    }

    public function createOrRefreshForDocument(
        int $companyId,
        EmployeeDocument $document,
        string $documentTypeKey,
    ): BulkDocumentSignatureRequest {
        $existing = BulkDocumentSignatureRequest::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $document->employee_id)
            ->where('document_type_key', $documentTypeKey)
            ->whereIn('status', [
                BulkDocumentSignatureRequestStatus::AwaitingSignature,
                BulkDocumentSignatureRequestStatus::Rejected,
            ])
            ->latest('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $this->createRequest->handle(
            $companyId,
            $document->employee_id,
            $document,
            $documentTypeKey,
        );
    }
}
