<?php

namespace App\Http\Controllers\Organization\Recruitment;

use App\Actions\Recruitment\ReopenRequirementAction;
use App\Enums\Recruitment\RequirementStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Recruitment\ReopenRequirementRequest;
use App\Models\RecruitmentRequirement;
use Illuminate\Http\RedirectResponse;

class RequirementReopenController extends Controller
{
    public function __invoke(
        ReopenRequirementRequest $request,
        RecruitmentRequirement $requirement,
        ReopenRequirementAction $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $requirement->company_id === $companyId, 404);

        $reopened = $action->execute(
            $requirement,
            (int) $request->user()->id,
            $request->validated('reason'),
            $request->validated('new_required_by_date'),
        );

        $message = $reopened->status === RequirementStatus::Draft
            ? "Requirement {$reopened->requirement_number} reopened and returned to draft for review and submission."
            : "Requirement {$reopened->requirement_number} reopened.";

        return redirect()->back()->with('success', $message);
    }
}
