<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDocument;
use App\Models\EmployeeDocumentVersion;
use App\Support\EmployeeDocuments\DocumentDownloadService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EmployeeDocumentVersionFileController extends Controller
{
    public function preview(
        Request $request,
        EmployeeDocument $document,
        EmployeeDocumentVersion $version,
        DocumentDownloadService $downloads,
    ): Response {
        return $this->respond($request, $document, $version, $downloads, inline: true);
    }

    public function download(
        Request $request,
        EmployeeDocument $document,
        EmployeeDocumentVersion $version,
        DocumentDownloadService $downloads,
    ): Response {
        return $this->respond($request, $document, $version, $downloads, inline: false);
    }

    private function respond(
        Request $request,
        EmployeeDocument $document,
        EmployeeDocumentVersion $version,
        DocumentDownloadService $downloads,
        bool $inline,
    ): Response {
        $companyId = (int) $request->attributes->get('current_company_id');

        return $downloads->downloadDocumentVersion($document, $version, $companyId, $inline);
    }
}
