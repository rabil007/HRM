<?php

namespace App\Http\Controllers\Organization\Documents;

use App\Http\Controllers\Controller;
use App\Support\Documents\Journey\DocumentJourneyPresenter;
use App\Support\Documents\Journey\DocumentJourneyQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentJourneyController extends Controller
{
    public function __invoke(
        Request $request,
        DocumentJourneyQuery $journeyQuery,
        DocumentJourneyPresenter $presenter,
    ): JsonResponse {
        $user = $request->user();
        abort_unless(
            $user->can('bulk_documents.view') || $user->can('documents.view'),
            403,
        );

        $companyId = (int) $request->attributes->get('current_company_id');

        $identifiers = [
            'document_instance_id' => $request->query('document_instance_id'),
            'employee_document_id' => $request->query('employee_document_id'),
            'employee_id' => $request->query('employee_id'),
            'version_id' => $request->query('version_id'),
            'document_type_key' => $request->query('document_type_key'),
            'generation_run_id' => $request->query('generation_run_id'),
        ];

        $journeyData = $journeyQuery->resolve($companyId, $identifiers, $user);

        if ($journeyData === null) {
            abort(404, 'Document journey could not be resolved for the requested entity.');
        }

        return response()->json(
            $presenter->present($journeyData, $user),
        );
    }
}
