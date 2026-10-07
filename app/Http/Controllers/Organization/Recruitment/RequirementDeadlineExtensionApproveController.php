<?php

namespace App\Http\Controllers\Organization\Recruitment;

use App\Actions\Recruitment\ApproveDeadlineExtensionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Recruitment\ApproveDeadlineExtensionRequest;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementDeadlineExtension;
use Illuminate\Http\RedirectResponse;

class RequirementDeadlineExtensionApproveController extends Controller
{
    public function __invoke(
        ApproveDeadlineExtensionRequest $request,
        RecruitmentRequirement $requirement,
        RecruitmentRequirementDeadlineExtension $deadline_extension,
        ApproveDeadlineExtensionAction $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $requirement->company_id === $companyId, 404);
        abort_unless((int) $deadline_extension->company_id === $companyId, 404);
        abort_unless((int) $deadline_extension->recruitment_requirement_id === (int) $requirement->id, 404);

        $action->execute($requirement, $deadline_extension, $request->user());

        return redirect()->back()->with('success', 'Deadline extension approved.');
    }
}
