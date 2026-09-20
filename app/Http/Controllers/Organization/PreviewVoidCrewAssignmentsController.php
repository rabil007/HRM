<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\PreviewVoidCrewAssignmentsRequest;
use App\Support\CrewMovements\VoidCrewAssignmentImpactResolver;
use Illuminate\Http\JsonResponse;

class PreviewVoidCrewAssignmentsController extends Controller
{
    public function __invoke(
        PreviewVoidCrewAssignmentsRequest $request,
        VoidCrewAssignmentImpactResolver $impactResolver,
    ): JsonResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        /** @var list<int> $assignmentIds */
        $assignmentIds = $request->validated('assignment_ids');

        $impact = $impactResolver->resolve(
            $companyId,
            $assignmentIds,
            $request->user(),
        );

        return response()->json($impact);
    }
}
