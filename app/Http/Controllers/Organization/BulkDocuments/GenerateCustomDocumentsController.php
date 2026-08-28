<?php

namespace App\Http\Controllers\Organization\BulkDocuments;

use App\Enums\DocumentGenerationTemplateFormat;
use App\Enums\DocumentGenerationTemplateStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\BulkDocuments\GenerateCustomDocumentsRequest;
use App\Jobs\GenerateCustomDocumentsJob;
use App\Models\DocumentGenerationRun;
use App\Models\DocumentGenerationRunItem;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\DocumentInstance;
use App\Support\BulkDocuments\BulkDocumentRosterQuery;
use App\Support\Employees\EmployeeDirectoryFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class GenerateCustomDocumentsController extends Controller
{
    public function __invoke(GenerateCustomDocumentsRequest $request): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $userId = (int) $request->user()?->id;
        $templateId = (int) $request->input('document_generation_template_id');

        /** @var DocumentGenerationTemplate|null $template */
        $template = DocumentGenerationTemplate::query()
            ->forCompany($companyId)
            ->where('status', DocumentGenerationTemplateStatus::Active)
            ->find($templateId);

        if ($template === null || $template->published_version_id === null) {
            return back()->withErrors(['document_generation_template_id' => 'The selected custom template is not active or has no published version.']);
        }

        /** @var DocumentGenerationTemplateVersion|null $version */
        $version = DocumentGenerationTemplateVersion::query()
            ->where('company_id', $companyId)
            ->where('document_generation_template_id', $template->id)
            ->where('id', $template->published_version_id)
            ->where('status', 'published')
            ->first();

        if ($version === null) {
            return back()->withErrors(['document_generation_template_id' => 'The published version for this template could not be found.']);
        }

        if (
            $template->template_format === DocumentGenerationTemplateFormat::PdfOverlay
            && ($version->source_pdf_path === null || (int) $version->source_pdf_page_count < 1)
        ) {
            return back()->withErrors(['document_generation_template_id' => 'This PDF Overlay template has no configured source PDF. Please publish a version with a valid source PDF first.']);
        }

        $employeeIds = $request->employeeIds();
        $isExplicitSelection = $employeeIds !== [];

        $filters = $request->filters();
        $filters['status'] = 'active';

        $directoryFilters = EmployeeDirectoryFilters::fromArray($filters);

        // Query active employees matching company and filters
        $employeeQuery = BulkDocumentRosterQuery::employeeQuery(
            $companyId,
            $directoryFilters,
            $isExplicitSelection ? $employeeIds : null,
        );

        if (! $isExplicitSelection) {
            // Filter out employees who already have an instance for this exact version
            $alreadyGeneratedIds = DocumentInstance::query()
                ->forCompany($companyId)
                ->where('document_generation_template_version_id', $version->id)
                ->pluck('employee_id')
                ->filter()
                ->all();

            if ($alreadyGeneratedIds !== []) {
                $employeeQuery->whereNotIn('id', $alreadyGeneratedIds);
            }
        }

        $targetEmployeeIds = $employeeQuery->pluck('id')->all();
        $targetCount = count($targetEmployeeIds);

        if ($targetCount === 0) {
            return back()->with('info', 'No employees need document generation for the current selection.');
        }

        $correlationId = (string) Str::uuid();

        // Create Run and all RunItems in one atomic transaction
        /** @var DocumentGenerationRun $run */
        $run = DB::transaction(function () use (
            $companyId,
            $template,
            $version,
            $filters,
            $targetCount,
            $correlationId,
            $userId,
            $targetEmployeeIds,
        ): DocumentGenerationRun {
            /** @var DocumentGenerationRun $run */
            $run = DocumentGenerationRun::query()->create([
                'company_id' => $companyId,
                'document_generation_template_id' => $template->id,
                'document_generation_template_version_id' => $version->id,
                'filters' => $filters,
                'status' => 'queued',
                'total_targeted' => $targetCount,
                'correlation_id' => $correlationId,
                'triggered_by' => $userId,
            ]);

            $itemsData = array_map(fn (int $empId): array => [
                'company_id' => $companyId,
                'document_generation_run_id' => $run->id,
                'employee_id' => $empId,
                'status' => 'pending',
                'document_instance_id' => null,
                'error_code' => null,
                'error_message' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ], $targetEmployeeIds);

            foreach (array_chunk($itemsData, 500) as $chunk) {
                DocumentGenerationRunItem::query()->insert($chunk);
            }

            return $run;
        });

        // Dispatch after transaction commits; handle dispatch failure safely
        $allowRepeatGeneration = $isExplicitSelection;

        try {
            GenerateCustomDocumentsJob::dispatch(
                $companyId,
                $userId,
                $run->id,
                $allowRepeatGeneration,
            );
        } catch (Throwable $e) {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
            ]);

            Log::error('Failed to dispatch custom document generation job', [
                'company_id' => $companyId,
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors(['document_generation_template_id' => 'Failed to start document generation. Please try again.']);
        }

        return back()->with(
            'success',
            "Generating {$template->name} (v{$version->version}) for {$targetCount} employee(s).",
        );
    }
}
