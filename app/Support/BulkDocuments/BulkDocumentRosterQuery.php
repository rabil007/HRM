<?php

namespace App\Support\BulkDocuments;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeDirectoryQuery;
use Illuminate\Database\Eloquent\Builder;

final class BulkDocumentRosterQuery
{
    /**
     * @param  list<int>|null  $employeeIds
     * @return array{
     *     counts: array{targeted: int, generated: int, not_generated: int},
     *     employees: list<array{
     *         id: int,
     *         name: string,
     *         employee_no: string|null,
     *         department: string|null,
     *         sponsor: string|null,
     *         status: string,
     *         document: array{id: int, file_path: string, created_at: string|null}|null
     *     }>
     * }
     */
    public static function for(
        int $companyId,
        string $documentTypeKey,
        EmployeeDirectoryFilters $filters,
        ?array $employeeIds = null,
    ): array {
        $documentType = BulkDocumentTypeRegistry::resolveDocumentType($documentTypeKey);

        $query = Employee::query()
            ->where('company_id', $companyId)
            ->with([
                'department:id,name',
                'companyVisaTypeRef:id,name',
            ]);

        EmployeeDirectoryQuery::applyAttributeFilters($query, $companyId, $filters);

        if ($employeeIds !== null && $employeeIds !== []) {
            $query->whereIn('id', $employeeIds);
        }

        $employees = $query
            ->orderBy('name')
            ->get(['id', 'name', 'employee_no', 'department_id', 'company_visa_type_id', 'status']);

        $documentIdsByEmployee = EmployeeDocument::query()
            ->where('company_id', $companyId)
            ->where('document_type_id', $documentType->id)
            ->whereIn('employee_id', $employees->pluck('id'))
            ->orderByDesc('id')
            ->get(['id', 'employee_id', 'file_path', 'created_at'])
            ->groupBy('employee_id')
            ->map(fn ($docs) => $docs->first());

        $generated = 0;
        $roster = [];

        foreach ($employees as $employee) {
            /** @var EmployeeDocument|null $document */
            $document = $documentIdsByEmployee->get($employee->id);

            if ($document !== null) {
                $generated++;
            }

            $roster[] = [
                'id' => $employee->id,
                'name' => (string) $employee->name,
                'employee_no' => $employee->employee_no,
                'department' => $employee->department?->name,
                'sponsor' => $employee->companyVisaTypeRef?->name,
                'status' => (string) $employee->status,
                'document' => $document !== null ? [
                    'id' => $document->id,
                    'file_path' => (string) $document->file_path,
                    'created_at' => $document->created_at?->toIso8601String(),
                ] : null,
            ];
        }

        $targeted = count($roster);

        return [
            'counts' => [
                'targeted' => $targeted,
                'generated' => $generated,
                'not_generated' => $targeted - $generated,
            ],
            'employees' => $roster,
        ];
    }

    public static function employeeQuery(
        int $companyId,
        EmployeeDirectoryFilters $filters,
        ?array $employeeIds = null,
    ): Builder {
        $query = Employee::query()->where('company_id', $companyId);

        EmployeeDirectoryQuery::applyAttributeFilters($query, $companyId, $filters);

        if ($employeeIds !== null && $employeeIds !== []) {
            $query->whereIn('id', $employeeIds);
        }

        return $query->orderBy('id');
    }
}
