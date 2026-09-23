<?php

namespace App\Http\Controllers\Organization\Recruitment;

use App\Actions\Recruitment\ChangeHeadcountAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Recruitment\ChangeHeadcountRequest;
use App\Models\RecruitmentRequirement;
use Illuminate\Http\RedirectResponse;

class RequirementChangeHeadcountController extends Controller
{
    public function __invoke(
        ChangeHeadcountRequest $request,
        RecruitmentRequirement $requirement,
        ChangeHeadcountAction $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $requirement->company_id === $companyId, 404);

        $action->execute(
            $requirement,
            (int) $request->user()->id,
            $request->validated('lines'),
            $request->validated('reason'),
        );

        return redirect()->back()->with('success', 'Headcount updated successfully.');
    }
}
