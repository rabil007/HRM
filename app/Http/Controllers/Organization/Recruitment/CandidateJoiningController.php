<?php

namespace App\Http\Controllers\Organization\Recruitment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Recruitment\CandidateConfirmJoinedRequest;
use App\Http\Requests\Organization\Recruitment\CandidateCorrectJoiningRequest;
use App\Http\Requests\Organization\Recruitment\CandidateJoiningReadinessRequest;
use App\Models\RecruitmentCandidate;
use App\Support\Recruitment\Candidates\Actions\ConfirmCandidateJoined;
use App\Support\Recruitment\Candidates\Actions\CorrectCandidateJoined;
use App\Support\Recruitment\Candidates\Actions\UpdateCandidateJoiningReadiness;
use Illuminate\Http\RedirectResponse;

class CandidateJoiningController extends Controller
{
    public function updateReadiness(
        CandidateJoiningReadinessRequest $request,
        RecruitmentCandidate $candidate,
        UpdateCandidateJoiningReadiness $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $user = $request->user();
        abort_unless($user !== null, 403);
        abort_unless((int) $candidate->company_id === $companyId, 404);

        $action->handle($user, $candidate, $request->validated());

        return back()->with('success', 'Candidate joining readiness updated.');
    }

    public function confirm(
        CandidateConfirmJoinedRequest $request,
        RecruitmentCandidate $candidate,
        ConfirmCandidateJoined $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $user = $request->user();
        abort_unless($user !== null, 403);
        abort_unless((int) $candidate->company_id === $companyId, 404);

        $action->handle($user, $candidate, $request->validated());

        return back()->with('success', 'Candidate confirmed as joined.');
    }

    public function correct(
        CandidateCorrectJoiningRequest $request,
        RecruitmentCandidate $candidate,
        CorrectCandidateJoined $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $user = $request->user();
        abort_unless($user !== null, 403);
        abort_unless((int) $candidate->company_id === $companyId, 404);

        $action->handle($user, $candidate, $request->validated());

        return back()->with('success', 'Candidate joining corrected.');
    }
}
