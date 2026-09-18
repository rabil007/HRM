<?php

namespace App\Support\EmployeeDocuments;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeDirectoryQuery;
use App\Support\Employees\EmployeeVisibilityScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DocumentBrowseQuery
{
    public function __construct(
        private ?User $user = null,
    ) {}

    public function forUser(?User $user): self
    {
        $clone = clone $this;
        $clone->user = $user;

        return $clone;
    }

    private function resolveUser(?User $user = null): ?User
    {
        return $user ?? $this->user ?? auth()->user();
    }

    /**
     * @return Collection<int, array{employee_id: int, employee_name: string, employee_no: string, document_count: int}>
     */
    public function employeesWithDocuments(
        int $companyId,
        ?string $search = null,
        string $departmentId = '',
        ?User $user = null,
    ): Collection {
        $actor = $this->resolveUser($user);
        $search = $search !== null ? trim($search) : '';

        $query = Employee::query()
            ->where('company_id', $companyId)
            ->active()
            ->whereHas('documents', fn ($query) => $query->where('company_id', $companyId))
            ->withCount([
                'documents as document_count' => fn ($query) => $query->where('company_id', $companyId),
            ]);

        if ($actor !== null) {
            EmployeeVisibilityScope::apply($query, $actor, $companyId);
        }

        return $query
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('employee_no', 'like', "%{$search}%");
                });
            })
            ->when($departmentId !== '', function ($query) use ($companyId, $departmentId, $actor): void {
                EmployeeDirectoryQuery::applyAttributeFilters(
                    $query,
                    $companyId,
                    new EmployeeDirectoryFilters(departmentId: $departmentId),
                    exceptPosition: true,
                    user: $actor,
                );
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
     * @return array{
     *     total_documents: int,
     *     expired: int,
     *     expiring_30: int,
     *     expiring_15: int,
     *     expiring_7: int
     * }
     */
    public function expirySummary(
        int $companyId,
        ?int $employeeId = null,
        string $departmentId = '',
    ): array {
        $today = now()->toDateString();
        $in7 = now()->addDays(7)->toDateString();
        $in15 = now()->addDays(15)->toDateString();
        $in30 = now()->addDays(30)->toDateString();

        $query = EmployeeDocument::query()
            ->forCompany($companyId)
            ->when($employeeId !== null, fn ($q) => $q->where('employee_id', $employeeId));

        if ($employeeId === null) {
            $this->applyOperationalEmployeeFilter($query, $companyId, $departmentId);
        }

        $row = $query
            ->selectRaw('COUNT(*) as total_documents')
            ->selectRaw(
                'SUM(CASE WHEN expiry_date IS NOT NULL AND expiry_date < ? THEN 1 ELSE 0 END) as expired',
                [$today],
            )
            ->selectRaw(
                'SUM(CASE WHEN expiry_date IS NOT NULL AND expiry_date >= ? AND expiry_date <= ? THEN 1 ELSE 0 END) as expiring_30',
                [$today, $in30],
            )
            ->selectRaw(
                'SUM(CASE WHEN expiry_date IS NOT NULL AND expiry_date >= ? AND expiry_date <= ? THEN 1 ELSE 0 END) as expiring_15',
                [$today, $in15],
            )
            ->selectRaw(
                'SUM(CASE WHEN expiry_date IS NOT NULL AND expiry_date >= ? AND expiry_date <= ? THEN 1 ELSE 0 END) as expiring_7',
                [$today, $in7],
            )
            ->first();

        return [
            'total_documents' => (int) ($row->total_documents ?? 0),
            'expired' => (int) ($row->expired ?? 0),
            'expiring_30' => (int) ($row->expiring_30 ?? 0),
            'expiring_15' => (int) ($row->expiring_15 ?? 0),
            'expiring_7' => (int) ($row->expiring_7 ?? 0),
        ];
    }

    /**
     * @return Builder<EmployeeDocument>
     */
    public function complianceExportQuery(
        int $companyId,
        string $expiryFilter,
        ?string $search = null,
        string $departmentId = '',
        ?int $documentTypeId = null,
    ): Builder {
        $search = $search !== null ? trim($search) : '';
        $today = now()->toDateString();

        $query = EmployeeDocument::query()
            ->forCompany($companyId)
            ->with([
                'employee:id,name,employee_no,company_id,department_id,work_email,personal_email,phone',
                'employee.department:id,name',
                'documentType:id,title',
                'uploader:id,name',
            ])
            ->withCount('versions');

        DocumentExpiry::applyExpiryFilter($query, $expiryFilter);

        $query
            ->when($search !== '', fn (Builder $documentQuery) => $this->applyBrowseSearch($documentQuery, $search))
            ->when(
                $documentTypeId !== null && $documentTypeId > 0,
                fn (Builder $documentQuery) => $documentQuery->where('document_type_id', $documentTypeId),
            )
            ->tap(fn (Builder $documentQuery) => $this->applyOperationalEmployeeFilter($documentQuery, $companyId, $departmentId))
            ->orderByRaw('CASE WHEN expiry_date < ? THEN 0 ELSE 1 END', [$today])
            ->orderBy('expiry_date');

        return $query;
    }

    /**
     * @return Builder<EmployeeDocument>
     */
    public function browseExportQuery(
        int $companyId,
        ?string $search = null,
        string $departmentId = '',
    ): Builder {
        $search = $search !== null ? trim($search) : '';

        $query = EmployeeDocument::query()
            ->forCompany($companyId)
            ->with([
                'employee:id,name,employee_no,company_id,department_id,work_email,personal_email,phone',
                'employee.department:id,name',
                'documentType:id,title',
                'uploader:id,name',
            ])
            ->withCount('versions');

        if ($search !== '') {
            $this->applyBrowseSearch($query, $search);
        }

        $this->applyOperationalEmployeeFilter($query, $companyId, $departmentId);

        return $query->latestUpload();
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function documentsForCompliance(
        int $companyId,
        string $expiryFilter,
        ?string $search = null,
        int $perPage = 25,
        string $departmentId = '',
        ?int $documentTypeId = null,
    ): LengthAwarePaginator {
        $query = $this->complianceExportQuery(
            $companyId,
            $expiryFilter,
            $search,
            $departmentId,
            $documentTypeId,
        );

        return $this->paginateBrowseDocuments($query, $perPage);
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function documentsForSearch(
        int $companyId,
        string $search,
        int $perPage = 25,
        string $departmentId = '',
    ): LengthAwarePaginator {
        $query = $this->browseExportQuery(
            $companyId,
            $search,
            $departmentId,
        );

        return $this->paginateBrowseDocuments(
            $query,
            $perPage,
        );
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginateBrowseDocuments(Builder $query, int $perPage): LengthAwarePaginator
    {
        return $query
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (EmployeeDocument $document) => [
                ...$document->toProfileArray(),
                'employee_id' => $document->employee_id,
                'employee_name' => $document->employee?->name ?? '',
                'employee_no' => $document->employee?->employee_no ?? '',
                'employee_email' => $document->employee?->work_email
                    ?: $document->employee?->personal_email,
                'employee_phone' => $document->employee?->phone,
            ]);
    }

    private function applyBrowseSearch(Builder $query, string $search): void
    {
        $like = '%'.$search.'%';

        $query->where(function (Builder $inner) use ($like) {
            $inner->whereHas('employee', function (Builder $employeeQuery) use ($like) {
                $employeeQuery
                    ->active()
                    ->where(function (Builder $nameQuery) use ($like): void {
                        $nameQuery
                            ->where('name', 'like', $like)
                            ->orWhere('employee_no', 'like', $like);
                    });
            })->orWhere(function (Builder $documentQuery) use ($like) {
                $this->applyDocumentFieldSearch($documentQuery, $like);
            });
        });
    }

    private function applyOperationalEmployeeFilter(
        Builder $query,
        int $companyId,
        string $departmentId = '',
        ?User $user = null,
    ): void {
        $actor = $this->resolveUser($user);

        if ($actor !== null) {
            EmployeeVisibilityScope::whereHas($query, $actor, $companyId, 'employee');
        }

        $query->whereHas('employee', function (Builder $employeeQuery) use ($companyId, $departmentId, $actor): void {
            EmployeeDirectoryQuery::applyAttributeFilters(
                $employeeQuery,
                $companyId,
                new EmployeeDirectoryFilters(departmentId: $departmentId),
                exceptPosition: true,
                user: $actor,
            );
        });
    }

    private function applyDocumentFieldSearch(Builder $query, string $like): void
    {
        $query->where(function (Builder $inner) use ($like) {
            $inner->where('original_filename', 'like', $like)
                ->orWhere('title', 'like', $like)
                ->orWhere('document_number', 'like', $like)
                ->orWhereHas('documentType', fn (Builder $typeQuery) => $typeQuery->where('title', 'like', $like));
        });
    }

    /**
     * @return array{employee: array{id: int, name: string, employee_no: string}, documents: list<array<string, mixed>>}
     */
    public function documentsForEmployee(int $companyId, Employee $employee, ?User $user = null): array
    {
        $actor = $this->resolveUser($user);

        if ($actor !== null) {
            abort_unless(EmployeeVisibilityScope::canAccess($actor, $employee, $companyId, allowSelf: true), 404);
        }

        $documents = EmployeeDocument::query()
            ->forCompany($companyId)
            ->where('employee_id', $employee->id)
            ->with([
                'documentType:id,title',
                'uploader:id,name',
            ])
            ->withCount('versions')
            ->latestUpload()
            ->get()
            ->map(fn (EmployeeDocument $document) => $document->toProfileArray())
            ->values()
            ->all();

        return [
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'employee_no' => $employee->employee_no,
                'email' => $employee->work_email ?: $employee->personal_email,
                'phone' => $employee->phone,
            ],
            'documents' => $documents,
        ];
    }
}
