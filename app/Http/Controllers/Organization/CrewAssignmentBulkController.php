<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreBulkCrewAssignmentRequest;
use App\Models\CrewAssignment;
use App\Support\CrewMovements\Actions\BulkStartCrewAssignments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CrewAssignmentBulkController extends Controller
{
    public function create(Request $request): RedirectResponse
    {
        Gate::authorize('start', CrewAssignment::class);

        return redirect()->route('organization.crew-assignments.create', [
            'mode' => 'bulk',
        ]);
    }

    public function store(
        StoreBulkCrewAssignmentRequest $request,
        BulkStartCrewAssignments $bulkStart,
    ): RedirectResponse {
        Gate::authorize('start', CrewAssignment::class);

        $companyId = (int) $request->attributes->get('current_company_id');
        $validated = $request->validated();

        $assignments = $bulkStart->handle(
            $companyId,
            $validated,
            $request->user()?->id,
        );

        $count = count($assignments);
        $success = $count === 1
            ? '1 crew assignment started successfully.'
            : "{$count} crew assignments started successfully.";

        return redirect()
            ->route('organization.crew-assignments.index')
            ->with('success', $success);
    }
}
