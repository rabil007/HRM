<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\CrewPlanning\StoreCrewPlanningAssignmentRequest;
use App\Http\Requests\Organization\CrewPlanning\UpdateCrewPlanningAssignmentRequest;
use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CrewPlanningAssignmentController extends Controller
{
    public function store(StoreCrewPlanningAssignmentRequest $request): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $validated = $request->validated();

        DB::transaction(function () use ($companyId, $validated): void {
            $relievesId = $validated['relieves_crew_assignment_id'] ?? null;

            if ($relievesId !== null && $relievesId !== '') {
                CrewAssignment::query()
                    ->where('company_id', $companyId)
                    ->whereKey((int) $relievesId)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            CrewPlanningAssignment::query()->create([
                ...$validated,
                'company_id' => $companyId,
            ]);
        });

        return back()->with('success', 'Assignment created.');
    }

    public function update(UpdateCrewPlanningAssignmentRequest $request, CrewPlanningAssignment $assignment): RedirectResponse
    {
        abort_if($assignment->company_id !== (int) $request->attributes->get('current_company_id'), 404);

        if ($assignment->crew_assignment_id !== null) {
            throw ValidationException::withMessages([
                'error' => 'This planning bar is controlled by Crew Assignments. Update the linked crew assignment instead.',
            ]);
        }

        $companyId = (int) $request->attributes->get('current_company_id');
        $validated = $request->validated();

        DB::transaction(function () use ($assignment, $companyId, $validated): void {
            $relievesId = array_key_exists('relieves_crew_assignment_id', $validated)
                ? $validated['relieves_crew_assignment_id']
                : $assignment->relieves_crew_assignment_id;

            if ($relievesId !== null && $relievesId !== '') {
                CrewAssignment::query()
                    ->where('company_id', $companyId)
                    ->whereKey((int) $relievesId)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $assignment->update($validated);
        });

        return back()->with('success', 'Assignment updated.');
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
