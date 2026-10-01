<?php

namespace App\Http\Controllers\Organization\Recruitment;

use App\Actions\Recruitment\SubmitRequirementForApprovalAction;
use App\Http\Controllers\Controller;
use App\Models\RecruitmentRequirement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RequirementSubmitController extends Controller
{
    public function __invoke(
        Request $request,
        RecruitmentRequirement $requirement,
        SubmitRequirementForApprovalAction $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $requirement->company_id === $companyId, 404);

        $action->execute($requirement, $request->user());

        return redirect()->back()->with(
            'success',
            "Requirement {$requirement->requirement_number} submitted for approval.",
        );
    }
}
