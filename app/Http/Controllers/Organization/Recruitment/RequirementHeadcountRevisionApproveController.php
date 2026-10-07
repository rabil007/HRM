<?php

namespace App\Http\Controllers\Organization\Recruitment;

use App\Actions\Recruitment\ApproveHeadcountRevisionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Recruitment\ApproveHeadcountRevisionRequest;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementHeadcountRevision;
use Illuminate\Http\RedirectResponse;

class RequirementHeadcountRevisionApproveController extends Controller
{
    public function __invoke(
        ApproveHeadcountRevisionRequest $request,
        RecruitmentRequirement $requirement,
        RecruitmentRequirementHeadcountRevision $headcount_revision,
        ApproveHeadcountRevisionAction $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $requirement->company_id === $companyId, 404);
        abort_unless((int) $headcount_revision->company_id === $companyId, 404);
        abort_unless((int) $headcount_revision->recruitment_requirement_id === (int) $requirement->id, 404);

        $action->execute($requirement, $headcount_revision, $request->user());

        return redirect()->back()->with('success', 'Headcount revision approved.');
    }
}
