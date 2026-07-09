<?php

namespace App\Http\Controllers\Organization\BulkDocuments;

use App\Http\Controllers\Controller;
use App\Models\BulkDocumentEmailBatch;
use App\Support\BulkDocuments\BulkDocumentEmailBatchSendsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BulkDocumentEmailBatchSendsController extends Controller
{
    public function __invoke(Request $request, BulkDocumentEmailBatch $batch): JsonResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        if ($batch->company_id !== $companyId) {
            abort(404);
        }

        return response()->json(
            BulkDocumentEmailBatchSendsQuery::forBatch($batch),
        );
    }
}
