<?php

namespace App\Http\Controllers\Organization\Recruitment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Recruitment\CorrectCandidateStageRequest;
use App\Models\RecruitmentCandidate;
use App\Support\Recruitment\Candidates\Actions\CorrectCandidateStage;
use Illuminate\Http\RedirectResponse;

class CandidateCorrectStageController extends Controller
{
    public function __invoke(
        CorrectCandidateStageRequest $request,
        RecruitmentCandidate $candidate,
        CorrectCandidateStage $action,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $user = $request->user();
        abort_unless($user !== null, 403);
        abort_unless((int) $candidate->company_id === $companyId, 404);

        $candidate->loadMissing('requirement', 'line', 'currentOffer');
        $action->handle($user, $candidate, $request->validated());

        return back()->with('success', 'Candidate stage corrected successfully.');
    }
}
