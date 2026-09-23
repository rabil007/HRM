<?php

namespace App\Http\Controllers\Organization\Recruitment;

use App\Actions\Recruitment\AddHeadcountToRequirementAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Recruitment\AddHeadcountDuplicateRequest;
use App\Models\RecruitmentRequirement;
use Illuminate\Http\RedirectResponse;

class RequirementAddHeadcountController extends Controller
{
    public function __invoke(
        AddHeadcountDuplicateRequest $request,
        RecruitmentRequirement $requirement,
        AddHeadcountToRequirementAction $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $requirement->company_id === $companyId, 404);

        $action->execute(
            (int) $requirement->id,
            $companyId,
            (int) $request->user()->id,
            $request->validated('lines'),
            $request->validated('reason'),
        );

        return redirect()->route('organization.recruitment.requirements.show', $requirement)
            ->with('success', "Added headcount to {$requirement->requirement_number} successfully.");
    }
}
