<?php

namespace App\Http\Controllers\Organization\Recruitment;

use App\Actions\Recruitment\TransferRequirementOwnershipAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Recruitment\TransferRequirementOwnershipRequest;
use App\Models\RecruitmentRequirement;
use Illuminate\Http\RedirectResponse;

class RequirementTransferOwnershipController extends Controller
{
    public function __invoke(
        TransferRequirementOwnershipRequest $request,
        RecruitmentRequirement $requirement,
        TransferRequirementOwnershipAction $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $requirement->company_id === $companyId, 404);

        $action->execute($requirement, $request->user(), $request->ownershipData());

        return redirect()->back()->with(
            'success',
            "Ownership transferred for requirement {$requirement->requirement_number}.",
        );
    }
}
