<?php

namespace App\Http\Controllers\Organization\Recruitment;

use App\Actions\Recruitment\CancelRequirementAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Recruitment\CancelRequirementRequest;
use App\Models\RecruitmentRequirement;
use Illuminate\Http\RedirectResponse;

class RequirementCancelController extends Controller
{
    public function __invoke(
        CancelRequirementRequest $request,
        RecruitmentRequirement $requirement,
        CancelRequirementAction $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $requirement->company_id === $companyId, 404);

        $action->execute(
            $requirement,
            (int) $request->user()->id,
            $request->validated('reason'),
        );

        return redirect()->back()->with('success', "Requirement {$requirement->requirement_number} cancelled.");
    }
}
