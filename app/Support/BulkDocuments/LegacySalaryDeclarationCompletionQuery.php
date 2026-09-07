<?php

namespace App\Support\BulkDocuments;

use App\Enums\BulkDocumentSignatureRequestStatus;
use App\Models\BulkDocumentSignatureRequest;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentType;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class LegacySalaryDeclarationCompletionQuery
{
    /**
     * @var array<int, list<int>>
     */
    private array $completedEmployeeIdsByCompany = [];

    public function appliesToTemplate(DocumentGenerationTemplate $template): bool
    {
        $successorTypeId = $this->successorDocumentTypeId();

        return $successorTypeId !== null
            && $template->document_type_id !== null
            && (int) $template->document_type_id === $successorTypeId;
    }

    public function successorDocumentTypeId(): ?int
    {
        $title = BulkDocumentTypeRegistry::find(LegacySalaryDeclarationSigning::DOCUMENT_TYPE_KEY)['document_type_title'];

        $id = DocumentType::query()
            ->where('title', $title)
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Distinct employee IDs with an approved, signed Salary Declaration in this company.
     *
     * @return list<int>
     */
    public function completedEmployeeIds(int $companyId): array
    {
        if (array_key_exists($companyId, $this->completedEmployeeIdsByCompany)) {
            return $this->completedEmployeeIdsByCompany[$companyId];
        }

        $ids = $this->completedQuery($companyId)
            ->distinct()
            ->orderBy('employee_id')
            ->pluck('employee_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        $this->completedEmployeeIdsByCompany[$companyId] = $ids;

        return $ids;
    }

    /**
     * Latest qualifying historical completion per employee (batch).
     *
     * @param  list<int>  $employeeIds
     * @return Collection<int, BulkDocumentSignatureRequest>
     */
    public function latestCompletionsForEmployees(int $companyId, array $employeeIds): Collection
    {
        if ($employeeIds === []) {
            return collect();
        }

        return $this->completedQuery($companyId)
            ->whereIn('employee_id', $employeeIds)
            ->orderByDesc('signed_at')
            ->orderByDesc('id')
            ->get([
                'id',
                'company_id',
                'employee_id',
                'employee_document_id',
                'document_type_key',
                'status',
                'signed_at',
                'signed_pdf_path',
                'reviewed_at',
                'created_at',
            ])
            ->unique('employee_id')
            ->keyBy('employee_id');
    }

    public function latestCompletionForEmployee(int $companyId, int $employeeId): ?BulkDocumentSignatureRequest
    {
        return $this->latestCompletionsForEmployees($companyId, [$employeeId])->get($employeeId);
    }

    /**
     * Restrict an employee query to historically completed employees (when IDs exist).
     *
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public function whereHistoricallyCompleted(Builder $query, int $companyId): Builder
    {
        $ids = $this->completedEmployeeIds($companyId);

        if ($ids === []) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereIn('id', $ids);
    }

    /**
     * Exclude historically completed employees from an employee query.
     *
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public function whereNotHistoricallyCompleted(Builder $query, int $companyId): Builder
    {
        $ids = $this->completedEmployeeIds($companyId);

        if ($ids === []) {
            return $query;
        }

        return $query->whereNotIn('id', $ids);
    }

    /**
     * @return Builder<BulkDocumentSignatureRequest>
     */
    private function completedQuery(int $companyId): Builder
    {
        return BulkDocumentSignatureRequest::query()
            ->forCompany($companyId)
            ->where('document_type_key', LegacySalaryDeclarationSigning::DOCUMENT_TYPE_KEY)
            ->where('status', BulkDocumentSignatureRequestStatus::Approved)
            ->whereNotNull('signed_at')
            ->whereNotNull('signed_pdf_path')
            ->where('signed_pdf_path', '!=', '');
    }
}
