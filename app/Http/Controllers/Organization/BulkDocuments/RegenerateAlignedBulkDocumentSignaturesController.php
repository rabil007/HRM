<?php

namespace App\Http\Controllers\Organization\BulkDocuments;

use App\Enums\BulkDocumentSignatureRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\BulkDocuments\RegenerateAlignedBulkDocumentSignaturesRequest;
use App\Jobs\RegenerateAlignedSignedBulkDocumentPdfsJob;
use App\Models\BulkDocumentSignatureRepairRun;
use App\Models\BulkDocumentSignatureRequest;
use Illuminate\Http\RedirectResponse;

class RegenerateAlignedBulkDocumentSignaturesController extends Controller
{
    public function __invoke(RegenerateAlignedBulkDocumentSignaturesRequest $request): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $userId = (int) $request->user()?->id;
        $documentTypeKey = (string) $request->input('document_type_key', 'salary_declaration');
        $requestedIds = $request->signatureRequestIds();

        $eligibleIds = BulkDocumentSignatureRequest::query()
            ->where('company_id', $companyId)
            ->where('document_type_key', $documentTypeKey)
            ->whereIn('status', [
                BulkDocumentSignatureRequestStatus::Submitted,
                BulkDocumentSignatureRequestStatus::Approved,
            ])
            ->whereNotNull('signature_image_path')
            ->whereNotNull('signed_pdf_path')
            ->whereKey($requestedIds)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($eligibleIds === []) {
            return back()->with('info', 'No eligible signed documents were selected for alignment repair.');
        }

        $run = BulkDocumentSignatureRepairRun::query()->create([
            'company_id' => $companyId,
            'document_type_key' => $documentTypeKey,
            'status' => 'queued',
            'total_count' => count($eligibleIds),
            'initiated_by' => $userId,
        ]);

        RegenerateAlignedSignedBulkDocumentPdfsJob::dispatch(
            $companyId,
            $userId,
            $run->id,
            $eligibleIds,
        );

        return back()->with(
            'success',
            'Regenerating alignment for '.count($eligibleIds).' signed document(s).',
        );
    }
}
