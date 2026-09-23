<?php

namespace App\Http\Controllers\Organization\Recruitment;

use App\Actions\Recruitment\FillRequirementAction;
use App\Http\Controllers\Controller;
use App\Models\RecruitmentRequirement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RequirementFillController extends Controller
{
    public function __invoke(
        Request $request,
        RecruitmentRequirement $requirement,
        FillRequirementAction $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $requirement->company_id === $companyId, 404);

        $action->execute($requirement, (int) $request->user()->id);

        return redirect()->back()->with('success', "Requirement {$requirement->requirement_number} marked as Filled.");
    }
}
