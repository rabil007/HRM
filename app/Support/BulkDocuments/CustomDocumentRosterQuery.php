<?php

namespace App\Support\BulkDocuments;

use App\Models\DocumentGenerationRunItem;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\DocumentInstance;
use App\Models\Employee;
use App\Support\Employees\EmployeeDirectoryFilters;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class CustomDocumentRosterQuery
{
    /**
     * @param  list<int>|null  $employeeIds
     * @return array{targeted: int, generated: int, not_generated: int, pending_review: int, awaiting_signature: int, approved: int}
     */
    public static function counts(
        int $companyId,
        DocumentGenerationTemplate $template,
        DocumentGenerationTemplateVersion $version,
        EmployeeDirectoryFilters $filters,
        ?array $employeeIds = null,
    ): array {
        $query = BulkDocumentRosterQuery::employeeQuery($companyId, $filters, $employeeIds);
        $targeted = (clone $query)->count();

        $generated = (clone $query)->whereHas('documentInstances', function (Builder $instanceQuery) use ($companyId, $version): void {
            $instanceQuery->where('company_id', $companyId)
                ->where('document_generation_template_version_id', $version->id)
                ->withLibraryDocument();
        })->count();

        $notGenerated = max(0, $targeted - $generated);

        return [
            'targeted' => $targeted,
            'generated' => $generated,
            'not_generated' => $notGenerated,
            'pending_review' => 0,
            'awaiting_signature' => 0,
            'approved' => 0,
        ];
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
        DocumentGenerationTemplateVersion $version,
        EmployeeDirectoryFilters $filters,
        string $generationFilter = 'all',
    ): array {
        $employeeIds = self::filteredEmployeeQuery($companyId, $version, $filters, $generationFilter)
            ->orderBy('name')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        $documentIds = DocumentInstance::query()
            ->where('company_id', $companyId)
            ->where('document_generation_template_version_id', $version->id)
            ->whereIn('employee_id', $employeeIds)
            ->withLibraryDocument()
            ->orderByDesc('id')
            ->get(['id', 'employee_id', 'employee_document_id'])
            ->unique('employee_id')
            ->pluck('employee_document_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        return [
            'employee_ids' => $employeeIds,
            'document_ids' => $documentIds,
            'total' => count($employeeIds),
        ];
    }

    public static function paginate(
        int $companyId,
        DocumentGenerationTemplate $template,
        DocumentGenerationTemplateVersion $version,
        EmployeeDirectoryFilters $filters,
        int $perPage,
        string $generationFilter = 'all',
        ?int $generationRunId = null,
    ): LengthAwarePaginator {
        $paginator = self::filteredEmployeeQuery($companyId, $version, $filters, $generationFilter)
            ->with([
                'department:id,name',
                'position:id,title',
            ])
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        $employeeIdList = $paginator->getCollection()->pluck('id')->all();

        $instancesByEmployee = DocumentInstance::query()
            ->where('company_id', $companyId)
            ->where('document_generation_template_version_id', $version->id)
            ->whereIn('employee_id', $employeeIdList)
            ->withLibraryDocument()
            ->with('employeeDocument')
            ->orderByDesc('id')
            ->get()
            ->unique('employee_id')
            ->keyBy('employee_id');

        $runItemsByEmployee = self::runItemsByEmployee(
            $companyId,
            $employeeIdList,
            $generationRunId,
        );

        return $paginator->through(function (Employee $employee) use ($instancesByEmployee, $runItemsByEmployee): array {
            /** @var DocumentInstance|null $instance */
            $instance = $instancesByEmployee->get($employee->id);
            $doc = $instance?->employeeDocument;
            $runItem = $runItemsByEmployee->get($employee->id);
            $runStatus = is_string($runItem?->status) ? $runItem->status : null;
            $errorCode = is_string($runItem?->error_code) ? $runItem->error_code : null;

            return [
                ...BulkDocumentRosterEmployeePresenter::identity($employee),
                'document' => $doc !== null ? [
                    'id' => $doc->id,
                    'created_at' => $instance?->generated_at?->toIso8601String(),
                ] : null,
                'email_sent_at' => null,
                'signature_status' => null,
                'signature_request' => null,
                'generation_run_status' => $runStatus,
                'generation_error' => $runStatus === 'failed' && $errorCode !== null
                    ? [
                        'code' => $errorCode,
                        'message' => DocumentGenerationItemErrorPresenter::userMessage($errorCode, $runItem?->error_message),
                    ]
                    : null,
            ];
        });
    }

    /**
     * @return Builder<Employee>
     */
    private static function filteredEmployeeQuery(
        int $companyId,
        DocumentGenerationTemplateVersion $version,
        EmployeeDirectoryFilters $filters,
        string $generationFilter,
    ): Builder {
        $query = BulkDocumentRosterQuery::employeeQuery($companyId, $filters);

        if ($generationFilter === 'missing') {
            $query->whereDoesntHave('documentInstances', function (Builder $instanceQuery) use ($companyId, $version): void {
                $instanceQuery->where('company_id', $companyId)
                    ->where('document_generation_template_version_id', $version->id)
                    ->withLibraryDocument();
            });
        } elseif ($generationFilter === 'generated') {
            $query->whereHas('documentInstances', function (Builder $instanceQuery) use ($companyId, $version): void {
                $instanceQuery->where('company_id', $companyId)
                    ->where('document_generation_template_version_id', $version->id)
                    ->withLibraryDocument();
            });
        }

        return $query;
    }

    /**
     * @param  list<int>  $employeeIds
     * @return Collection<int, DocumentGenerationRunItem>
     */
    private static function runItemsByEmployee(int $companyId, array $employeeIds, ?int $generationRunId): Collection
    {
        if ($generationRunId === null || $employeeIds === []) {
            return collect();
        }

        return DocumentGenerationRunItem::query()
            ->where('company_id', $companyId)
            ->where('document_generation_run_id', $generationRunId)
            ->whereIn('employee_id', $employeeIds)
            ->orderByDesc('id')
            ->get(['id', 'employee_id', 'status', 'error_code', 'error_message'])
            ->unique('employee_id')
            ->keyBy('employee_id');
    }
}
