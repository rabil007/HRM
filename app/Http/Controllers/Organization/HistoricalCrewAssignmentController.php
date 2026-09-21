<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\PreviewHistoricalCrewAssignmentRequest;
use App\Http\Requests\Organization\StoreHistoricalCrewAssignmentRequest;
use App\Models\CrewAssignment;
use App\Support\CrewMovements\Historical\HistoricalCrewAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class HistoricalCrewAssignmentController extends Controller
{
    public function __construct(
        private readonly HistoricalCrewAssignmentService $historicalService,
    ) {}

    public function preview(PreviewHistoricalCrewAssignmentRequest $request): JsonResponse
    {
        Gate::authorize('createHistorical', CrewAssignment::class);

        $data = $request->toData();

        $preview = $this->historicalService->preview($data, $request->user());

        return response()->json($preview->toArray());
    }

    public function store(StoreHistoricalCrewAssignmentRequest $request): RedirectResponse
    {
        Gate::authorize('createHistorical', CrewAssignment::class);

        $data = $request->toData();

        $assignment = $this->historicalService->create(
            data: $data,
            actorId: $request->user()?->id,
        );

        return redirect()
            ->route('organization.crew-assignments.index')
            ->with('success', "Historical assignment {$assignment->assignment_no} recorded successfully.");
    }
}
