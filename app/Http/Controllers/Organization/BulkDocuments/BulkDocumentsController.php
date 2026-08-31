<?php

namespace App\Http\Controllers\Organization\BulkDocuments;

use App\Enums\DocumentGenerationTemplateStatus;
use App\Http\Controllers\Controller;
use App\Models\BulkDocumentEmailBatch;
use App\Models\BulkDocumentGenerationRun;
use App\Models\BulkDocumentSignatureRepairRun;
use App\Models\Company;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Support\BulkDocuments\BulkDocumentActivityQuery;
use App\Support\BulkDocuments\BulkDocumentPagePermissions;
use App\Support\BulkDocuments\BulkDocumentRosterQuery;
use App\Support\BulkDocuments\BulkDocumentSignatureRosterQuery;
use App\Support\BulkDocuments\BulkDocumentTypeRegistry;
use App\Support\BulkDocuments\CustomDocumentRosterQuery;
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
        $documentTypeKey = (string) $request->query('document_type_key', 'salary_declaration');

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

        $isCustom = str_starts_with($documentTypeKey, 'custom_');
        $customTemplate = null;
        $customVersion = null;

        if ($isCustom) {
            $customTemplateId = (int) substr($documentTypeKey, strlen('custom_'));
            $customTemplate = $customTemplates->firstWhere('id', $customTemplateId);
            $customVersion = $customTemplate?->publishedVersion;

            if ($customTemplate === null || $customVersion === null) {
                $documentTypeKey = 'salary_declaration';
                $isCustom = false;
            }
        } else {
            try {
                BulkDocumentTypeRegistry::find($documentTypeKey);
            } catch (\InvalidArgumentException) {
                $documentTypeKey = 'salary_declaration';
            }
        }

        $filters = $this->resolveFilters($request);
        $perPage = $this->resolvePerPage($request);
        $page = max(1, (int) $request->query('page', 1));
        $generationFilter = match ($request->query('generation_filter')) {
            'missing' => 'missing',
            'generated' => 'generated',
            default => 'all',
        };
        $signatureFilter = match ($request->query('signature_filter')) {
            'submitted', 'pending_review' => 'submitted',
            'awaiting_signature' => 'awaiting_signature',
            'approved' => 'approved',
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
            $paginator = CustomDocumentRosterQuery::paginate(
                $companyId,
                $customTemplate,
                $customVersion,
                $filters,
                $perPage,
                $generationFilter,
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
                'signature_filter' => 'all',
                'email_filter' => 'all',
                'signature_requests' => [],
            ]);
        }

        if ($view === 'history') {
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
                'signature_filter' => $signatureFilter,
                'email_filter' => $emailFilter,
                'signature_requests' => [],
            ]);
        }

        if ($view === 'signatures') {
            $signaturesPaginator = BulkDocumentSignatureRosterQuery::paginate(
                $companyId,
                $documentTypeKey,
                $filters,
                $perPage,
                $page,
                $signatureFilter === 'all' ? null : $signatureFilter,
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
                'view' => 'signatures',
                'signature_requests' => $signaturesPaginator->items(),
                'activity' => [],
                'employees' => [],
                'counts' => BulkDocumentRosterQuery::counts($companyId, $documentTypeKey, $filters, null, $emailFilter),
                'pagination' => $this->paginationMeta($signaturesPaginator),
                'generation_filter' => $generationFilter,
                'signature_filter' => $signatureFilter,
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
            'signature_filter' => $signatureFilter,
            'email_filter' => $emailFilter,
            'signature_requests' => [],
        ]);
    }

    /**
     * @param  Collection<int, DocumentGenerationTemplate>  $customTemplates
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
    ): array {
        $systemOptions = BulkDocumentTypeRegistry::options()->map(fn (array $def): array => [
            'value' => $def['value'],
            'label' => $def['label'],
            'category' => 'System Templates',
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

        $documentTypeOptions = array_merge($systemOptions, $customOptions);
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
            'email_template' => $isCustom ? null : $this->emailTemplatePayload($documentTypeKey),
            'reminder_email_template' => $isCustom ? null : $this->emailTemplatePayload($documentTypeKey, 'reminder'),
            'latest_run' => $isCustom ? null : $this->latestRunPayload($companyId, $documentTypeKey),
            'latest_email_batch' => $isCustom ? null : $this->latestEmailBatchPayload($companyId, $documentTypeKey),
            'latest_signature_repair_run' => $isCustom ? null : $this->latestSignatureRepairRunPayload($companyId, $documentTypeKey),
            'can' => BulkDocumentPagePermissions::for($request->user()),
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
    private function latestSignatureRepairRunPayload(int $companyId, string $documentTypeKey): ?array
    {
        $run = BulkDocumentSignatureRepairRun::query()
            ->where('company_id', $companyId)
            ->where('document_type_key', $documentTypeKey)
            ->latest('id')
            ->with('initiatedBy:id,name')
            ->first();

        if ($run === null) {
            return null;
        }

        return [
            'id' => $run->id,
            'status' => $run->status,
            'document_type_key' => $run->document_type_key,
            'total_count' => $run->total_count,
            'repaired_count' => $run->repaired_count,
            'skipped_count' => $run->skipped_count,
            'failed_count' => $run->failed_count,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'initiated_by' => $run->initiatedBy?->name,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestRunPayload(int $companyId, string $documentTypeKey): ?array
    {
        $run = BulkDocumentGenerationRun::query()
            ->where('company_id', $companyId)
            ->where('document_type_key', $documentTypeKey)
            ->latest('id')
            ->with('triggeredBy:id,name')
            ->first();

        if ($run === null) {
            return null;
        }

        return [
            'id' => $run->id,
            'status' => $run->status,
            'document_type_key' => $run->document_type_key,
            'total_targeted' => $run->total_targeted,
            'generated_count' => $run->generated_count,
            'replaced_count' => $run->replaced_count,
            'skipped_count' => $run->skipped_count,
            'failed_count' => $run->failed_count,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'triggered_by' => $run->triggeredBy?->name,
        ];
    }
}
