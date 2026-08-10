<?php

namespace App\Http\Controllers\Organization;

use App\Exceptions\CrewMovementException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\CrewPlanning\StoreCrewPlanningAssignmentRequest;
use App\Http\Requests\Organization\CrewPlanning\UpdateCrewPlanningAssignmentRequest;
use App\Models\CrewPlanningAssignment;
use App\Support\CrewPlanning\CreateCrewAssignmentFromPlanning;
use App\Support\CrewPlanning\SaveCrewPlanningAssignment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CrewPlanningAssignmentController extends Controller
{
    public function store(
        StoreCrewPlanningAssignmentRequest $request,
        SaveCrewPlanningAssignment $save,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        $save->create($companyId, $request->validated());

        return back()->with('success', 'Assignment created.');
    }

    public function update(
        UpdateCrewPlanningAssignmentRequest $request,
        CrewPlanningAssignment $assignment,
        SaveCrewPlanningAssignment $save,
    ): RedirectResponse {
        abort_if($assignment->company_id !== (int) $request->attributes->get('current_company_id'), 404);

        if ($assignment->crew_assignment_id !== null) {
            throw ValidationException::withMessages([
                'error' => 'This planning bar is controlled by Crew Assignments. Update the linked crew assignment instead.',
            ]);
        }

        $companyId = (int) $request->attributes->get('current_company_id');

        $save->update($assignment, $companyId, $request->validated());

        return back()->with('success', 'Assignment updated.');
    }

    public function createCrewAssignment(
        Request $request,
        CrewPlanningAssignment $assignment,
        CreateCrewAssignmentFromPlanning $createAssignment,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_if($assignment->company_id !== $companyId, 404);

        if (! $request->user()?->can('crew_operations.assignments.create')) {
            abort(403);
        }

        try {
            $newAssignment = $createAssignment->handle($assignment, (int) $request->user()->id);
        } catch (CrewMovementException $exception) {
            throw ValidationException::withMessages([
                'error' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('organization.crew-assignments.show', $newAssignment)
            ->with('success', 'Crew assignment created from planning.');
    }

    public function destroy(Request $request, CrewPlanningAssignment $assignment): RedirectResponse
    {
        abort_if($assignment->company_id !== (int) $request->attributes->get('current_company_id'), 404);

        if ($assignment->crew_assignment_id !== null) {
            throw ValidationException::withMessages([
                'error' => 'This planning bar is controlled by Crew Assignments. Cancel or update the linked crew assignment instead.',
            ]);
        }

        $assignment->delete();

        return back()->with('success', 'Assignment removed.');
    }
}
