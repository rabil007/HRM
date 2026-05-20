<?php

namespace App\Support\EmployeeDocuments;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Support\Collection;

class DocumentBrowseQuery
{
    /**
     * @return Collection<int, array{employee_id: int, employee_name: string, employee_no: string, document_count: int}>
     */
    public function employeesWithDocuments(int $companyId, ?string $search = null): Collection
    {
        $search = $search !== null ? trim($search) : '';

        return Employee::query()
            ->where('company_id', $companyId)
            ->whereHas('documents', fn ($query) => $query->where('company_id', $companyId))
            ->withCount([
                'documents as document_count' => fn ($query) => $query->where('company_id', $companyId),
            ])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('employee_no', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->get(['id', 'name', 'employee_no'])
            ->map(fn (Employee $employee) => [
                'employee_id' => $employee->id,
                'employee_name' => $employee->name,
                'employee_no' => $employee->employee_no,
                'document_count' => (int) $employee->document_count,
            ]);
    }

    /**
     * @return array{employee: array{id: int, name: string, employee_no: string}, documents: list<array<string, mixed>>}
     */
    public function documentsForEmployee(int $companyId, Employee $employee): array
    {
        $documents = EmployeeDocument::query()
            ->forCompany($companyId)
            ->where('employee_id', $employee->id)
            ->with('documentType:id,title')
            ->latestUpload()
            ->get()
            ->map(fn (EmployeeDocument $document) => $document->toBrowseArray())
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
}
