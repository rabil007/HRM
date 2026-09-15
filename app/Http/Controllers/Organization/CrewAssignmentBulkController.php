<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreBulkCrewAssignmentRequest;
use App\Models\CrewAssignment;
use App\Support\CrewMovements\Actions\BulkStartCrewAssignments;
use App\Support\CrewMovements\CrewAssignmentCreateFormOptions;
use App\Support\CrewMovements\CrewAssignmentPagePermissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class CrewAssignmentBulkController extends Controller
{
    public function create(Request $request): InertiaResponse
    {
        Gate::authorize('start', CrewAssignment::class);

        $companyId = (int) $request->attributes->get('current_company_id');

        return Inertia::render('organization/crew/bulk-create', [
            'form_options' => CrewAssignmentCreateFormOptions::for($companyId, $request->user()),
            'can' => CrewAssignmentPagePermissions::for($request->user()),
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
