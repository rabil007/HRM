<?php

namespace App\Http\Controllers\Organization\BulkDocuments;

use App\Http\Controllers\Controller;
use App\Models\BulkDocumentSignatureRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadSignedBulkDocumentController extends Controller
{
    public function __invoke(
        Request $request,
        BulkDocumentSignatureRequest $signatureRequest,
    ): StreamedResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        abort_unless($signatureRequest->company_id === $companyId, 404);

        $path = (string) $signatureRequest->signed_pdf_path;

        if ($path === '' || ! Storage::disk('public')->exists($path)) {
            abort(404);
        }

        return Storage::disk('public')->download(
            $path,
            'signed-salary-declaration.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }
}
