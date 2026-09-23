<?php

namespace App\Http\Controllers\Organization\Recruitment;

use App\Actions\Recruitment\ReopenRequirementAction;
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

        $action->execute(
            $requirement,
            (int) $request->user()->id,
            $request->validated('reason'),
            $request->validated('new_required_by_date'),
        );

        return redirect()->back()->with('success', "Requirement {$requirement->requirement_number} reopened.");
    }
}
