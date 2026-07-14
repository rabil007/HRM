<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\CompanyDocumentVersion;
use App\Support\CompanyDocuments\CompanyDocumentAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class CompanyDocumentVersionController extends Controller
{
    public function index(
        Request $request,
        Company $company,
        CompanyDocument $companyDocument,
        CompanyDocumentAccess $access,
    ): JsonResponse {
        $access->authorize($request->user(), $company, CompanyDocumentAccess::Abilities['view']);
        abort_unless((int) $companyDocument->company_id === $company->id, 404);
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
                'download_url' => route('organization.companies.documents.versions.download', [$company->id, $companyDocument->id, $version->id]),
            ])->values(),
        ]);
    }

    public function download(
        Request $request,
        Company $company,
        CompanyDocument $companyDocument,
        CompanyDocumentVersion $companyDocumentVersion,
        CompanyDocumentAccess $access,
    ): Response {
        $access->authorize($request->user(), $company, CompanyDocumentAccess::Abilities['download']);
        abort_unless(
            (int) $companyDocument->company_id === $company->id
            && (int) $companyDocumentVersion->company_document_id === $companyDocument->id
            && (int) $companyDocumentVersion->company_id === $company->id,
            404,
        );
        abort_unless(Storage::disk('local')->exists($companyDocumentVersion->file_path), 404);

        return Storage::disk('local')->download($companyDocumentVersion->file_path, $companyDocumentVersion->original_filename);
    }
}
