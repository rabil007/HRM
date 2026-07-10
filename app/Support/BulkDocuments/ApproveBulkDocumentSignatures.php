<?php

namespace App\Support\BulkDocuments;

use App\Enums\BulkDocumentSignatureRequestStatus;
use App\Models\BulkDocumentSignatureRequest;
use Illuminate\Validation\ValidationException;

final class ApproveBulkDocumentSignatures
{
    public function __construct(
        private ReviewBulkDocumentSignature $review,
    ) {}

    /**
     * @param  list<int>  $signatureRequestIds
     * @return array{approved: int, skipped: int}
     */
    public function handle(
        int $companyId,
        int $reviewerId,
        string $documentTypeKey,
        array $signatureRequestIds,
    ): array {
        $requests = BulkDocumentSignatureRequest::query()
            ->where('company_id', $companyId)
            ->where('document_type_key', $documentTypeKey)
            ->where('status', BulkDocumentSignatureRequestStatus::Submitted)
            ->whereNotNull('signed_pdf_path')
            ->whereKey($signatureRequestIds)
            ->orderBy('id')
            ->get();

        $approved = 0;
        $skipped = max(0, count($signatureRequestIds) - $requests->count());

        foreach ($requests as $request) {
            try {
                $this->review->approve($request, $reviewerId);
                $approved++;
            } catch (ValidationException) {
                $skipped++;
            }
        }

        return [
            'approved' => $approved,
            'skipped' => $skipped,
        ];
    }
}
