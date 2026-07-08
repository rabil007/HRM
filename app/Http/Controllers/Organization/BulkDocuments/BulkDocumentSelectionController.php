<?php

namespace App\Http\Controllers\Organization\BulkDocuments;

use App\Http\Controllers\Controller;
use App\Support\BulkDocuments\BulkDocumentRosterQuery;
use App\Support\BulkDocuments\BulkDocumentTypeRegistry;
use App\Support\Employees\EmployeeDirectoryFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BulkDocumentSelectionController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $documentTypeKey = (string) $request->query('document_type_key', 'salary_declaration');

        try {
            BulkDocumentTypeRegistry::find($documentTypeKey);
        } catch (\InvalidArgumentException) {
            $documentTypeKey = 'salary_declaration';
        }

        $filters = EmployeeDirectoryFilters::fromRequest($request);

        $filters = EmployeeDirectoryFilters::fromArray(array_merge(
            $filters->toQueryArray(),
            ['status' => 'active'],
        ));

        $generationFilter = match ($request->query('generation_filter')) {
            'missing' => 'missing',
            'generated' => 'generated',
            default => 'all',
        };

        return response()->json(
            BulkDocumentRosterQuery::matchingSelection(
                $companyId,
                $documentTypeKey,
                $filters,
                $generationFilter,
            ),
        );
    }
}
