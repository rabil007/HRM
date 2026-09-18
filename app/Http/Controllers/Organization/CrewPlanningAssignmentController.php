<?php

namespace App\Http\Controllers\Organization;

use App\Exceptions\CrewMovementException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\CrewPlanning\StartCrewAssignmentFromPlanningRequest;
use App\Http\Requests\Organization\CrewPlanning\StoreCrewPlanningAssignmentRequest;
use App\Http\Requests\Organization\CrewPlanning\UpdateCrewPlanningAssignmentRequest;
use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Support\CrewPlanning\CrewPlanningAssignmentAccess;
use App\Support\CrewPlanning\SaveCrewPlanningAssignment;
use App\Support\CrewPlanning\StartCrewAssignmentFromPlanning;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CrewPlanningAssignmentController extends Controller
{
    public function store(
        StoreCrewPlanningAssignmentRequest $request,
        SaveCrewPlanningAssignment $save,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        $save->create($companyId, $request->validated(), $request->user());

        return back()->with('success', 'Assignment created.');
    }

    public function update(
        UpdateCrewPlanningAssignmentRequest $request,
        CrewPlanningAssignment $assignment,
        SaveCrewPlanningAssignment $save,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        CrewPlanningAssignmentAccess::assertInCompany($assignment, $companyId, $request->user());

        if ($assignment->crew_assignment_id !== null) {
            throw ValidationException::withMessages([
                'error' => 'This planning bar is controlled by Crew Assignments. Update the linked crew assignment instead.',
            ]);
        }

        $save->update($assignment, $companyId, $request->validated(), $request->user());

        return back()->with('success', 'Assignment updated.');
    }

    public function createCrewAssignment(
        Request $request,
        CrewPlanningAssignment $assignment,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        CrewPlanningAssignmentAccess::assertInCompany($assignment, $companyId, $request->user());

        if (! $request->user()?->can('crew_operations.planning.view')
            || ! $request->user()->can('crew_operations.assignments.create')
            || ! $request->user()->can('crew_operations.movements.perform')) {
            abort(403);
        }

        return redirect()->route('organization.crew-assignments.create', array_filter([
            'planning_assignment_id' => $assignment->id,
            'view' => $request->query('view'),
            'vessel_id' => $request->query('vessel_id'),
            'rank_id' => $request->query('rank_id'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'search' => $request->query('search'),
        ], fn ($value) => $value !== null && $value !== ''));
    }

    public function startAssignment(
        StartCrewAssignmentFromPlanningRequest $request,
        CrewPlanningAssignment $assignment,
        StartCrewAssignmentFromPlanning $startFromPlanning,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        CrewPlanningAssignmentAccess::assertInCompany($assignment, $companyId, $request->user());

        Gate::authorize('start', CrewAssignment::class);

        try {
            $result = $startFromPlanning->handle(
                $assignment,
                $request->validated(),
                $request->user(),
            );
        } catch (CrewMovementException $exception) {
            throw ValidationException::withMessages([
                'error' => $exception->getMessage(),
            ]);
        }

        $started = $result['assignment'];
        $success = $result['created_new']
            ? 'Crew assignment started from planning.'
            : 'This planning record is already linked to an active crew assignment.';

        if (Gate::allows('view', $started)) {
            return redirect()
                ->route('organization.crew-assignments.show', $started)
                ->with('success', $success);
        }

        return redirect()
            ->route('dashboard')
            ->with('success', $success);
    }

    public function destroy(Request $request, CrewPlanningAssignment $assignment): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        CrewPlanningAssignmentAccess::assertInCompany($assignment, $companyId, $request->user());

        if ($assignment->crew_assignment_id !== null) {
            throw ValidationException::withMessages([
                'error' => 'This planning bar is controlled by Crew Assignments. Cancel or update the linked crew assignment instead.',
            ]);
        }

        $assignment->delete();

        return back()->with('success', 'Assignment removed.');
    }
}
