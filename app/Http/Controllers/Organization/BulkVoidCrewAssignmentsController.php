<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\BulkVoidCrewAssignmentsRequest;
use App\Support\CrewMovements\Actions\BulkVoidCrewAssignments;
use Illuminate\Http\RedirectResponse;

class BulkVoidCrewAssignmentsController extends Controller
{
    public function __invoke(
        BulkVoidCrewAssignmentsRequest $request,
        BulkVoidCrewAssignments $bulkVoid,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $user = $request->user();
        abort_unless($user !== null, 403);

        /** @var list<int> $assignmentIds */
        $assignmentIds = $request->validated('assignment_ids');

        $voided = $bulkVoid->handle(
            $companyId,
            $assignmentIds,
            $user,
            (string) $request->validated('void_reason'),
            $request->boolean('delete_sea_service'),
            $request->boolean('delete_training'),
        );

        $count = $voided->count();
        $message = $count === 1
            ? 'Erroneous assignment voided and removed from active operational use.'
            : "{$count} erroneous assignments voided and removed from active operational use.";

        return redirect()
            ->route('organization.crew-assignments.index')
            ->with('success', $message);
    }
}
