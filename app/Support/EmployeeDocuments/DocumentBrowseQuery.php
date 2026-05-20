<?php

namespace App\Support\EmployeeDocuments;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
     * @return array{
     *     total_documents: int,
     *     expired: int,
     *     expiring_30: int,
     *     expiring_15: int,
     *     expiring_7: int
     * }
     */
    public function expirySummary(int $companyId, ?int $employeeId = null): array
    {
        $today = now()->toDateString();
        $in7 = now()->addDays(7)->toDateString();
        $in15 = now()->addDays(15)->toDateString();
        $in30 = now()->addDays(30)->toDateString();

        $row = EmployeeDocument::query()
            ->forCompany($companyId)
            ->when($employeeId !== null, fn ($query) => $query->where('employee_id', $employeeId))
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
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function documentsForCompliance(
        int $companyId,
        string $expiryFilter,
        ?string $search = null,
        int $perPage = 25,
    ): LengthAwarePaginator {
        $search = $search !== null ? trim($search) : '';
        $today = now()->toDateString();

        $query = EmployeeDocument::query()
            ->forCompany($companyId)
            ->with([
                'employee:id,name,employee_no,company_id',
                'documentType:id,title',
                'uploader:id,name',
            ]);

        DocumentExpiry::applyExpiryFilter($query, $expiryFilter);

        $query
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($inner) use ($search) {
                    $inner->whereHas('employee', function ($employeeQuery) use ($search) {
                        $employeeQuery
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('employee_no', 'like', "%{$search}%");
                    })
                        ->orWhere('original_filename', 'like', "%{$search}%")
                        ->orWhere('title', 'like', "%{$search}%")
                        ->orWhereHas('documentType', fn ($typeQuery) => $typeQuery->where('title', 'like', "%{$search}%"));
                });
            })
            ->orderByRaw('CASE WHEN expiry_date < ? THEN 0 ELSE 1 END', [$today])
            ->orderBy('expiry_date');

        return $query
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (EmployeeDocument $document) => [
                ...$document->toBrowseArray(),
                'employee_id' => $document->employee_id,
                'employee_name' => $document->employee?->name ?? '',
                'employee_no' => $document->employee?->employee_no ?? '',
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
            ->with([
                'documentType:id,title',
                'uploader:id,name',
            ])
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
