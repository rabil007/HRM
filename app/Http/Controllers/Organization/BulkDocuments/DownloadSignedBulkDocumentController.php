<?php

namespace App\Http\Controllers\Organization\BulkDocuments;

use App\Enums\BulkDocumentSignatureRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\BulkDocumentSignatureRequest;
use App\Support\BulkDocuments\BulkDocumentSignatureStorage;
use App\Support\BulkDocuments\BulkDocumentTypeRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadSignedBulkDocumentController extends Controller
{
    public function __invoke(
        Request $request,
        BulkDocumentSignatureRequest $signatureRequest,
    ): StreamedResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($signatureRequest->company_id === $companyId, 404);

        abort_unless(in_array($signatureRequest->status, [
            BulkDocumentSignatureRequestStatus::Submitted,
            BulkDocumentSignatureRequestStatus::Approved,
            BulkDocumentSignatureRequestStatus::Rejected,
        ], true), 404);

        $path = (string) $signatureRequest->signed_pdf_path;

        if ($path === '' || ! BulkDocumentSignatureStorage::exists($path)) {
            abort(404);
        }

        $label = BulkDocumentTypeRegistry::find($signatureRequest->document_type_key)['label'];
        $filename = 'signed-'.(Str::slug($label) ?: 'document').'.pdf';

        return BulkDocumentSignatureStorage::download(
            $path,
            $filename,
            ['Content-Type' => 'application/pdf'],
        );
    }
}
