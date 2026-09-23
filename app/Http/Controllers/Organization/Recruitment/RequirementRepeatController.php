<?php

namespace App\Http\Controllers\Organization\Recruitment;

use App\Actions\Recruitment\RepeatRequirementAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Recruitment\RepeatRequirementRequest;
use App\Models\RecruitmentRequirement;
use Illuminate\Http\RedirectResponse;

class RequirementRepeatController extends Controller
{
    public function __invoke(
        RepeatRequirementRequest $request,
        RecruitmentRequirement $requirement,
        RepeatRequirementAction $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $requirement->company_id === $companyId, 404);

        $newRequirement = $action->execute(
            $requirement,
            (int) $request->user()->id,
            $request->validated(),
        );

        return redirect()->route('organization.recruitment.requirements.index')
            ->with('success', "Requirement repeated as {$newRequirement->requirement_number}.");
    }
}
