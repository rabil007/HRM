<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDocument;
use App\Support\EmployeeDocuments\DocumentDownloadService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DocumentShareController extends Controller
{
    public function __invoke(
        Request $request,
        EmployeeDocument $document,
        DocumentDownloadService $downloads,
    ): Response {
        $pwdHash = $request->query('pwd_hash');

        if ($pwdHash !== null && $pwdHash !== '') {
            $password = $request->input('password');

            if ($password !== null && hash_equals($pwdHash, hash_hmac('sha256', $password, config('app.key')))) {
                return $downloads->downloadSingleDocument($document, (int) $document->company_id);
            }

            $error = $password !== null ? 'Incorrect password. Please try again.' : null;

            $documentName = (string) ($document->original_filename ?? $document->title ?? $document->document_type_label);
            $fileSize = $document->size_bytes ? number_format($document->size_bytes / 1024 / 1024, 2).' MB' : '';

            return response()->view('documents.share-password', [
                'document' => $document,
                'document_name' => $documentName,
                'file_size' => $fileSize,
                'error' => $error,
            ]);
        }

        return $downloads->downloadSingleDocument($document, (int) $document->company_id);
    }
}
