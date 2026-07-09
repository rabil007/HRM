<?php

namespace App\Support\BulkDocuments;

use App\Enums\BulkDocumentSignatureRequestStatus;
use App\Models\BulkDocumentSignatureRequest;
use App\Models\EmployeeDocument;
use App\Support\EmployeeDocuments\StoresEmployeeDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ReviewBulkDocumentSignature
{
    public function __construct(
        private StoresEmployeeDocument $store,
    ) {}

    public function approve(BulkDocumentSignatureRequest $request, int $reviewerId): BulkDocumentSignatureRequest
    {
        $this->assertReviewable($request);

        $signedPath = (string) $request->signed_pdf_path;

        if ($signedPath === '' || ! BulkDocumentSignatureStorage::exists($signedPath)) {
            throw ValidationException::withMessages([
                'request' => 'Signed document file is missing.',
            ]);
        }

        $document = EmployeeDocument::query()->findOrFail($request->employee_document_id);
        $absolutePath = BulkDocumentSignatureStorage::path($signedPath);
        $label = BulkDocumentTypeRegistry::find($request->document_type_key)['label'];
        $filename = (Str::slug($label) ?: 'signed-document').'.pdf';
        $uploadedFile = new UploadedFile(
            $absolutePath,
            $filename,
            'application/pdf',
            null,
            true,
        );

        $this->store->replace(
            $document,
            $uploadedFile,
            $request->company_id,
            $request->employee_id,
            $reviewerId,
        );

        BulkDocumentSignatureRequest::query()
            ->where('company_id', $request->company_id)
            ->where('employee_id', $request->employee_id)
            ->where('document_type_key', $request->document_type_key)
            ->where('id', '!=', $request->id)
            ->whereIn('status', [
                BulkDocumentSignatureRequestStatus::AwaitingSignature->value,
                BulkDocumentSignatureRequestStatus::Submitted->value,
            ])
            ->update(['status' => BulkDocumentSignatureRequestStatus::Cancelled->value]);

        $request->update([
            'status' => BulkDocumentSignatureRequestStatus::Approved,
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
            'rejection_reason' => null,
        ]);

        return $request->refresh();
    }

    public function reject(BulkDocumentSignatureRequest $request, int $reviewerId, string $reason): BulkDocumentSignatureRequest
    {
        $this->assertReviewable($request);

        $request->update([
            'status' => BulkDocumentSignatureRequestStatus::Rejected,
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ]);

        return $request->refresh();
    }

    private function assertReviewable(BulkDocumentSignatureRequest $request): void
    {
        if ($request->status !== BulkDocumentSignatureRequestStatus::Submitted) {
            throw ValidationException::withMessages([
                'request' => 'Only submitted signature requests can be reviewed.',
            ]);
        }
    }
}
