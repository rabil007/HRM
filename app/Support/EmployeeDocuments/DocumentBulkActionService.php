<?php

namespace App\Support\EmployeeDocuments;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Support\Collection;

class DocumentBulkActionService
{
    public function __construct(
        private DocumentDeletionService $deletion,
    ) {}

    /**
     * @param  list<int>  $documentIds
     */
    public function deleteDocuments(array $documentIds, int $companyId, int $employeeId): int
    {
        abort_unless(
            Employee::query()
                ->where('company_id', $companyId)
                ->whereKey($employeeId)
                ->exists(),
            404,
        );

        $uniqueIds = array_values(array_unique($documentIds));

        $documents = EmployeeDocument::query()
            ->forCompany($companyId)
            ->where('employee_id', $employeeId)
            ->whereIn('id', $uniqueIds)
            ->get();

        abort_if($documents->count() !== count($uniqueIds), 404);

        foreach ($documents as $document) {
            $this->deletion->delete($document);
        }

        return $documents->count();
    }

    /**
     * @param  list<int>  $documentIds
     * @return Collection<int, EmployeeDocument>
     */
    public function documentsForEmployeeAction(array $documentIds, int $companyId, int $employeeId): Collection
    {
        abort_unless(
            Employee::query()
                ->where('company_id', $companyId)
                ->whereKey($employeeId)
                ->exists(),
            404,
        );

        $uniqueIds = array_values(array_unique($documentIds));

        $documents = EmployeeDocument::query()
            ->forCompany($companyId)
            ->where('employee_id', $employeeId)
            ->whereIn('id', $uniqueIds)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get([
                'id',
                'company_id',
                'employee_id',
                'file_path',
                'original_filename',
                'mime_type',
                'title',
                'document_type',
            ]);

        abort_if($documents->count() !== count($uniqueIds), 404);

        return $documents;
    }

    /**
     * @param  list<int>  $documentIds
     * @return Collection<int, EmployeeDocument>
     */
    public function documentsForDownload(array $documentIds, int $companyId): Collection
    {
        $documents = EmployeeDocument::query()
            ->forCompany($companyId)
            ->whereIn('id', $documentIds)
            ->orderBy('employee_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get([
                'id',
                'company_id',
                'employee_id',
                'file_path',
                'original_filename',
                'mime_type',
                'title',
                'document_type',
            ]);

        abort_if($documents->count() !== count(array_unique($documentIds)), 404);

        return $documents;
    }

    /**
     * @param  list<int>  $employeeIds
     * @return Collection<int, Employee>
     */
    public function employeesForDownload(array $employeeIds, int $companyId): Collection
    {
        $employees = Employee::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $employeeIds)
            ->orderBy('name')
            ->get(['id', 'name', 'employee_no', 'company_id']);

        abort_if($employees->count() !== count(array_unique($employeeIds)), 404);

        return $employees;
    }
}
