<?php

namespace App\Http\Controllers\Organization\Recruitment;

use App\Actions\Recruitment\RejectHeadcountRevisionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Recruitment\RejectHeadcountRevisionRequest;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementHeadcountRevision;
use Illuminate\Http\RedirectResponse;

class RequirementHeadcountRevisionRejectController extends Controller
{
    public function __invoke(
        RejectHeadcountRevisionRequest $request,
        RecruitmentRequirement $requirement,
        RecruitmentRequirementHeadcountRevision $headcount_revision,
        RejectHeadcountRevisionAction $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $requirement->company_id === $companyId, 404);
        abort_unless((int) $headcount_revision->company_id === $companyId, 404);
        abort_unless((int) $headcount_revision->recruitment_requirement_id === (int) $requirement->id, 404);

        $action->execute(
            $requirement,
            $headcount_revision,
            $request->user(),
            $request->validated('decision_note'),
        );

        return redirect()->back()->with('success', 'Headcount revision rejected.');
    }
}
