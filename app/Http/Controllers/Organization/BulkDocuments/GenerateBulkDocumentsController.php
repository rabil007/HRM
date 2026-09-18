<?php

namespace App\Http\Controllers\Organization\BulkDocuments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\BulkDocuments\GenerateBulkDocumentsRequest;
use App\Jobs\GenerateBulkDocumentsJob;
use App\Models\BulkDocumentGenerationRun;
use App\Models\User;
use App\Support\BulkDocuments\BulkDocumentRosterQuery;
use App\Support\BulkDocuments\BulkDocumentTypeRegistry;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeVisibilityScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

class GenerateBulkDocumentsController extends Controller
{
    public function store(GenerateBulkDocumentsRequest $request): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $user = $request->user();
        $userId = (int) $user?->id;
        $documentTypeKey = (string) $request->input('document_type_key');
        $requestedEmployeeIds = $request->employeeIds();
        $replaceExisting = $requestedEmployeeIds !== [];

        $filters = $request->filters();
        $filters['status'] = 'active';

        $directoryFilters = EmployeeDirectoryFilters::fromArray($filters);

        $snapshotEmployeeIds = $this->resolveAuthorizedTargetEmployeeIds(
            $companyId,
            $documentTypeKey,
            $directoryFilters,
            $requestedEmployeeIds,
            $replaceExisting,
            $user,
        );

        if ($snapshotEmployeeIds === []) {
            return back()->with('info', 'No employees need document generation for the current selection.');
        }

        BulkDocumentTypeRegistry::find($documentTypeKey);

        $correlationId = (string) Str::uuid();
        $targetCount = count($snapshotEmployeeIds);

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
            $snapshotEmployeeIds,
        );

        $label = BulkDocumentTypeRegistry::find($documentTypeKey)['label'];

        return back()->with(
            'success',
            "Generating {$label} for {$targetCount} employee(s).",
        );
    }

    /**
     * @param  list<int>  $requestedEmployeeIds
     * @return list<int>
     */
    private function resolveAuthorizedTargetEmployeeIds(
        int $companyId,
        string $documentTypeKey,
        EmployeeDirectoryFilters $directoryFilters,
        array $requestedEmployeeIds,
        bool $replaceExisting,
        ?User $user,
    ): array {
        if ($replaceExisting) {
            return EmployeeVisibilityScope::filterAuthorizedEmployeeIds(
                $user,
                $companyId,
                $requestedEmployeeIds,
            );
        }

        $selection = BulkDocumentRosterQuery::matchingSelection(
            $companyId,
            $documentTypeKey,
            $directoryFilters,
            'missing',
            'all',
            $user,
        );

        $authorizedIds = $selection['employee_ids'];

        if ($requestedEmployeeIds !== []) {
            $requested = EmployeeVisibilityScope::filterAuthorizedEmployeeIds(
                $user,
                $companyId,
                $requestedEmployeeIds,
            );

            $authorizedIds = array_values(array_intersect($authorizedIds, $requested));
        }

        return array_values(array_unique(array_map('intval', $authorizedIds)));
    }
}
