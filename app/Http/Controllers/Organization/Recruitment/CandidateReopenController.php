<?php

namespace App\Http\Controllers\Organization\Recruitment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Recruitment\ReopenCandidateRequest;
use App\Models\RecruitmentCandidate;
use App\Support\Recruitment\Candidates\Actions\ReopenRejectedCandidate;
use Illuminate\Http\RedirectResponse;

class CandidateReopenController extends Controller
{
    public function __invoke(
        ReopenCandidateRequest $request,
        RecruitmentCandidate $candidate,
        ReopenRejectedCandidate $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $user = $request->user();
        abort_unless($user !== null, 403);
        abort_unless((int) $candidate->company_id === $companyId, 404);

        $candidate->loadMissing('requirement', 'line');
        $action->handle(
            $user,
            $candidate,
            $request->string('reason')->toString(),
            $request->only(['lock_version', 'expected_stage']),
        );

        return back()->with('success', 'Candidate reopened.');
    }
}
