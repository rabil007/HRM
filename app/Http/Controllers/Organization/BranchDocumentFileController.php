<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Support\CompanyDocuments\CompanyDocumentAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class BranchDocumentFileController extends Controller
{
    public function preview(
        Request $request,
        Branch $branch,
        CompanyDocument $companyDocument,
        CompanyDocumentAccess $access,
    ): Response {
        return $this->respond($request, $branch, $companyDocument, $access, true);
    }

    public function download(
        Request $request,
        Branch $branch,
        CompanyDocument $companyDocument,
        CompanyDocumentAccess $access,
    ): Response {
        return $this->respond($request, $branch, $companyDocument, $access, false);
    }

    private function respond(
        Request $request,
        Branch $branch,
        CompanyDocument $document,
        CompanyDocumentAccess $access,
        bool $inline,
    ): Response {
        $companyId = (int) $request->attributes->get('current_company_id');
        $company = Company::query()->findOrFail($companyId);

        $access->authorizeBranchDocument($request->user(), $company, $branch, $document, CompanyDocumentAccess::Abilities['download']);
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
