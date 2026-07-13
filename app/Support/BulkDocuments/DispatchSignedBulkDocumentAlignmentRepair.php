<?php

namespace App\Support\BulkDocuments;

use App\Enums\BulkDocumentSignatureRequestStatus;
use App\Jobs\RegenerateAlignedSignedBulkDocumentPdfsJob;
use App\Models\BulkDocumentSignatureRepairRun;
use App\Models\BulkDocumentSignatureRequest;

final class DispatchSignedBulkDocumentAlignmentRepair
{
    public function handle(BulkDocumentSignatureRequest $request, ?int $initiatedBy = null): void
    {
        if (! in_array($request->status, [
            BulkDocumentSignatureRequestStatus::Submitted,
            BulkDocumentSignatureRequestStatus::Approved,
        ], true)) {
            return;
        }

        if ($request->signature_image_path === null || $request->signed_pdf_path === null) {
            return;
        }

        $run = BulkDocumentSignatureRepairRun::query()->create([
            'company_id' => $request->company_id,
            'document_type_key' => $request->document_type_key,
            'status' => 'queued',
            'total_count' => 1,
            'initiated_by' => $initiatedBy,
        ]);

        RegenerateAlignedSignedBulkDocumentPdfsJob::dispatch(
            (int) $request->company_id,
            $initiatedBy,
            $run->id,
            [(int) $request->id],
        );
    }
}
