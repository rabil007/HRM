<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Support\EmployeeDocuments\DocumentDownloadService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentFolderDownloadController extends Controller
{
    public function __invoke(Request $request, Employee $employee, DocumentDownloadService $downloads): StreamedResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        $downloads->assertEmployeeAccessible($employee, $companyId);

        return $downloads->streamEmployeeDocumentsZip($employee, $companyId);
    }
}
