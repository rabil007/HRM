<?php

namespace App\Http\Controllers\Organization;

use App\Exceptions\CrewMovementException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\CrewPlanning\StoreCrewPlanningAssignmentRequest;
use App\Http\Requests\Organization\CrewPlanning\UpdateCrewPlanningAssignmentRequest;
use App\Models\CrewPlanningAssignment;
use App\Support\CrewPlanning\CrewPlanningAssignmentAccess;
use App\Support\CrewPlanning\SaveCrewPlanningAssignment;
use App\Support\CrewPlanning\StartPlanningMobilisation;
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

        // Redirect-only vacant handoff: Draft needs create; Start is authorized on Store.
        if (! $request->user()?->can('crew_operations.planning.view')
            || ! $request->user()->can('crew_operations.assignments.create')) {
            abort(403);
        }

        if ($assignment->employee_id !== null) {
            return redirect()
                ->route('organization.crew-planning.index')
                ->with('error', 'Named planning records must be started using Start Mobilisation.');
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

    public function startMobilisation(
        Request $request,
        CrewPlanningAssignment $assignment,
        StartPlanningMobilisation $startMobilisation,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        CrewPlanningAssignmentAccess::assertInCompany($assignment, $companyId, $request->user());

        if (! $request->user()?->can('crew_operations.planning.view')
            || ! $request->user()->can('crew_operations.assignments.create')
            || ! $request->user()->can('crew_operations.movements.perform')) {
            abort(403);
        }

        try {
            $createdAssignment = $startMobilisation->handle($companyId, $assignment, $request->user());
        } catch (CrewMovementException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (ValidationException $exception) {
            $message = $exception->validator->errors()->first();

            return back()->withErrors($exception->validator)->with('error', $message);
        }

        if (Gate::forUser($request->user())->allows('view', $createdAssignment)) {
            return redirect()
                ->route('organization.crew-assignments.show', $createdAssignment)
                ->with('success', 'Mobilisation started successfully.');
        }

        return redirect()
            ->route('organization.crew-planning.index')
            ->with('success', 'Mobilisation started successfully.');
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
