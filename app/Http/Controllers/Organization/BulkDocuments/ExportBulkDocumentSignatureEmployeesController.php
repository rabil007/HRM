<?php

namespace App\Http\Controllers\Organization\BulkDocuments;

use App\Exports\BulkDocumentSignatureEmployeesExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\BulkDocuments\ExportBulkDocumentSignatureEmployeesRequest;
use App\Support\BulkDocuments\BulkDocumentTypeRegistry;
use App\Support\BulkDocuments\ExportBulkDocumentSignatureEmployees;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportBulkDocumentSignatureEmployeesController extends Controller
{
    public function __invoke(
        ExportBulkDocumentSignatureEmployeesRequest $request,
        ExportBulkDocumentSignatureEmployees $exportEmployees,
    ): BinaryFileResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $documentTypeKey = (string) $request->input('document_type_key', 'salary_declaration');

        try {
            BulkDocumentTypeRegistry::find($documentTypeKey);
        } catch (\InvalidArgumentException) {
            $documentTypeKey = 'salary_declaration';
        }

        $query = $exportEmployees->query(
            $companyId,
            $documentTypeKey,
            $request->signatureRequestIds(),
        );

        abort_if($query->clone()->doesntExist(), 404, 'No employees found for the current selection.');

        $export = new BulkDocumentSignatureEmployeesExport($query);
        $timestamp = now()->format('Y-m-d_His');
        $baseName = "{$documentTypeKey}-signature-employees-{$timestamp}";
        $format = $request->exportFormat();

        if ($format === 'xlsx') {
            return Excel::download($export, "{$baseName}.xlsx", ExcelWriter::XLSX);
        }

        return Excel::download($export, "{$baseName}.csv", ExcelWriter::CSV, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
