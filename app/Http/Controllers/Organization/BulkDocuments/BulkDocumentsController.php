<?php

namespace App\Http\Controllers\Organization\BulkDocuments;

use App\Http\Controllers\Controller;
use App\Models\BulkDocumentEmailBatch;
use App\Models\BulkDocumentGenerationRun;
use App\Models\BulkDocumentSignatureRepairRun;
use App\Models\Company;
use App\Support\BulkDocuments\BulkDocumentActivityQuery;
use App\Support\BulkDocuments\BulkDocumentPagePermissions;
use App\Support\BulkDocuments\BulkDocumentRosterQuery;
use App\Support\BulkDocuments\BulkDocumentSignatureRosterQuery;
use App\Support\BulkDocuments\BulkDocumentTypeRegistry;
use App\Support\Employees\BuildDepartmentEmployeeTree;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeFormOptions;
use App\Support\Pagination\ResolvesPerPage;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BulkDocumentsController extends Controller
{
    use ResolvesPerPage;

    public function __invoke(Request $request)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $documentTypeKey = (string) $request->query('document_type_key', 'salary_declaration');

        try {
            BulkDocumentTypeRegistry::find($documentTypeKey);
        } catch (\InvalidArgumentException) {
            $documentTypeKey = 'salary_declaration';
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
            default => 'all',
        };
        $emailFilter = match ($request->query('email_filter')) {
            'emailed' => 'emailed',
            'not_emailed' => 'not_emailed',
            default => 'all',
        };
        $view = match ($request->query('view')) {
            'history' => 'history',
            'signatures' => 'signatures',
            default => 'roster',
        };
        $formOptions = EmployeeFormOptions::for($companyId);

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
     * @return array<string, mixed>
     */
    private function sharedPayload(
        Request $request,
        int $companyId,
        string $documentTypeKey,
        EmployeeDirectoryFilters $filters,
        array $formOptions,
    ): array {
        return [
            'document_type_key' => $documentTypeKey,
            'document_type_options' => BulkDocumentTypeRegistry::options()->all(),
            'filters' => $this->filtersPayload($filters),
            'search' => $filters->search,
            'departments' => $formOptions['departments'],
            'positions' => $formOptions['positions'],
            'company_visa_types' => $formOptions['company_visa_types'],
            'department_tree' => BuildDepartmentEmployeeTree::for($companyId, $filters),
            'department_tree_selected_id' => $filters->departmentId !== '' ? (int) $filters->departmentId : null,
            'department_tree_selected_position_id' => $filters->positionId !== '' ? (int) $filters->positionId : null,
            'company_name' => (string) Company::query()->whereKey($companyId)->value('name'),
            'email_template' => $this->emailTemplatePayload($documentTypeKey),
            'latest_run' => $this->latestRunPayload($companyId, $documentTypeKey),
            'latest_email_batch' => $this->latestEmailBatchPayload($companyId, $documentTypeKey),
            'latest_signature_repair_run' => $this->latestSignatureRepairRunPayload($companyId, $documentTypeKey),
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
    private function emailTemplatePayload(string $documentTypeKey): ?array
    {
        $template = BulkDocumentTypeRegistry::resolveEmailTemplate($documentTypeKey);

        if ($template === null) {
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
