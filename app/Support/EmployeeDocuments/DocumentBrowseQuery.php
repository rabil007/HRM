<?php

namespace App\Support\EmployeeDocuments;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

class DocumentBrowseQuery
{
    /**
     * @return Collection<int, array{employee_id: int, employee_name: string, employee_no: string, document_count: int}>
     */
    public function employeesWithDocuments(int $companyId, ?string $search = null): Collection
    {
        $search = $search !== null ? trim($search) : '';

        return EmployeeDocument::query()
            ->where('employee_documents.company_id', $companyId)
            ->join('employees', 'employees.id', '=', 'employee_documents.employee_id')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('employees.name', 'like', "%{$search}%")
                        ->orWhere('employees.employee_no', 'like', "%{$search}%");
                });
            })
            ->groupBy('employees.id', 'employees.name', 'employees.employee_no')
            ->orderBy('employees.name')
            ->selectRaw('employees.id as employee_id')
            ->selectRaw('employees.name as employee_name')
            ->selectRaw('employees.employee_no as employee_no')
            ->selectRaw('COUNT(employee_documents.id) as document_count')
            ->get()
            ->map(fn ($row) => [
                'employee_id' => (int) $row->employee_id,
                'employee_name' => (string) $row->employee_name,
                'employee_no' => (string) $row->employee_no,
                'document_count' => (int) $row->document_count,
            ]);
    }

    /**
     * @return array{employee: array{id: int, name: string, employee_no: string}, documents: list<array<string, mixed>>}
     */
    public function documentsForEmployee(int $companyId, int $employeeId): array
    {
        $employee = Employee::query()
            ->where('company_id', $companyId)
            ->whereKey($employeeId)
            ->first(['id', 'name', 'employee_no']);

        if ($employee === null) {
            throw (new ModelNotFoundException)->setModel(Employee::class, [$employeeId]);
        }

        $documents = EmployeeDocument::query()
            ->where('employee_documents.company_id', $companyId)
            ->where('employee_documents.employee_id', $employeeId)
            ->leftJoin('document_types', 'document_types.id', '=', 'employee_documents.document_type_id')
            ->orderByDesc('employee_documents.created_at')
            ->orderByDesc('employee_documents.id')
            ->select([
                'employee_documents.id',
                'employee_documents.title',
                'employee_documents.original_filename',
                'employee_documents.file_path',
                'employee_documents.mime_type',
                'employee_documents.document_type',
                'employee_documents.status',
                'employee_documents.created_at',
                'document_types.title as document_type_title',
            ])
            ->get()
            ->map(fn ($doc) => $this->mapDocumentRow($doc))
            ->values()
            ->all();

        return [
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'employee_no' => $employee->employee_no,
            ],
            'documents' => $documents,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapDocumentRow(object $doc): array
    {
        $documentTypeLabel = $doc->document_type_title ?? $doc->document_type ?? 'Document';
        $documentName = $doc->original_filename
            ?? $doc->title
            ?? $documentTypeLabel;

        $mimeType = $doc->mime_type;

        return [
            'id' => $doc->id,
            'document_name' => $documentName,
            'document_type' => $documentTypeLabel,
            'file_url' => str_starts_with((string) $doc->file_path, 'http')
                ? $doc->file_path
                : asset('storage/'.ltrim((string) $doc->file_path, '/')),
            'uploaded_at' => $doc->created_at
                ? Carbon::parse($doc->created_at)->toIso8601String()
                : null,
            'mime_type' => $mimeType,
            'can_preview' => str_starts_with((string) $mimeType, 'image/')
                || $mimeType === 'application/pdf',
            'status' => $doc->status,
        ];
    }
}
