<?php

namespace App\Http\Controllers\Organization;

use App\Exports\DocumentRequirementsExport;
use App\Exports\DocumentsExport;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\Documents\DocumentsLibraryQueryState;
use App\Support\EmployeeDocuments\DocumentBrowseQuery;
use App\Support\EmployeeDocuments\DocumentComplianceQuery;
use App\Support\EmployeeDocuments\DocumentExpiry;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DocumentExportController extends Controller
{
    public function export(
        Request $request,
        DocumentBrowseQuery $browse,
        DocumentComplianceQuery $compliance,
    ): BinaryFileResponse {
        $user = $request->user();
        abort_unless(
            $user !== null && ($user->can('documents.view') || $user->can('documents.download')),
            403,
        );

        $companyId = (int) $request->attributes->get('current_company_id');
        $format = strtolower((string) $request->query('format', 'xlsx'));
        $timestamp = now()->format('Y-m-d_His');

        $ids = $this->parseSelectedIds($request);
        $employeeId = $request->query('employee_id');

        if ($employeeId !== null && $employeeId !== '') {
            $employee = Employee::query()
                ->where('company_id', $companyId)
                ->findOrFail((int) $employeeId);

            $query = EmployeeDocument::query()
                ->forCompany($companyId)
                ->where('employee_id', $employee->id)
                ->with([
                    'employee:id,name,employee_no,company_id,department_id',
                    'employee.department:id,name',
                    'documentType:id,title',
                    'uploader:id,name',
                ]);

            $search = trim((string) $request->query('search', ''));
            if ($search !== '') {
                $like = '%'.$search.'%';
                $query->where(function ($inner) use ($like): void {
                    $inner->where('original_filename', 'like', $like)
                        ->orWhere('title', 'like', $like)
                        ->orWhere('document_number', 'like', $like)
                        ->orWhereHas('documentType', fn ($tq) => $tq->where('title', 'like', $like));
                });
            }

            $expiry = (string) $request->query('expiry', 'all');
            if (DocumentExpiry::isValidFilter($expiry) && $expiry !== 'all') {
                DocumentExpiry::applyExpiryFilter($query, $expiry);
            }

            if ($ids !== []) {
                $query->whereKey($ids);
            }

            $query->latestUpload();
            $export = new DocumentsExport($query);
            $baseName = "employee_{$employee->employee_no}_documents_{$timestamp}";

            return $this->downloadExport($export, $baseName, $format);
        }

        $libraryQuery = DocumentsLibraryQueryState::fromRequest($request);
        $search = $libraryQuery->search;
        $expiry = $libraryQuery->expiry;
        $departmentId = $libraryQuery->departmentId;
        $requirementStatus = $libraryQuery->requirementStatus;
        $documentTypeId = $libraryQuery->documentTypeId !== ''
            ? (int) $libraryQuery->documentTypeId
            : null;

        $isRequirementView = $requirementStatus !== ''
            || ($documentTypeId !== null && $expiry === 'all');

        if ($isRequirementView) {
            $query = $compliance->exportQuery(
                $companyId,
                $requirementStatus !== '' ? $requirementStatus : 'required',
                $search !== '' ? $search : null,
                $departmentId,
                $documentTypeId,
            );

            if ($ids !== []) {
                $query->whereIn('employee_documents.id', $ids);
            }

            $export = new DocumentRequirementsExport($query);
            $baseName = "documents_compliance_{$timestamp}";

            return $this->downloadExport($export, $baseName, $format);
        }

        if ($expiry !== 'all') {
            $query = $browse->complianceExportQuery(
                $companyId,
                $expiry,
                $search !== '' ? $search : null,
                $departmentId,
                $documentTypeId,
            );

            if ($ids !== []) {
                $query->whereKey($ids);
            }

            $export = new DocumentsExport($query);
            $baseName = "documents_{$expiry}_{$timestamp}";

            return $this->downloadExport($export, $baseName, $format);
        }

        $query = $browse->browseExportQuery(
            $companyId,
            $search !== '' ? $search : null,
            $departmentId,
        );

        if ($documentTypeId !== null && $documentTypeId > 0) {
            $query->where('document_type_id', $documentTypeId);
        }

        if ($ids !== []) {
            $query->whereKey($ids);
        }

        $export = new DocumentsExport($query);
        $baseName = "documents_{$timestamp}";

        return $this->downloadExport($export, $baseName, $format);
    }

    /**
     * @return list<int>
     */
    private function parseSelectedIds(Request $request): array
    {
        $rawIds = $request->query('ids');

        if (! is_string($rawIds) || trim($rawIds) === '') {
            return [];
        }

        return collect(explode(',', $rawIds))
            ->map(fn (string $value) => trim($value))
            ->filter(fn (string $value) => ctype_digit($value) && (int) $value > 0)
            ->map(fn (string $value): int => (int) $value)
            ->unique()
            ->values()
            ->all();
    }

    private function downloadExport(
        DocumentsExport|DocumentRequirementsExport $export,
        string $baseName,
        string $format,
    ): BinaryFileResponse {
        if ($format === 'csv') {
            return Excel::download($export, "{$baseName}.csv", ExcelWriter::CSV, [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        return Excel::download($export, "{$baseName}.xlsx", ExcelWriter::XLSX);
    }
}
