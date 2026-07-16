<?php

namespace App\Http\Controllers\Organization;

use App\Enums\CrewMovementAction;
use App\Exceptions\CrewMovementException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\PerformCrewMovementActionRequest;
use App\Models\CrewAssignment;
use App\Support\CrewMovements\CrewAssignmentAccess;
use App\Support\CrewMovements\CrewMovementService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CrewMovementActionController extends Controller
{
    public function __construct(private CrewMovementService $service) {}

    public function __invoke(PerformCrewMovementActionRequest $request, CrewAssignment $assignment)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        CrewAssignmentAccess::assertInCompany($assignment, $companyId);

        $validated = $request->validated();
        $actionValue = (string) $validated['action'];

        $action = CrewMovementAction::tryFrom($actionValue);
        if ($action === null) {
            throw ValidationException::withMessages(['action' => 'Invalid action.']);
        }

        if ($action === CrewMovementAction::CancelAssignment) {
            Gate::authorize('cancel', $assignment);
        } else {
            Gate::authorize('performMovement', $assignment);
        }

        try {
            $this->service->perform(
                $companyId,
                $assignment->id,
                $action,
                $validated,
                $request->user()?->id,
            );

            return redirect()
                ->route('organization.crew-assignments.show', $assignment)
                ->with('success', sprintf('%s completed successfully.', $action->label()));
        } catch (CrewMovementException $e) {
            throw ValidationException::withMessages(['error' => $e->getMessage()]);
        }
    }
}
