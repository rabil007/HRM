<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\EmployeeDocument\BulkEmployeeIdsRequest;
use App\Support\EmployeeDocuments\DocumentDownloadService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentBulkFolderDownloadController extends Controller
{
    public function __invoke(BulkEmployeeIdsRequest $request, DocumentDownloadService $downloads): StreamedResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        return $downloads->streamBulkEmployeesZip(
            $request->validated('employee_ids'),
            $companyId,
        );
    }
}
