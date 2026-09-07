<?php

namespace App\Support\BulkDocuments;

use App\Models\BulkDocumentSignatureRequest;
use App\Models\DocumentGenerationRunItem;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\DocumentInstance;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\Documents\Process\DocumentOperationalProcessPresenter;
use App\Support\Employees\EmployeeDirectoryFilters;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class CustomDocumentRosterQuery
{
    /**
     * @param  list<int>|null  $employeeIds
     * @return array{
     *     targeted: int,
     *     generated: int,
     *     not_generated: int,
     *     pending_review: int,
     *     awaiting_signature: int,
     *     approved: int,
     *     all: int,
     *     not_started: int,
     *     in_progress: int,
     *     needs_attention: int,
     *     completed: int,
     * }
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
        $historicalIds = self::historicalCompletedEmployeeIds($companyId, $template);

        $generated = (clone $query)->whereHas('documentInstances', function (Builder $instanceQuery) use ($companyId, $version): void {
            self::constrainCurrentLibraryInstance($instanceQuery, $companyId, $version);
        })->count();

        $inProgress = (clone $query)->whereHas('documentInstances', function (Builder $instanceQuery) use ($companyId, $version): void {
            $instanceQuery->where('company_id', $companyId)
                ->where('document_generation_template_version_id', $version->id)
                ->whereHas('lifecycleAutomation', function (Builder $lifecycleQuery): void {
                    $lifecycleQuery->whereIn('status', ['active', 'pending']);
                });
        })->count();

        $needsAttention = (clone $query)->whereHas('documentInstances', function (Builder $instanceQuery) use ($companyId, $version): void {
            $instanceQuery->where('company_id', $companyId)
                ->where('document_generation_template_version_id', $version->id)
                ->whereHas('lifecycleAutomation', function (Builder $lifecycleQuery): void {
                    $lifecycleQuery->where('status', 'blocked');
                });
        })->count();

        $completed = (clone $query)->where(function (Builder $outer) use ($companyId, $version, $historicalIds): void {
            $outer->whereHas('documentInstances', function (Builder $instanceQuery) use ($companyId, $version): void {
                self::constrainCurrentLibraryInstance($instanceQuery, $companyId, $version);
                $instanceQuery->where(function (Builder $q): void {
                    $q->whereHas('lifecycleAutomation', fn ($lq) => $lq->where('status', 'completed'))
                        ->orWhereDoesntHave('lifecycleAutomation');
                });
            });

            if ($historicalIds !== []) {
                $outer->orWhere(function (Builder $fallback) use ($companyId, $version, $historicalIds): void {
                    $fallback->whereIn('id', $historicalIds)
                        ->whereDoesntHave('documentInstances', function (Builder $instanceQuery) use ($companyId, $version): void {
                            self::constrainCurrentLibraryInstance($instanceQuery, $companyId, $version);
                        });
                });
            }
        })->count();

        $notStarted = (clone $query)
            ->whereDoesntHave('documentInstances', function (Builder $instanceQuery) use ($companyId, $version): void {
                self::constrainCurrentLibraryInstance($instanceQuery, $companyId, $version);
            });

        if ($historicalIds !== []) {
            $notStarted->whereNotIn('id', $historicalIds);
        }

        $notStartedCount = $notStarted->count();

        return [
            'targeted' => $targeted,
            'generated' => $generated,
            'not_generated' => $notStartedCount,
            'pending_review' => 0,
            'awaiting_signature' => 0,
            'approved' => 0,
            'all' => $targeted,
            'not_started' => $notStartedCount,
            'in_progress' => $inProgress,
            'needs_attention' => $needsAttention,
            'completed' => $completed,
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
        string $filter = 'all',
    ): array {
        $version->loadMissing('template');

        $employeeIds = self::filteredEmployeeQuery($companyId, $version, $filters, $filter, $version->template)
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
        string $filter = 'all',
        ?int $generationRunId = null,
    ): LengthAwarePaginator {
        $paginator = self::filteredEmployeeQuery($companyId, $version, $filters, $filter, $template)
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
            ->with([
                'employeeDocument',
                'lifecycleAutomation.workflowRequest.stages.tasks',
                'lifecycleAutomation.signingFlow.recipientRequests.deliveries',
                'recipientRequests.deliveries',
            ])
            ->orderByDesc('id')
            ->get()
            ->unique('employee_id')
            ->keyBy('employee_id');

        $runItemsByEmployee = self::runItemsByEmployee(
            $companyId,
            $employeeIdList,
            $generationRunId,
        );

        $historicalByEmployee = self::historicalCompletionsForPage($companyId, $template, $employeeIdList, $instancesByEmployee);
        $historicalDocuments = self::historicalLibraryDocuments($companyId, $historicalByEmployee);

        $processPresenter = app(DocumentOperationalProcessPresenter::class);
        $viewer = auth()->user();

        return $paginator->through(function (Employee $employee) use (
            $instancesByEmployee,
            $runItemsByEmployee,
            $historicalByEmployee,
            $historicalDocuments,
            $processPresenter,
            $viewer,
        ): array {
            /** @var DocumentInstance|null $instance */
            $instance = $instancesByEmployee->get($employee->id);
            $doc = $instance?->employeeDocument;
            $runItem = $runItemsByEmployee->get($employee->id);
            $runStatus = is_string($runItem?->status) ? $runItem->status : null;
            $errorCode = is_string($runItem?->error_code) ? $runItem->error_code : null;
            $historical = $instance === null ? $historicalByEmployee->get($employee->id) : null;

            if ($doc === null && $historical?->employee_document_id !== null) {
                $doc = $historicalDocuments->get((int) $historical->employee_document_id);
            }

            $process = $processPresenter->present(
                employee: $employee,
                instance: $instance,
                employeeDocument: $doc,
                runItem: $runItem,
                copyEmailSentAt: null,
                legacySignatureStatus: null,
                viewer: $viewer,
                historicalCompletion: $historical,
            );

            return [
                ...BulkDocumentRosterEmployeePresenter::identity($employee),
                'document' => $doc !== null ? [
                    'id' => $doc->id,
                    'created_at' => $instance?->generated_at?->toIso8601String()
                        ?? $historical?->signed_at?->toIso8601String(),
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
                'process' => $process,
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
        string $filter,
        ?DocumentGenerationTemplate $template = null,
    ): Builder {
        $query = BulkDocumentRosterQuery::employeeQuery($companyId, $filters);
        $template ??= $version->template;
        $historicalIds = $template !== null
            ? self::historicalCompletedEmployeeIds($companyId, $template)
            : [];

        if ($filter === 'not_started' || $filter === 'missing') {
            $query->whereDoesntHave('documentInstances', function (Builder $instanceQuery) use ($companyId, $version): void {
                self::constrainCurrentLibraryInstance($instanceQuery, $companyId, $version);
            });

            if ($historicalIds !== []) {
                $query->whereNotIn('id', $historicalIds);
            }
        } elseif ($filter === 'in_progress') {
            $query->whereHas('documentInstances', function (Builder $instanceQuery) use ($companyId, $version): void {
                $instanceQuery->where('company_id', $companyId)
                    ->where('document_generation_template_version_id', $version->id)
                    ->whereHas('lifecycleAutomation', function (Builder $lifecycleQuery): void {
                        $lifecycleQuery->whereIn('status', ['active', 'pending']);
                    });
            });
        } elseif ($filter === 'needs_attention') {
            $query->whereHas('documentInstances', function (Builder $instanceQuery) use ($companyId, $version): void {
                $instanceQuery->where('company_id', $companyId)
                    ->where('document_generation_template_version_id', $version->id)
                    ->whereHas('lifecycleAutomation', function (Builder $lifecycleQuery): void {
                        $lifecycleQuery->where('status', 'blocked');
                    });
            });
        } elseif ($filter === 'completed') {
            $query->where(function (Builder $outer) use ($companyId, $version, $historicalIds): void {
                $outer->whereHas('documentInstances', function (Builder $instanceQuery) use ($companyId, $version): void {
                    self::constrainCurrentLibraryInstance($instanceQuery, $companyId, $version);
                    $instanceQuery->where(function (Builder $q): void {
                        $q->whereHas('lifecycleAutomation', fn ($lq) => $lq->where('status', 'completed'))
                            ->orWhereDoesntHave('lifecycleAutomation');
                    });
                });

                if ($historicalIds !== []) {
                    $outer->orWhere(function (Builder $fallback) use ($companyId, $version, $historicalIds): void {
                        $fallback->whereIn('id', $historicalIds)
                            ->whereDoesntHave('documentInstances', function (Builder $instanceQuery) use ($companyId, $version): void {
                                self::constrainCurrentLibraryInstance($instanceQuery, $companyId, $version);
                            });
                    });
                }
            });
        } elseif ($filter === 'generated') {
            $query->whereHas('documentInstances', function (Builder $instanceQuery) use ($companyId, $version): void {
                self::constrainCurrentLibraryInstance($instanceQuery, $companyId, $version);
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

    /**
     * @return list<int>
     */
    private static function historicalCompletedEmployeeIds(int $companyId, DocumentGenerationTemplate $template): array
    {
        $query = self::completions();

        if (! $query->appliesToTemplate($template)) {
            return [];
        }

        return $query->completedEmployeeIds($companyId);
    }

    /**
     * @param  list<int>  $employeeIds
     * @param  Collection<int, DocumentInstance>  $instancesByEmployee
     * @return Collection<int, BulkDocumentSignatureRequest>
     */
    private static function historicalCompletionsForPage(
        int $companyId,
        DocumentGenerationTemplate $template,
        array $employeeIds,
        Collection $instancesByEmployee,
    ): Collection {
        $query = self::completions();

        if (! $query->appliesToTemplate($template) || $employeeIds === []) {
            return collect();
        }

        $withoutCurrentInstance = array_values(array_filter(
            $employeeIds,
            fn (int $id): bool => ! $instancesByEmployee->has($id),
        ));

        return $query->latestCompletionsForEmployees($companyId, $withoutCurrentInstance);
    }

    /**
     * @param  Collection<int, BulkDocumentSignatureRequest>  $historicalByEmployee
     * @return Collection<int, EmployeeDocument>
     */
    private static function historicalLibraryDocuments(int $companyId, Collection $historicalByEmployee): Collection
    {
        $documentIds = $historicalByEmployee
            ->pluck('employee_document_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($documentIds === []) {
            return collect();
        }

        return EmployeeDocument::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $documentIds)
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  Builder<DocumentInstance>  $instanceQuery
     */
    private static function constrainCurrentLibraryInstance(
        Builder $instanceQuery,
        int $companyId,
        DocumentGenerationTemplateVersion $version,
    ): void {
        $instanceQuery->where('company_id', $companyId)
            ->where('document_generation_template_version_id', $version->id)
            ->withLibraryDocument();
    }

    private static function completions(): LegacySalaryDeclarationCompletionQuery
    {
        return once(fn (): LegacySalaryDeclarationCompletionQuery => app(LegacySalaryDeclarationCompletionQuery::class));
    }
}
