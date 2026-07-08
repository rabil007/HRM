<?php

namespace App\Http\Controllers\Organization\BulkDocuments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\BulkDocuments\GenerateBulkDocumentsRequest;
use App\Jobs\GenerateBulkDocumentsJob;
use App\Models\BulkDocumentGenerationRun;
use App\Support\BulkDocuments\BulkDocumentRosterQuery;
use App\Support\BulkDocuments\BulkDocumentTypeRegistry;
use App\Support\Employees\EmployeeDirectoryFilters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

class GenerateBulkDocumentsController extends Controller
{
    public function store(GenerateBulkDocumentsRequest $request): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $userId = (int) $request->user()?->id;
        $documentTypeKey = (string) $request->input('document_type_key');
        $employeeIds = $request->employeeIds();
        $replaceExisting = $employeeIds !== [];

        $filters = $request->filters();
        if (trim((string) ($filters['status'] ?? '')) === '') {
            $filters['status'] = 'active';
        }

        $directoryFilters = EmployeeDirectoryFilters::fromArray($filters);
        $roster = BulkDocumentRosterQuery::for(
            $companyId,
            $documentTypeKey,
            $directoryFilters,
            $employeeIds !== [] ? $employeeIds : null,
        );

        $targetCount = $replaceExisting
            ? count($employeeIds)
            : $roster['counts']['not_generated'];

        if ($targetCount === 0) {
            return back()->with('info', 'No employees need document generation for the current selection.');
        }

        BulkDocumentTypeRegistry::find($documentTypeKey);

        $correlationId = (string) Str::uuid();

        $run = BulkDocumentGenerationRun::query()->create([
            'company_id' => $companyId,
            'document_type_key' => $documentTypeKey,
            'filters' => $filters,
            'status' => 'queued',
            'total_targeted' => $targetCount,
            'correlation_id' => $correlationId,
            'triggered_by' => $userId,
        ]);

        GenerateBulkDocumentsJob::dispatch(
            $companyId,
            $userId,
            $documentTypeKey,
            $filters,
            $run->id,
            $replaceExisting,
            $employeeIds !== [] ? $employeeIds : null,
        );

        $label = BulkDocumentTypeRegistry::find($documentTypeKey)['label'];

        return back()->with(
            'success',
            "Generating {$label} for {$targetCount} employee(s).",
        );
    }
}
