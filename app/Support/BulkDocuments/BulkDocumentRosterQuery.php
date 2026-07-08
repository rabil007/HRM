<?php

namespace App\Support\BulkDocuments;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeDirectoryQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
     *         image: string|null,
     *         department: string|null,
     *         position: string|null,
     *         email: string|null,
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
        return [
            'counts' => self::counts($companyId, $documentTypeKey, $filters, $employeeIds),
            'employees' => self::rosterEmployees($companyId, $documentTypeKey, $filters, $employeeIds),
        ];
    }

    /**
     * @param  list<int>|null  $employeeIds
     * @return array{targeted: int, generated: int, not_generated: int}
     */
    public static function counts(
        int $companyId,
        string $documentTypeKey,
        EmployeeDirectoryFilters $filters,
        ?array $employeeIds = null,
    ): array {
        $documentType = BulkDocumentTypeRegistry::resolveDocumentType($documentTypeKey);
        $query = self::baseEmployeeQuery($companyId, $filters, $employeeIds);

        $targeted = (clone $query)->count();

        $generated = (clone $query)->whereHas('documents', function (Builder $documentQuery) use ($companyId, $documentType): void {
            $documentQuery
                ->where('company_id', $companyId)
                ->where('document_type_id', $documentType->id);
        })->count();

        return [
            'targeted' => $targeted,
            'generated' => $generated,
            'not_generated' => $targeted - $generated,
        ];
    }

    public static function paginate(
        int $companyId,
        string $documentTypeKey,
        EmployeeDirectoryFilters $filters,
        int $perPage,
        string $generationFilter = 'all',
    ): LengthAwarePaginator {
        $documentType = BulkDocumentTypeRegistry::resolveDocumentType($documentTypeKey);

        $query = self::baseEmployeeQuery($companyId, $filters)
            ->with([
                'department:id,name',
                'position:id,title',
            ]);

        self::applyGenerationFilter($query, $companyId, $documentType->id, $generationFilter);

        $paginator = $query
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        $documentsByEmployee = self::latestDocumentsForEmployees(
            $companyId,
            $documentType->id,
            $paginator->getCollection()->pluck('id')->all(),
        );

        return $paginator->through(
            fn (Employee $employee): array => self::mapEmployee(
                $employee,
                $documentsByEmployee->get($employee->id),
            ),
        );
    }

    public static function employeeQuery(
        int $companyId,
        EmployeeDirectoryFilters $filters,
        ?array $employeeIds = null,
    ): Builder {
        return self::baseEmployeeQuery($companyId, $filters, $employeeIds)->orderBy('id');
    }

    /**
     * @return array{
     *     employee_ids: list<int>,
     *     document_ids: list<int>,
     *     total: int
     * }
     */
    public static function matchingSelection(
        int $companyId,
        string $documentTypeKey,
        EmployeeDirectoryFilters $filters,
        string $generationFilter = 'all',
    ): array {
        $documentType = BulkDocumentTypeRegistry::resolveDocumentType($documentTypeKey);

        $employeeIds = self::filteredEmployeeQuery(
            $companyId,
            $documentType->id,
            $filters,
            $generationFilter,
        )
            ->orderBy('name')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        $documentsByEmployee = self::latestDocumentsForEmployees(
            $companyId,
            $documentType->id,
            $employeeIds,
        );

        return [
            'employee_ids' => $employeeIds,
            'document_ids' => $documentsByEmployee->values()->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            'total' => count($employeeIds),
        ];
    }

    private static function filteredEmployeeQuery(
        int $companyId,
        int $documentTypeId,
        EmployeeDirectoryFilters $filters,
        string $generationFilter,
    ): Builder {
        $query = self::baseEmployeeQuery($companyId, $filters);

        self::applyGenerationFilter($query, $companyId, $documentTypeId, $generationFilter);

        return $query;
    }

    private static function applyGenerationFilter(
        Builder $query,
        int $companyId,
        int $documentTypeId,
        string $generationFilter,
    ): void {
        if ($generationFilter === 'missing') {
            $query->whereDoesntHave('documents', function (Builder $documentQuery) use ($companyId, $documentTypeId): void {
                $documentQuery
                    ->where('company_id', $companyId)
                    ->where('document_type_id', $documentTypeId);
            });

            return;
        }

        if ($generationFilter === 'generated') {
            $query->whereHas('documents', function (Builder $documentQuery) use ($companyId, $documentTypeId): void {
                $documentQuery
                    ->where('company_id', $companyId)
                    ->where('document_type_id', $documentTypeId);
            });
        }
    }

    /**
     * @param  list<int>|null  $employeeIds
     * @return list<array{
     *     id: int,
     *     name: string,
     *     employee_no: string|null,
     *     image: string|null,
     *     department: string|null,
     *     position: string|null,
     *     email: string|null,
     *     status: string,
     *     document: array{id: int, file_path: string, created_at: string|null}|null
     * }>
     */
    private static function rosterEmployees(
        int $companyId,
        string $documentTypeKey,
        EmployeeDirectoryFilters $filters,
        ?array $employeeIds = null,
    ): array {
        $documentType = BulkDocumentTypeRegistry::resolveDocumentType($documentTypeKey);

        $employees = self::baseEmployeeQuery($companyId, $filters, $employeeIds)
            ->with([
                'department:id,name',
                'position:id,title',
                'companyVisaTypeRef:id,name',
            ])
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'employee_no',
                'image',
                'department_id',
                'position_id',
                'work_email',
                'personal_email',
                'status',
            ]);

        $documentsByEmployee = self::latestDocumentsForEmployees(
            $companyId,
            $documentType->id,
            $employees->pluck('id')->all(),
        );

        return $employees
            ->map(fn (Employee $employee): array => self::mapEmployee(
                $employee,
                $documentsByEmployee->get($employee->id),
            ))
            ->values()
            ->all();
    }

    /**
     * @param  list<int>|null  $employeeIds
     */
    private static function baseEmployeeQuery(
        int $companyId,
        EmployeeDirectoryFilters $filters,
        ?array $employeeIds = null,
    ): Builder {
        $query = Employee::query()
            ->where('company_id', $companyId)
            ->where('status', 'active');

        EmployeeDirectoryQuery::applyAttributeFilters($query, $companyId, $filters);

        if ($employeeIds !== null && $employeeIds !== []) {
            $query->whereIn('id', $employeeIds);
        }

        return $query;
    }

    /**
     * @param  list<int>  $employeeIds
     * @return Collection<int, EmployeeDocument>
     */
    private static function latestDocumentsForEmployees(
        int $companyId,
        int $documentTypeId,
        array $employeeIds,
    ): Collection {
        if ($employeeIds === []) {
            return collect();
        }

        return EmployeeDocument::query()
            ->where('company_id', $companyId)
            ->where('document_type_id', $documentTypeId)
            ->whereIn('employee_id', $employeeIds)
            ->orderByDesc('id')
            ->get(['id', 'employee_id', 'file_path', 'created_at'])
            ->groupBy('employee_id')
            ->map(fn ($documents) => $documents->first());
    }

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     employee_no: string|null,
     *     image: string|null,
     *     department: string|null,
     *     position: string|null,
     *     email: string|null,
     *     status: string,
     *     document: array{id: int, file_path: string, created_at: string|null}|null
     * }
     */
    private static function mapEmployee(Employee $employee, ?EmployeeDocument $document): array
    {
        return [
            'id' => $employee->id,
            'name' => (string) $employee->name,
            'employee_no' => $employee->employee_no,
            'image' => $employee->image,
            'department' => $employee->department?->name,
            'position' => $employee->position?->title,
            'email' => $employee->work_email ?: $employee->personal_email,
            'status' => (string) $employee->status,
            'document' => $document !== null ? [
                'id' => $document->id,
                'file_path' => (string) $document->file_path,
                'created_at' => $document->created_at?->toIso8601String(),
            ] : null,
        ];
    }
}
