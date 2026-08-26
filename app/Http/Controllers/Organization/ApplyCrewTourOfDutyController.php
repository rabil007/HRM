<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\CrewAssignment;
use App\Support\CrewMovements\ApplyMissingCrewTourOfDuty;
use App\Support\CrewMovements\CrewAssignmentAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ApplyCrewTourOfDutyController extends Controller
{
    public function __invoke(
        Request $request,
        CrewAssignment $assignment,
        ApplyMissingCrewTourOfDuty $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        CrewAssignmentAccess::assertInCompany($assignment, $companyId);

        Gate::authorize('performMovement', $assignment);

        $result = $action->handle(
            $companyId,
            (int) $assignment->id,
            $request->user()?->id,
        );

        if ($result === null) {
            throw ValidationException::withMessages([
                'error' => 'This assignment is not eligible for Tour of Duty application or no Tour of Duty is configured for this rank.',
            ]);
        }

        return redirect()
            ->route('organization.crew-assignments.show', $result)
            ->with('success', 'Tour of Duty applied successfully.');
    }
}
