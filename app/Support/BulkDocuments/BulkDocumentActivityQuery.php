<?php

namespace App\Support\BulkDocuments;

use App\Models\BulkDocumentEmailBatch;
use App\Models\BulkDocumentGenerationRun;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeDirectoryQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;

final class BulkDocumentActivityQuery
{
    public static function paginate(
        int $companyId,
        string $documentTypeKey,
        EmployeeDirectoryFilters $filters,
        int $perPage,
        int $page,
    ): LengthAwarePaginator {
        $items = self::items($companyId, $documentTypeKey, $filters);
        $total = $items->count();
        $page = max(1, $page);

        $slice = $items
            ->slice(($page - 1) * $perPage, $perPage)
            ->values()
            ->all();

        return new Paginator(
            $slice,
            $total,
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'query' => request()->query(),
            ],
        );
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private static function items(
        int $companyId,
        string $documentTypeKey,
        EmployeeDirectoryFilters $filters,
    ): Collection {
        $hasEmployeeFilters = self::hasEmployeeFilters($filters);

        $runs = BulkDocumentGenerationRun::query()
            ->where('company_id', $companyId)
            ->where('document_type_key', $documentTypeKey)
            ->with('triggeredBy:id,name')
            ->get()
            ->when(
                $hasEmployeeFilters,
                fn (Collection $collection): Collection => $collection->filter(
                    fn (BulkDocumentGenerationRun $run): bool => self::runMatchesEmployeeFilters(
                        is_array($run->filters) ? $run->filters : [],
                        $filters,
                    ),
                ),
            )
            ->map(fn (BulkDocumentGenerationRun $run): array => [
                'kind' => 'generation',
                'id' => $run->id,
                'document_type_key' => $run->document_type_key,
                'document_type_label' => self::labelForKey($run->document_type_key),
                'status' => $run->status,
                'generated_count' => $run->generated_count,
                'replaced_count' => $run->replaced_count,
                'skipped_count' => $run->skipped_count,
                'failed_count' => $run->failed_count,
                'created_at' => $run->created_at?->toIso8601String(),
                'triggered_by' => $run->triggeredBy?->name,
            ]);

        $batchesQuery = BulkDocumentEmailBatch::query()
            ->where('company_id', $companyId)
            ->where('document_type_key', $documentTypeKey)
            ->with(['triggeredBy:id,name', 'emailTemplate:id,label']);

        if ($hasEmployeeFilters) {
            $batchesQuery->whereHas('sends', function ($sendQuery) use ($companyId, $filters): void {
                $sendQuery->whereHas('employee', function ($employeeQuery) use ($companyId, $filters): void {
                    EmployeeDirectoryQuery::applyAttributeFilters($employeeQuery, $companyId, $filters);
                });
            });
        }

        $batches = $batchesQuery
            ->get()
            ->map(fn (BulkDocumentEmailBatch $batch): array => [
                'kind' => 'email',
                'id' => $batch->id,
                'document_type_key' => $batch->document_type_key,
                'document_type_label' => self::labelForKey($batch->document_type_key),
                'template_label' => $batch->emailTemplate?->label,
                'sent_count' => $batch->sent_count,
                'failed_count' => $batch->failed_count,
                'skipped_no_email_count' => $batch->skipped_no_email_count,
                'created_at' => $batch->created_at?->toIso8601String(),
                'triggered_by' => $batch->triggeredBy?->name,
            ]);

        return $runs
            ->concat($batches)
            ->sortByDesc(fn (array $item): string => (string) ($item['created_at'] ?? ''))
            ->values();
    }

    private static function hasEmployeeFilters(EmployeeDirectoryFilters $filters): bool
    {
        return $filters->search !== ''
            || $filters->departmentId !== ''
            || $filters->positionId !== ''
            || $filters->companyVisaTypeId !== '';
    }

    /**
     * @param  array<string, mixed>  $runFilters
     */
    private static function runMatchesEmployeeFilters(
        array $runFilters,
        EmployeeDirectoryFilters $filters,
    ): bool {
        if ($filters->search !== '') {
            $runSearch = strtolower((string) ($runFilters['search'] ?? ''));

            if ($runSearch !== '' && ! str_contains($runSearch, strtolower($filters->search))) {
                return false;
            }
        }

        if ($filters->departmentId !== '') {
            $runDepartmentId = (string) ($runFilters['department_id'] ?? '');

            if ($runDepartmentId !== '' && $runDepartmentId !== $filters->departmentId) {
                return false;
            }
        }

        if ($filters->positionId !== '') {
            $runPositionId = (string) ($runFilters['position_id'] ?? '');

            if ($runPositionId !== '' && $runPositionId !== $filters->positionId) {
                return false;
            }
        }

        if ($filters->companyVisaTypeId !== '') {
            $runSponsorId = (string) ($runFilters['company_visa_type_id'] ?? '');

            if ($runSponsorId !== '' && $runSponsorId !== $filters->companyVisaTypeId) {
                return false;
            }
        }

        return true;
    }

    private static function labelForKey(string $key): string
    {
        try {
            return BulkDocumentTypeRegistry::find($key)['label'];
        } catch (\InvalidArgumentException) {
            return $key;
        }
    }
}
