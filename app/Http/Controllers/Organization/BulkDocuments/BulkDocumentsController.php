<?php

namespace App\Http\Controllers\Organization\BulkDocuments;

use App\Enums\EmailTemplateCategory;
use App\Http\Controllers\Controller;
use App\Models\BulkDocumentEmailBatch;
use App\Models\BulkDocumentGenerationRun;
use App\Models\EmailTemplate;
use App\Support\BulkDocuments\BulkDocumentPagePermissions;
use App\Support\BulkDocuments\BulkDocumentRosterQuery;
use App\Support\BulkDocuments\BulkDocumentTypeRegistry;
use App\Support\Employees\BuildDepartmentEmployeeTree;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeFormOptions;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BulkDocumentsController extends Controller
{
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
        $roster = BulkDocumentRosterQuery::for($companyId, $documentTypeKey, $filters);
        $formOptions = EmployeeFormOptions::for($companyId);

        $generationFilter = $request->query('generation_filter');
        if ($generationFilter === 'missing') {
            $roster['employees'] = array_values(array_filter(
                $roster['employees'],
                fn (array $employee): bool => $employee['document'] === null,
            ));
        }

        return Inertia::render('organization/documents/bulk/index', [
            'document_type_key' => $documentTypeKey,
            'document_type_options' => BulkDocumentTypeRegistry::options()->all(),
            'filters' => $this->filtersPayload($filters),
            'search' => $filters->search,
            'counts' => $roster['counts'],
            'employees' => $roster['employees'],
            'generation_filter' => $generationFilter === 'missing' ? 'missing' : 'all',
            'departments' => $formOptions['departments'],
            'positions' => $formOptions['positions'],
            'company_visa_types' => $formOptions['company_visa_types'],
            'department_tree' => BuildDepartmentEmployeeTree::for($companyId, $filters),
            'department_tree_selected_id' => $filters->departmentId !== '' ? (int) $filters->departmentId : null,
            'department_tree_selected_position_id' => $filters->positionId !== '' ? (int) $filters->positionId : null,
            'email_templates' => EmailTemplate::query()
                ->enabled()
                ->whereIn('category', [EmailTemplateCategory::Document, EmailTemplateCategory::Payroll])
                ->orderByDesc('is_default')
                ->orderBy('sort_order')
                ->orderBy('label')
                ->get(['id', 'slug', 'label', 'subject', 'body_html', 'is_default'])
                ->map(fn (EmailTemplate $template) => [
                    'id' => $template->id,
                    'slug' => $template->slug,
                    'label' => $template->label,
                    'subject' => $template->subject,
                    'body_html' => $template->body_html,
                    'is_default' => $template->is_default,
                ])
                ->values()
                ->all(),
            'latest_run' => $this->latestRunPayload($companyId, $documentTypeKey),
            'recent_activity' => $this->recentActivity($companyId),
            'can' => BulkDocumentPagePermissions::for($request->user()),
        ]);
    }

    private function resolveFilters(Request $request): EmployeeDirectoryFilters
    {
        $filters = EmployeeDirectoryFilters::fromRequest($request);

        if ($filters->status === '') {
            return EmployeeDirectoryFilters::fromArray(array_merge(
                $filters->toQueryArray(),
                ['status' => 'active'],
            ));
        }

        return $filters;
    }

    /**
     * @return array<string, string>
     */
    private function filtersPayload(EmployeeDirectoryFilters $filters): array
    {
        return [
            'department_id' => $filters->departmentId,
            'position_id' => $filters->positionId,
            'status' => $filters->status !== '' ? $filters->status : 'active',
            'company_visa_type_id' => $filters->companyVisaTypeId,
            'search' => $filters->search,
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

    /**
     * @return list<array<string, mixed>>
     */
    private function recentActivity(int $companyId): array
    {
        $runs = BulkDocumentGenerationRun::query()
            ->where('company_id', $companyId)
            ->latest('id')
            ->limit(10)
            ->with('triggeredBy:id,name')
            ->get()
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

        $batches = BulkDocumentEmailBatch::query()
            ->where('company_id', $companyId)
            ->latest('id')
            ->limit(10)
            ->with(['triggeredBy:id,name', 'emailTemplate:id,label'])
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
            ->sortByDesc('created_at')
            ->take(10)
            ->values()
            ->all();
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
