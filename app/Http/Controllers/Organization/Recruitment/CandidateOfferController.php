<?php

namespace App\Http\Controllers\Organization\Recruitment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Recruitment\AcceptCandidateOfferRequest;
use App\Http\Requests\Organization\Recruitment\PrepareCandidateOfferRequest;
use App\Http\Requests\Organization\Recruitment\RejectCandidateOfferRequest;
use App\Http\Requests\Organization\Recruitment\ReviseCandidateOfferRequest;
use App\Http\Requests\Organization\Recruitment\SendCandidateOfferRequest;
use App\Http\Requests\Organization\Recruitment\UpdateCandidateOfferRequest;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentCandidateOffer;
use App\Support\Recruitment\Candidates\Actions\AcceptCandidateOffer;
use App\Support\Recruitment\Candidates\Actions\PrepareCandidateOffer;
use App\Support\Recruitment\Candidates\Actions\RejectCandidateOffer;
use App\Support\Recruitment\Candidates\Actions\ReviseCandidateOffer;
use App\Support\Recruitment\Candidates\Actions\SendCandidateOffer;
use App\Support\Recruitment\Candidates\Actions\UpdateCandidateOffer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CandidateOfferController extends Controller
{
    public function prepare(
        PrepareCandidateOfferRequest $request,
        RecruitmentCandidate $candidate,
        PrepareCandidateOffer $action,
    ): RedirectResponse {
        $this->assertCompany($request, $candidate);
        $action->handle($request->user(), $candidate, $request->validated());

        return back()->with('success', 'Offer/JOL draft prepared.');
    }

    public function update(
        UpdateCandidateOfferRequest $request,
        RecruitmentCandidate $candidate,
        RecruitmentCandidateOffer $offer,
        UpdateCandidateOffer $action,
    ): RedirectResponse {
        $this->assertCompanyOffer($request, $candidate, $offer);
        $action->handle($request->user(), $candidate, $offer, $request->validated());

        return back()->with('success', 'Offer/JOL draft updated.');
    }

    public function send(
        SendCandidateOfferRequest $request,
        RecruitmentCandidate $candidate,
        RecruitmentCandidateOffer $offer,
        SendCandidateOffer $action,
    ): RedirectResponse {
        $this->assertCompanyOffer($request, $candidate, $offer);
        $action->handle($request->user(), $candidate, $offer, $request->validated());

        return back()->with('success', 'Offer recorded as sent (no email was sent).');
    }

    public function accept(
        AcceptCandidateOfferRequest $request,
        RecruitmentCandidate $candidate,
        RecruitmentCandidateOffer $offer,
        AcceptCandidateOffer $action,
    ): RedirectResponse {
        $this->assertCompanyOffer($request, $candidate, $offer);
        $action->handle($request->user(), $candidate, $offer, $request->validated());

        return back()->with('success', 'Offer accepted. Candidate moved to Joining.');
    }

    public function reject(
        RejectCandidateOfferRequest $request,
        RecruitmentCandidate $candidate,
        RecruitmentCandidateOffer $offer,
        RejectCandidateOffer $action,
    ): RedirectResponse {
        $this->assertCompanyOffer($request, $candidate, $offer);
        $action->handle($request->user(), $candidate, $offer, $request->validated());

        return back()->with('success', 'Offer rejected. Candidate moved to Rejected.');
    }

    public function revise(
        ReviseCandidateOfferRequest $request,
        RecruitmentCandidate $candidate,
        RecruitmentCandidateOffer $offer,
        ReviseCandidateOffer $action,
    ): RedirectResponse {
        $this->assertCompanyOffer($request, $candidate, $offer);
        $action->handle($request->user(), $candidate, $offer, $request->validated());

        return back()->with('success', 'Offer revision created as a new Draft.');
    }

    private function assertCompany(Request $request, RecruitmentCandidate $candidate): void
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless($request->user() !== null, 403);
        abort_unless((int) $candidate->company_id === $companyId, 404);
    }

    private function assertCompanyOffer(
        Request $request,
        RecruitmentCandidate $candidate,
        RecruitmentCandidateOffer $offer,
    ): void {
        $this->assertCompany($request, $candidate);
        abort_unless((int) $offer->recruitment_candidate_id === (int) $candidate->id, 404);
        abort_unless((int) $offer->company_id === (int) $candidate->company_id, 404);
    }
}
