<?php

namespace App\Http\Controllers\Organization;

use App\Exceptions\CrewMovementException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\UpdateCrewAccommodationStayRequest;
use App\Models\CrewAccommodationStay;
use App\Models\CrewAssignment;
use App\Support\CrewAccommodation\UpdateCrewAccommodationStay;
use App\Support\CrewMovements\CrewAssignmentAccess;
use Illuminate\Http\RedirectResponse;

class CrewAccommodationStayController extends Controller
{
    public function __construct(
        private readonly UpdateCrewAccommodationStay $updateStay,
    ) {}

    public function update(
        UpdateCrewAccommodationStayRequest $request,
        CrewAssignment $assignment,
        CrewAccommodationStay $stay,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        CrewAssignmentAccess::assertInCompany($assignment, $companyId, $request->user());

        abort_unless((int) $stay->company_id === $companyId, 404);
        abort_unless((int) $stay->crew_assignment_id === (int) $assignment->id, 404);

        try {
            $this->updateStay->handle(
                $assignment,
                $stay,
                $request->user(),
                $companyId,
                $request->validated(),
            );
        } catch (CrewMovementException $exception) {
            return back()->withErrors([
                'accommodation' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('organization.crew-assignments.show', $assignment)
            ->with('success', 'Accommodation record updated successfully.');
    }
}
