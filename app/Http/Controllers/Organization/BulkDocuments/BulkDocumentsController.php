<?php

namespace App\Http\Controllers\Organization\BulkDocuments;

use App\Enums\DocumentGenerationTemplateStatus;
use App\Http\Controllers\Controller;
use App\Models\BulkDocumentEmailBatch;
use App\Models\Company;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Support\BulkDocuments\BulkDocumentActivityQuery;
use App\Support\BulkDocuments\BulkDocumentPagePermissions;
use App\Support\BulkDocuments\BulkDocumentRosterQuery;
use App\Support\BulkDocuments\BulkDocumentTypeRegistry;
use App\Support\BulkDocuments\CustomDocumentRosterQuery;
use App\Support\BulkDocuments\DocumentGenerationProgressQuery;
use App\Support\Documents\DocumentsModuleAccess;
use App\Support\Employees\BuildDepartmentEmployeeTree;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeFormOptions;
use App\Support\Pagination\ResolvesPerPage;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;

class BulkDocumentsController extends Controller
{
    use ResolvesPerPage;

    public function __invoke(Request $request)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $requestedTypeKey = trim((string) $request->query('document_type_key', ''));

        $customTemplates = DocumentGenerationTemplate::query()
            ->forCompany($companyId)
            ->where('status', DocumentGenerationTemplateStatus::Active)
            ->whereNotNull('published_version_id')
            ->with('publishedVersion')
            ->get()
            ->filter(function (DocumentGenerationTemplate $template): bool {
                $version = $template->publishedVersion;

                if ($version === null) {
                    return false;
                }

                if ($template->isContent()) {
                    return true;
                }

                if ($template->isPdfOverlay()) {
                    return is_string($version->source_pdf_path)
                        && $version->source_pdf_path !== ''
                        && (int) $version->source_pdf_page_count >= 1;
                }

                return false;
            })
            ->values();

        $resolvedType = $this->resolveGenerationType($requestedTypeKey, $customTemplates);
        $documentTypeKey = $resolvedType['key'];
        $isCustom = $resolvedType['custom'] !== null;
        $customTemplate = $resolvedType['custom'];
        $customVersion = $resolvedType['version'];

        $filters = $this->resolveFilters($request);
        $perPage = $this->resolvePerPage($request);
        $page = max(1, (int) $request->query('page', 1));
        $generationFilter = match ($request->query('generation_filter')) {
            'missing' => 'missing',
            'generated' => 'generated',
            default => 'all',
        };
        $emailFilter = match ($request->query('email_filter')) {
            'emailed' => 'emailed',
            'not_emailed' => 'not_emailed',
            default => 'all',
        };
        $view = $isCustom ? 'roster' : DocumentsModuleAccess::resolveBulkView($request);
        $moduleViewLocked = $request->route('module_view') !== null;
        $formOptions = EmployeeFormOptions::for($companyId);

        if ($isCustom && $customTemplate !== null && $customVersion !== null) {
            $latestRun = $this->latestRunPayload($request, $companyId, $documentTypeKey, $customTemplate, $customVersion);
            $paginator = CustomDocumentRosterQuery::paginate(
                $companyId,
                $customTemplate,
                $customVersion,
                $filters,
                $perPage,
                $generationFilter,
                isset($latestRun['id']) ? (int) $latestRun['id'] : null,
            );

            return Inertia::render('organization/documents/bulk/index', $this->sharedPayload(
                $request,
                $companyId,
                $documentTypeKey,
                $filters,
                $formOptions,
                $customTemplates,
                $moduleViewLocked,
                $customTemplate,
                $customVersion,
                $latestRun,
            ) + [
                'view' => 'roster',
                'activity' => [],
                'counts' => CustomDocumentRosterQuery::counts(
                    $companyId,
                    $customTemplate,
                    $customVersion,
                    $filters,
                ),
                'employees' => $paginator->items(),
                'pagination' => $this->paginationMeta($paginator),
                'generation_filter' => $generationFilter,
                'email_filter' => 'all',
            ]);
        }

        if ($view === 'history') {
            if ($documentTypeKey === '') {
                return Inertia::render('organization/documents/bulk/index', $this->sharedPayload(
                    $request,
                    $companyId,
                    $documentTypeKey,
                    $filters,
                    $formOptions,
                    $customTemplates,
                    $moduleViewLocked,
                ) + [
                    'view' => 'history',
                    'activity' => [],
                    'employees' => [],
                    'counts' => [
                        'total' => 0,
                        'generated' => 0,
                        'missing' => 0,
                        'emailed' => 0,
                        'not_emailed' => 0,
                    ],
                    'pagination' => $this->emptyPagination($perPage),
                    'generation_filter' => $generationFilter,
                    'email_filter' => $emailFilter,
                ]);
            }
            $activityPaginator = BulkDocumentActivityQuery::paginate(
                $companyId,
                $documentTypeKey,
                $filters,
                $perPage,
                $page,
            );

            return Inertia::render('organization/documents/bulk/index', $this->sharedPayload(
                $request,
                $companyId,
                $documentTypeKey,
                $filters,
                $formOptions,
                $customTemplates,
                $moduleViewLocked,
            ) + [
                'view' => 'history',
                'activity' => $activityPaginator->items(),
                'employees' => [],
                'counts' => BulkDocumentRosterQuery::counts($companyId, $documentTypeKey, $filters, null, $emailFilter),
                'pagination' => $this->paginationMeta($activityPaginator),
                'generation_filter' => $generationFilter,
                'email_filter' => $emailFilter,
            ]);
        }

        if ($documentTypeKey === '') {
            return Inertia::render('organization/documents/bulk/index', $this->sharedPayload(
                $request,
                $companyId,
                $documentTypeKey,
                $filters,
                $formOptions,
                $customTemplates,
                $moduleViewLocked,
            ) + [
                'view' => 'roster',
                'activity' => [],
                'employees' => [],
                'counts' => [
                    'total' => 0,
                    'generated' => 0,
                    'missing' => 0,
                    'emailed' => 0,
                    'not_emailed' => 0,
                ],
                'pagination' => $this->emptyPagination($perPage),
                'generation_filter' => $generationFilter,
                'email_filter' => $emailFilter,
            ]);
        }

        $paginator = BulkDocumentRosterQuery::paginate(
            $companyId,
            $documentTypeKey,
            $filters,
            $perPage,
            $generationFilter,
            $emailFilter,
        );

        return Inertia::render('organization/documents/bulk/index', $this->sharedPayload(
            $request,
            $companyId,
            $documentTypeKey,
            $filters,
            $formOptions,
            $customTemplates,
            $moduleViewLocked,
        ) + [
            'view' => 'roster',
            'activity' => [],
            'counts' => BulkDocumentRosterQuery::counts($companyId, $documentTypeKey, $filters, null, $emailFilter),
            'employees' => $paginator->items(),
            'pagination' => $this->paginationMeta($paginator),
            'generation_filter' => $generationFilter,
            'email_filter' => $emailFilter,
        ]);
    }

    /**
     * @param  Collection<int, DocumentGenerationTemplate>  $customTemplates
     * @param  array<string, mixed>|false|null  $latestRunPayload  `false` computes the current user's run; `null`/`array` uses the given payload.
     * @return array<string, mixed>
     */
    private function sharedPayload(
        Request $request,
        int $companyId,
        string $documentTypeKey,
        EmployeeDirectoryFilters $filters,
        array $formOptions,
        Collection $customTemplates,
        bool $moduleViewLocked = false,
        ?DocumentGenerationTemplate $customTemplate = null,
        ?DocumentGenerationTemplateVersion $customVersion = null,
        array|false|null $latestRunPayload = false,
    ): array {
        $systemOptions = BulkDocumentTypeRegistry::availableGenerationOptions()->map(fn (array $def): array => [
            'value' => $def['value'],
            'label' => $def['label'],
            'category' => 'Built-in Documents',
            'is_custom' => false,
        ])->all();

        $customOptions = $customTemplates->map(fn (DocumentGenerationTemplate $t): array => [
            'value' => "custom_{$t->id}",
            'label' => $t->name.' (v'.$t->publishedVersion?->version.')',
            'category' => 'Company Templates',
            'is_custom' => true,
            'template_id' => $t->id,
            'template_format' => $t->template_format->value,
        ])->values()->all();

        $documentTypeOptions = array_merge($customOptions, $systemOptions);
        $isCustom = $customTemplate !== null;

        return [
            'document_type_key' => $documentTypeKey,
            'document_type_options' => $documentTypeOptions,
            'is_custom_template' => $isCustom,
            'custom_template' => $isCustom ? [
                'id' => $customTemplate->id,
                'name' => $customTemplate->name,
                'version' => $customVersion?->version,
                'template_format' => $customTemplate->template_format->value,
            ] : null,
            'module_view_locked' => $moduleViewLocked,
            'filters' => $this->filtersPayload($filters),
            'search' => $filters->search,
            'departments' => $formOptions['departments'],
            'positions' => $formOptions['positions'],
            'company_visa_types' => $formOptions['company_visa_types'],
            'department_tree' => BuildDepartmentEmployeeTree::for($companyId, $filters),
            'department_tree_selected_id' => $filters->departmentId !== '' ? (int) $filters->departmentId : null,
            'department_tree_selected_position_id' => $filters->positionId !== '' ? (int) $filters->positionId : null,
            'company_name' => (string) Company::query()->whereKey($companyId)->value('name'),
            'email_template' => $isCustom || $documentTypeKey === '' ? null : $this->emailTemplatePayload($documentTypeKey),
            'reminder_email_template' => $isCustom || $documentTypeKey === '' ? null : $this->emailTemplatePayload($documentTypeKey, 'reminder'),
            'latest_run' => $latestRunPayload === false
                ? $this->latestRunPayload($request, $companyId, $documentTypeKey, $customTemplate, $customVersion)
                : $latestRunPayload,
            'latest_email_batch' => $isCustom || $documentTypeKey === '' ? null : $this->latestEmailBatchPayload($companyId, $documentTypeKey),
            'can' => BulkDocumentPagePermissions::for($request->user()),
            'can_view_templates' => DocumentsModuleAccess::canViewTemplates($request->user()),
        ];
    }

    /**
     * @param  Collection<int, DocumentGenerationTemplate>  $customTemplates
     * @return array{key: string, custom: ?DocumentGenerationTemplate, version: ?DocumentGenerationTemplateVersion}
     */
    private function resolveGenerationType(string $requestedTypeKey, Collection $customTemplates): array
    {
        if (str_starts_with($requestedTypeKey, 'custom_')) {
            $customTemplateId = (int) substr($requestedTypeKey, strlen('custom_'));
            $customTemplate = $customTemplates->firstWhere('id', $customTemplateId);
            $customVersion = $customTemplate?->publishedVersion;

            if ($customTemplate !== null && $customVersion !== null) {
                return [
                    'key' => $requestedTypeKey,
                    'custom' => $customTemplate,
                    'version' => $customVersion,
                ];
            }
        } elseif ($requestedTypeKey !== '' && BulkDocumentTypeRegistry::availableForNewGeneration($requestedTypeKey)) {
            return [
                'key' => $requestedTypeKey,
                'custom' => null,
                'version' => null,
            ];
        }

        $firstCustom = $customTemplates->first();

        if ($firstCustom !== null && $firstCustom->publishedVersion !== null) {
            return [
                'key' => "custom_{$firstCustom->id}",
                'custom' => $firstCustom,
                'version' => $firstCustom->publishedVersion,
            ];
        }

        $firstBuiltIn = BulkDocumentTypeRegistry::availableGenerationDefinitions()[0] ?? null;

        if ($firstBuiltIn !== null) {
            return [
                'key' => $firstBuiltIn['key'],
                'custom' => null,
                'version' => null,
            ];
        }

        return [
            'key' => '',
            'custom' => null,
            'version' => null,
        ];
    }

    /**
     * @return array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}
     */
    private function emptyPagination(int $perPage): array
    {
        return [
            'current_page' => 1,
            'last_page' => 1,
            'per_page' => $perPage,
            'total' => 0,
            'from' => null,
            'to' => null,
        ];
    }

    private function resolveFilters(Request $request): EmployeeDirectoryFilters
    {
        $filters = EmployeeDirectoryFilters::fromRequest($request);

        return EmployeeDirectoryFilters::fromArray(array_merge(
            $filters->toQueryArray(),
            ['status' => 'active'],
        ));
    }

    /**
     * @return array<string, string>
     */
    private function filtersPayload(EmployeeDirectoryFilters $filters): array
    {
        return [
            'department_id' => $filters->departmentId,
            'position_id' => $filters->positionId,
            'status' => 'active',
            'company_visa_type_id' => $filters->companyVisaTypeId,
            'search' => $filters->search,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function emailTemplatePayload(string $documentTypeKey, string $intent = 'initial'): ?array
    {
        $definition = BulkDocumentTypeRegistry::find($documentTypeKey);

        if ($intent === 'reminder' && ($definition['reminder_email_template_slug'] ?? null) === null) {
            return null;
        }

        $template = BulkDocumentTypeRegistry::resolveEmailTemplate($documentTypeKey, $intent);

        if ($template === null) {
            return null;
        }

        if ($intent === 'reminder' && $template->slug !== $definition['reminder_email_template_slug']) {
            return null;
        }

        return [
            'id' => $template->id,
            'slug' => $template->slug,
            'label' => $template->label,
            'subject' => $template->subject,
            'body_html' => $template->body_html,
            'to_preset' => $template->to_preset,
            'cc_preset' => $template->cc_preset,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestEmailBatchPayload(int $companyId, string $documentTypeKey): ?array
    {
        $batch = BulkDocumentEmailBatch::query()
            ->where('company_id', $companyId)
            ->where('document_type_key', $documentTypeKey)
            ->latest('id')
            ->with('triggeredBy:id,name')
            ->first();

        if ($batch === null) {
            return null;
        }

        return [
            'id' => $batch->id,
            'status' => $batch->status,
            'total_selected' => $batch->total_selected,
            'sent_count' => $batch->sent_count,
            'failed_count' => $batch->failed_count,
            'skipped_no_email_count' => $batch->skipped_no_email_count,
            'started_at' => $batch->started_at?->toIso8601String(),
            'finished_at' => $batch->finished_at?->toIso8601String(),
            'triggered_by' => $batch->triggeredBy?->name,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestRunPayload(
        Request $request,
        int $companyId,
        string $documentTypeKey,
        ?DocumentGenerationTemplate $customTemplate,
        ?DocumentGenerationTemplateVersion $customVersion,
    ): ?array {
        $userId = (int) $request->user()?->id;
        $query = app(DocumentGenerationProgressQuery::class);

        if ($customTemplate !== null) {
            return $query->forCurrentUserCustomTemplate($companyId, $userId, $customTemplate, $customVersion);
        }

        if ($documentTypeKey === '') {
            return null;
        }

        return $query->forBuiltIn($companyId, $documentTypeKey);
    }
}
