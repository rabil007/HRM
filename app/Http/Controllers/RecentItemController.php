<?php

namespace App\Http\Controllers;

use App\Http\Requests\RecentItems\ListRecentItemsRequest;
use App\Support\RecentItems\ListRecentItems;
use Illuminate\Http\JsonResponse;

class RecentItemController extends Controller
{
    public function __invoke(ListRecentItemsRequest $request, ListRecentItems $list): JsonResponse
    {
        $companyId = $request->attributes->get('current_company_id');

        return response()->json($list->handle(
            $request->user(),
            is_numeric($companyId) ? (int) $companyId : null,
        ));
    }
}
