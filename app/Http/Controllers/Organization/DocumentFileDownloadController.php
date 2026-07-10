<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDocument;
use App\Support\EmployeeDocuments\DocumentDownloadService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DocumentFileDownloadController extends Controller
{
    public function __invoke(
        Request $request,
        EmployeeDocument $document,
        DocumentDownloadService $downloads,
    ): Response {
        $companyId = (int) $request->attributes->get('current_company_id');

        return $downloads->downloadSingleDocument(
            $document,
            $companyId,
            $request->boolean('inline'),
        );
    }
}
