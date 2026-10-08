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

        $result = $action->execute(
            $requirement,
            $request->user(),
            $request->validated('lines'),
            $request->validated('reason'),
        );

        $message = $result['mode'] === 'direct'
            ? 'Headcount updated successfully.'
            : 'Headcount revision submitted for approval.';

        return redirect()->back()->with('success', $message);
    }
}
