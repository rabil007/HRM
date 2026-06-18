<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\CrewPlanning\StoreCrewPlanningAssignmentRequest;
use App\Http\Requests\Organization\CrewPlanning\UpdateCrewPlanningAssignmentRequest;
use App\Models\CrewPlanningAssignment;
use App\Support\CrewPlanning\ConfirmCrewPlanningAssignment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CrewPlanningAssignmentController extends Controller
{
    public function store(StoreCrewPlanningAssignmentRequest $request): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        CrewPlanningAssignment::query()->create([
            ...$request->validated(),
            'company_id' => $companyId,
            'status' => 'draft',
        ]);

        return back()->with('success', 'Assignment created.');
    }

    public function update(UpdateCrewPlanningAssignmentRequest $request, CrewPlanningAssignment $assignment): RedirectResponse
    {
        abort_if($assignment->company_id !== (int) $request->attributes->get('current_company_id'), 404);

        if ($assignment->status !== 'draft') {
            throw ValidationException::withMessages([
                'assignment' => 'Only draft assignments can be updated.',
            ]);
        }

        $assignment->update($request->validated());

        return back()->with('success', 'Assignment updated.');
    }

    public function confirm(
        Request $request,
        CrewPlanningAssignment $assignment,
        ConfirmCrewPlanningAssignment $confirmAssignment,
    ): RedirectResponse {
        abort_if($assignment->company_id !== (int) $request->attributes->get('current_company_id'), 404);

        $confirmAssignment->handle($assignment, (int) $request->attributes->get('current_company_id'));

        return back()->with('success', 'Assignment confirmed and deployment created.');
    }

    public function destroy(Request $request, CrewPlanningAssignment $assignment): RedirectResponse
    {
        abort_if($assignment->company_id !== (int) $request->attributes->get('current_company_id'), 404);

        $assignment->delete();

        return back()->with('success', 'Assignment removed.');
    }
}
