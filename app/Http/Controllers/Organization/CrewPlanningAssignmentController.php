<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\CrewPlanning\StoreCrewPlanningAssignmentRequest;
use App\Http\Requests\Organization\CrewPlanning\UpdateCrewPlanningAssignmentRequest;
use App\Models\CrewPlanningAssignment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CrewPlanningAssignmentController extends Controller
{
    public function store(StoreCrewPlanningAssignmentRequest $request): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        CrewPlanningAssignment::query()->create([
            ...$request->validated(),
            'company_id' => $companyId,
        ]);

        return back()->with('success', 'Assignment created.');
    }

    public function update(UpdateCrewPlanningAssignmentRequest $request, CrewPlanningAssignment $assignment): RedirectResponse
    {
        abort_if($assignment->company_id !== (int) $request->attributes->get('current_company_id'), 404);

        $assignment->update($request->validated());

        return back()->with('success', 'Assignment updated.');
    }

    public function destroy(Request $request, CrewPlanningAssignment $assignment): RedirectResponse
    {
        abort_if($assignment->company_id !== (int) $request->attributes->get('current_company_id'), 404);

        $assignment->delete();

        return back()->with('success', 'Assignment removed.');
    }
}
