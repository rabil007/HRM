<?php

namespace App\Http\Controllers\Organization\Recruitment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Recruitment\MoveCandidateRequest;
use App\Models\RecruitmentCandidate;
use App\Support\Recruitment\Candidates\Actions\SelectCandidate;
use Illuminate\Http\RedirectResponse;

class CandidateSelectController extends Controller
{
    public function __invoke(
        MoveCandidateRequest $request,
        RecruitmentCandidate $candidate,
        SelectCandidate $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $user = $request->user();
        abort_unless($user !== null, 403);
        abort_unless((int) $candidate->company_id === $companyId, 404);

        $candidate->loadMissing('requirement', 'line');
        $action->handle($user, $candidate, $request->validated());

        return back()->with('success', 'Candidate marked as Selected.');
    }
}
