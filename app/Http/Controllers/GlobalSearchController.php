<?php

namespace App\Http\Controllers;

use App\Support\Search\GlobalSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GlobalSearchController extends Controller
{
    public function __invoke(Request $request, GlobalSearch $search): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless($companyId > 0, 403);

        return response()->json([
            'results' => $search->search($user, $companyId, $validated['q']),
        ]);
    }
}
