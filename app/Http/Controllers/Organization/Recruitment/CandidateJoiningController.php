<?php

namespace App\Http\Controllers\Organization\Recruitment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Recruitment\CandidateConfirmJoinedRequest;
use App\Http\Requests\Organization\Recruitment\CandidateCorrectJoiningRequest;
use App\Http\Requests\Organization\Recruitment\CandidateJoiningReadinessRequest;
use App\Http\Requests\Organization\Recruitment\CandidateLinkEmployeeRequest;
use App\Models\RecruitmentCandidate;
use App\Support\Recruitment\Candidates\Actions\ConfirmCandidateJoined;
use App\Support\Recruitment\Candidates\Actions\CorrectCandidateJoined;
use App\Support\Recruitment\Candidates\Actions\LinkCandidateToEmployee;
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

    public function linkEmployee(
        CandidateLinkEmployeeRequest $request,
        RecruitmentCandidate $candidate,
        LinkCandidateToEmployee $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $user = $request->user();
        abort_unless($user !== null, 403);
        abort_unless((int) $candidate->company_id === $companyId, 404);

        $validated = $request->validated();
        $action->handle(
            $user,
            $candidate,
            (int) $validated['employee_id'],
            (string) $validated['reason'],
            $companyId,
            (bool) $validated['confirmed'],
            isset($validated['lock_version']) ? (int) $validated['lock_version'] : null,
        );

        return back()->with('success', 'Candidate successfully linked to existing employee.');
    }
}
