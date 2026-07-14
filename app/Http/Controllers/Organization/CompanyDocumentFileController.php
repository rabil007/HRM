<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Support\CompanyDocuments\CompanyDocumentAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class CompanyDocumentFileController extends Controller
{
    public function preview(
        Request $request,
        Company $company,
        CompanyDocument $companyDocument,
        CompanyDocumentAccess $access,
    ): Response {
        return $this->respond($request, $company, $companyDocument, $access, true);
    }

    public function download(
        Request $request,
        Company $company,
        CompanyDocument $companyDocument,
        CompanyDocumentAccess $access,
    ): Response {
        return $this->respond($request, $company, $companyDocument, $access, false);
    }

    private function respond(
        Request $request,
        Company $company,
        CompanyDocument $document,
        CompanyDocumentAccess $access,
        bool $inline,
    ): Response {
        $access->authorize($request->user(), $company, CompanyDocumentAccess::Abilities['download']);
        abort_unless((int) $document->company_id === $company->id, 404);
        abort_unless(Storage::disk('local')->exists($document->file_path), 404);

        if (! $inline) {
            return Storage::disk('local')->download($document->file_path, $document->original_filename);
        }

        return Storage::disk('local')->response($document->file_path, $document->original_filename, [
            'Content-Type' => $document->mime_type,
            'Content-Disposition' => 'inline; filename="'.str_replace('"', '', $document->original_filename).'"',
        ]);
    }
}
