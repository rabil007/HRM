<?php

namespace App\Http\Controllers\Organization\Recruitment;

use App\Actions\Recruitment\ExtendDeadlineAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Recruitment\ExtendDeadlineRequest;
use App\Models\RecruitmentRequirement;
use Illuminate\Http\RedirectResponse;

class RequirementExtendDeadlineController extends Controller
{
    public function __invoke(
        ExtendDeadlineRequest $request,
        RecruitmentRequirement $requirement,
        ExtendDeadlineAction $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $requirement->company_id === $companyId, 404);

        $action->execute(
            $requirement,
            (int) $request->user()->id,
            $request->validated('new_date'),
            $request->validated('reason'),
        );

        return redirect()->back()->with('success', 'Deadline extended successfully.');
    }
}
