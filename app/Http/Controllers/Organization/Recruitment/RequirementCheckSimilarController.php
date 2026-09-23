<?php

namespace App\Http\Controllers\Organization\Recruitment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Recruitment\CheckSimilarRequirementRequest;
use App\Models\RecruitmentRequirement;
use App\Support\Recruitment\DuplicateRequirementDetector;
use App\Support\Recruitment\DuplicateRequirementDto;
use Illuminate\Http\JsonResponse;

class RequirementCheckSimilarController extends Controller
{
    public function __invoke(CheckSimilarRequirementRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        $positionIds = array_map('intval', $request->validated('position_ids'));
        $similar = DuplicateRequirementDetector::findSimilar(
            $companyId,
            (int) $request->validated('client_id'),
            $request->validated('project_id') ? (int) $request->validated('project_id') : null,
            $positionIds,
            $request->validated('exclude_id') ? (int) $request->validated('exclude_id') : null,
        );

        $duplicates = $similar->map(fn (RecruitmentRequirement $r): array => DuplicateRequirementDto::fromRequirement($r, $positionIds))->all();

        return response()->json([
            'has_duplicates' => $similar->isNotEmpty(),
            'duplicates' => $duplicates,
            'similar' => $duplicates,
        ]);
    }
}
