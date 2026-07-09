<?php

namespace App\Support\BulkDocuments;

use App\Enums\BulkDocumentSignatureRequestStatus;
use App\Models\BulkDocumentSignatureRequest;

final class CancelPendingBulkDocumentSignatureRequests
{
    /**
     * @param  list<int>  $documentIds
     */
    public function forDocuments(int $companyId, array $documentIds): int
    {
        if ($documentIds === []) {
            return 0;
        }

        return BulkDocumentSignatureRequest::query()
            ->where('company_id', $companyId)
            ->whereIn('employee_document_id', $documentIds)
            ->whereIn('status', [
                BulkDocumentSignatureRequestStatus::AwaitingSignature->value,
                BulkDocumentSignatureRequestStatus::Submitted->value,
            ])
            ->update(['status' => BulkDocumentSignatureRequestStatus::Cancelled->value]);
    }
}
