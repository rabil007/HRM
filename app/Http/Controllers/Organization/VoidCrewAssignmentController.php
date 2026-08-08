<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\VoidCrewAssignmentRequest;
use App\Models\CrewAssignment;
use App\Support\CrewMovements\Actions\VoidCrewAssignment;
use App\Support\CrewMovements\CrewAssignmentAccess;
use Illuminate\Http\RedirectResponse;

class VoidCrewAssignmentController extends Controller
{
    public function __invoke(
        VoidCrewAssignmentRequest $request,
        CrewAssignment $assignment,
        VoidCrewAssignment $voidAssignment,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        CrewAssignmentAccess::assertInCompany($assignment, $companyId);

        $user = $request->user();
        abort_unless($user !== null, 403);

        $voidAssignment->handle(
            $companyId,
            (int) $assignment->id,
            $user,
            (string) $request->validated('void_reason'),
        );

        return redirect()
            ->route('organization.crew-assignments.index')
            ->with('success', 'Erroneous assignment voided and removed from active operational use.');
    }
}
