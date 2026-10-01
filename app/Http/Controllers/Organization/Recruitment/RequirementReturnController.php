<?php

namespace App\Http\Controllers\Organization\Recruitment;

use App\Actions\Recruitment\ReturnRequirementAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Recruitment\ReturnRequirementRequest;
use App\Models\RecruitmentRequirement;
use Illuminate\Http\RedirectResponse;

class RequirementReturnController extends Controller
{
    public function __invoke(
        ReturnRequirementRequest $request,
        RecruitmentRequirement $requirement,
        ReturnRequirementAction $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $requirement->company_id === $companyId, 404);

        $action->execute(
            $requirement,
            $request->user(),
            (string) $request->validated('return_reason'),
        );

        return redirect()->back()->with(
            'success',
            "Requirement {$requirement->requirement_number} returned for changes.",
        );
    }
}
