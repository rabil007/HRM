<?php

namespace App\Http\Controllers\Organization\Documents;

use App\Http\Controllers\Controller;
use App\Models\BulkDocumentEmailBatch;
use App\Models\BulkDocumentGenerationRun;
use App\Models\BulkDocumentSignatureRepairRun;
use App\Models\Company;
use App\Support\BulkDocuments\BulkDocumentPagePermissions;
use App\Support\BulkDocuments\BulkDocumentRosterQuery;
use App\Support\BulkDocuments\BulkDocumentSignatureRosterQuery;
use App\Support\BulkDocuments\BulkDocumentTypeRegistry;
use App\Support\Documents\Workflow\DocumentWorkflowPagePermissions;
use App\Support\Documents\Workflow\DocumentWorkflowPresenter;
use App\Support\Documents\Workflow\DocumentWorkflowPresetPagePermissions;
use App\Support\Documents\Workflow\DocumentWorkflowRosterQuery;
use App\Support\Employees\BuildDepartmentEmployeeTree;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeFormOptions;
use App\Support\Pagination\ResolvesPerPage;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DocumentRequestsIndexController extends Controller
{
    use ResolvesPerPage;

    public function __invoke(
        Request $request,
        DocumentWorkflowRosterQuery $rosterQuery,
        DocumentWorkflowPresenter $presenter,
    ) {
        $companyId = (int) $request->attributes->get('current_company_id');
        $workflowPermissions = DocumentWorkflowPagePermissions::for($request->user());

        abort_unless(
            $workflowPermissions['view'] || $workflowPermissions['view_signatures'],
            403,
        );

        $requestedTab = $request->query('tab');
        $tab = match ($requestedTab) {
            'signatures' => 'signatures',
            'review' => 'review',
            default => $workflowPermissions['view'] ? 'review' : 'signatures',
        };

        if ($tab === 'review') {
            abort_unless($workflowPermissions['view'], 403);
        } else {
            abort_unless($workflowPermissions['view_signatures'], 403);
        }

        $perPage = $this->resolvePerPage($request);
        $page = max(1, (int) $request->query('page', 1));

        if ($tab === 'review') {

            $filters = [
                'search' => trim((string) $request->query('search', '')),
                'status' => trim((string) $request->query('status', '')),
                'action' => trim((string) $request->query('action', '')),
                'assigned_to_me' => $request->boolean('assigned_to_me'),
            ];

            $paginator = $rosterQuery->paginate(
                $companyId,
                $filters,
                $perPage,
                $page,
                $request->user(),
            );

            return Inertia::render('organization/documents/requests/index', [
                'tab' => 'review',
                'can' => $workflowPermissions,
                'preset_can' => DocumentWorkflowPresetPagePermissions::for($request->user()),
                'filters' => $filters,
                'search' => $filters['search'],
                'workflow_requests' => collect($paginator->items())
                    ->map(fn ($item) => $presenter->listItem($item))
                    ->values()
                    ->all(),
                'pagination' => $this->paginationMeta($paginator),
                'signature_payload' => null,
            ]);
        }

        abort_unless($workflowPermissions['view_signatures'], 403);

        $documentTypeKey = (string) $request->query('document_type_key', 'salary_declaration');
        try {
            BulkDocumentTypeRegistry::find($documentTypeKey);
        } catch (\InvalidArgumentException) {
            $documentTypeKey = 'salary_declaration';
        }

        $filters = $this->resolveFilters($request);
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

        $signaturesPaginator = BulkDocumentSignatureRosterQuery::paginate(
            $companyId,
            $documentTypeKey,
            $filters,
            $perPage,
            $page,
            $signatureFilter === 'all' ? null : $signatureFilter,
            $emailFilter,
        );

        $formOptions = EmployeeFormOptions::for($companyId);

        return Inertia::render('organization/documents/requests/index', [
            'tab' => 'signatures',
            'can' => $workflowPermissions,
            'preset_can' => DocumentWorkflowPresetPagePermissions::for($request->user()),
            'filters' => $this->filtersPayload($filters),
            'search' => $filters->search,
            'workflow_requests' => [],
            'pagination' => $this->paginationMeta($signaturesPaginator),
            'signature_payload' => [
                'document_type_key' => $documentTypeKey,
                'document_type_options' => BulkDocumentTypeRegistry::options()->map(fn (array $def): array => [
                    'value' => $def['value'],
                    'label' => $def['label'],
                ])->values()->all(),
                'signature_requests' => $signaturesPaginator->items(),
                'signature_filter' => $signatureFilter,
                'email_filter' => $emailFilter,
                'counts' => BulkDocumentRosterQuery::counts($companyId, $documentTypeKey, $filters, null, $emailFilter),
                'departments' => $formOptions['departments'],
                'positions' => $formOptions['positions'],
                'company_visa_types' => $formOptions['company_visa_types'],
                'department_tree' => BuildDepartmentEmployeeTree::for($companyId, $filters),
                'department_tree_selected_id' => $filters->departmentId !== '' ? (int) $filters->departmentId : null,
                'department_tree_selected_position_id' => $filters->positionId !== '' ? (int) $filters->positionId : null,
                'company_name' => (string) Company::query()->whereKey($companyId)->value('name'),
                'latest_run' => $this->latestRunPayload($companyId, $documentTypeKey),
                'latest_email_batch' => $this->latestEmailBatchPayload($companyId, $documentTypeKey),
                'latest_signature_repair_run' => $this->latestSignatureRepairRunPayload($companyId, $documentTypeKey),
                'can' => BulkDocumentPagePermissions::for($request->user()),
            ],
        ]);
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
