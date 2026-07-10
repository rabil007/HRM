<?php

namespace App\Http\Controllers\Organization\BulkDocuments;

use App\Http\Controllers\Controller;
use App\Support\BulkDocuments\BulkDocumentRosterQuery;
use App\Support\BulkDocuments\BulkDocumentSignatureRosterQuery;
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

        $emailFilter = match ($request->query('email_filter')) {
            'emailed' => 'emailed',
            'not_emailed' => 'not_emailed',
            default => 'all',
        };

        if ($request->query('view') === 'signatures') {
            $signatureFilter = match ($request->query('signature_filter')) {
                'submitted' => 'submitted',
                'awaiting_signature' => 'awaiting_signature',
                'approved' => 'approved',
                default => 'all',
            };

            return response()->json(
                BulkDocumentSignatureRosterQuery::matchingSelection(
                    $companyId,
                    $documentTypeKey,
                    $filters,
                    $signatureFilter,
                    $emailFilter,
                ),
            );
        }

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
                $emailFilter,
            ),
        );
    }
}
