<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\CompanyDocumentVersion;
use App\Support\CompanyDocuments\CompanyDocumentAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class BranchDocumentVersionController extends Controller
{
    public function index(
        Request $request,
        Branch $branch,
        CompanyDocument $companyDocument,
        CompanyDocumentAccess $access,
    ): JsonResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $company = Company::query()->findOrFail($companyId);

        $access->authorizeBranchDocument($request->user(), $company, $branch, $companyDocument, CompanyDocumentAccess::Abilities['view']);
        $companyDocument->load(['versions.replacer:id,name']);

        return response()->json([
            'versions' => $companyDocument->versions->map(fn (CompanyDocumentVersion $version) => [
                'id' => $version->id,
                'version' => $version->version,
                'original_filename' => $version->original_filename,
                'mime_type' => $version->mime_type,
                'size_bytes' => $version->size_bytes,
                'replaced_by' => $version->replacer?->name,
                'created_at' => $version->created_at?->toIso8601String(),
                'download_url' => route('organization.branches.documents.versions.download', [$branch->id, $companyDocument->id, $version->id]),
            ])->values(),
        ]);
    }

    public function download(
        Request $request,
        Branch $branch,
        CompanyDocument $companyDocument,
        CompanyDocumentVersion $companyDocumentVersion,
        CompanyDocumentAccess $access,
    ): Response {
        $companyId = (int) $request->attributes->get('current_company_id');
        $company = Company::query()->findOrFail($companyId);

        $access->authorizeBranchDocument($request->user(), $company, $branch, $companyDocument, CompanyDocumentAccess::Abilities['download']);
        abort_unless(
            (int) $companyDocumentVersion->company_document_id === $companyDocument->id
            && (int) $companyDocumentVersion->company_id === $company->id,
            404,
        );
        abort_unless(Storage::disk('local')->exists($companyDocumentVersion->file_path), 404);

        return Storage::disk('local')->download($companyDocumentVersion->file_path, $companyDocumentVersion->original_filename);
    }
}
