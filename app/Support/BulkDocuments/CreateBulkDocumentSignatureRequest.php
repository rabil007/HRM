<?php

namespace App\Support\BulkDocuments;

use App\Enums\BulkDocumentSignatureRequestStatus;
use App\Models\BulkDocumentSignatureRequest;
use App\Models\EmployeeDocument;
use Illuminate\Support\Str;

final class CreateBulkDocumentSignatureRequest
{
    public function handle(
        int $companyId,
        int $employeeId,
        EmployeeDocument $document,
        string $documentTypeKey,
        ?int $batchId = null,
    ): BulkDocumentSignatureRequest {
        BulkDocumentSignatureRequest::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('document_type_key', $documentTypeKey)
            ->whereIn('status', [
                BulkDocumentSignatureRequestStatus::AwaitingSignature->value,
                BulkDocumentSignatureRequestStatus::Submitted->value,
            ])
            ->update(['status' => BulkDocumentSignatureRequestStatus::Cancelled->value]);

        return BulkDocumentSignatureRequest::query()->create([
            'company_id' => $companyId,
            'employee_id' => $employeeId,
            'employee_document_id' => $document->id,
            'document_type_key' => $documentTypeKey,
            'token' => Str::random(48),
            'status' => BulkDocumentSignatureRequestStatus::AwaitingSignature,
            'batch_id' => $batchId,
            'expires_at' => now()->addDays(BulkDocumentSignatureRequest::EXPIRY_DAYS),
        ]);
    }
}
