<?php

namespace App\Http\Controllers;

use App\Http\Requests\Search\GlobalSearchRequest;
use App\Support\Search\GlobalSearchQuery;
use Illuminate\Http\JsonResponse;

class GlobalSearchController extends Controller
{
    public function __invoke(GlobalSearchRequest $request, GlobalSearchQuery $search): JsonResponse
    {
        $companyId = $request->attributes->get('current_company_id');

        return response()->json($search->search(
            $request->user(),
            is_numeric($companyId) ? (int) $companyId : null,
            $request->queryString(),
        ));
    }
}
