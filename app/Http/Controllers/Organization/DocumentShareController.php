<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDocument;
use App\Support\EmployeeDocuments\DocumentDownloadService;
use Symfony\Component\HttpFoundation\Response;

class DocumentShareController extends Controller
{
    public function __invoke(
        EmployeeDocument $document,
        DocumentDownloadService $downloads,
    ): Response {
        return $downloads->downloadSingleDocument($document, (int) $document->company_id);
    }
}
