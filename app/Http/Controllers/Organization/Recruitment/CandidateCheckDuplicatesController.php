<?php

namespace App\Http\Controllers\Organization\Recruitment;

use App\Http\Controllers\Controller;
use App\Support\Recruitment\Candidates\CandidateDuplicateDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CandidateCheckDuplicatesController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless($request->user()?->can('recruitment.candidates.view'), 403);

        $validated = $request->validate([
            'email' => ['nullable', 'email', 'max:200'],
            'phone' => ['nullable', 'string', 'max:50'],
            'ignore_candidate_id' => ['nullable', 'integer'],
        ]);

        $duplicates = CandidateDuplicateDetector::find(
            $companyId,
            $validated['email'] ?? null,
            $validated['phone'] ?? null,
            isset($validated['ignore_candidate_id']) ? (int) $validated['ignore_candidate_id'] : null,
        );

        return response()->json([
            'duplicates' => $duplicates,
            'has_duplicates' => $duplicates !== [],
        ]);
    }
}
