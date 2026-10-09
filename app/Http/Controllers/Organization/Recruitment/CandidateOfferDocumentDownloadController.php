<?php

namespace App\Http\Controllers\Organization\Recruitment;

use App\Http\Controllers\Controller;
use App\Models\RecruitmentCandidate;
use App\Models\RecruitmentCandidateOffer;
use App\Support\Recruitment\Candidates\CandidateOfferStorage;
use App\Support\Recruitment\Candidates\CandidateWorkflowAuthorization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CandidateOfferDocumentDownloadController extends Controller
{
    public function __invoke(
        Request $request,
        RecruitmentCandidate $candidate,
        RecruitmentCandidateOffer $offer,
        string $kind,
    ): StreamedResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $user = $request->user();
        abort_unless($user !== null, 403);
        abort_unless((int) $candidate->company_id === $companyId, 404);
        abort_unless((int) $offer->recruitment_candidate_id === (int) $candidate->id, 404);
        abort_unless((int) $offer->company_id === $companyId, 404);
        abort_unless(CandidateWorkflowAuthorization::canDownloadOfferDocuments($user, $candidate), 403);

        if (! in_array($kind, ['offer', 'acceptance'], true)) {
            throw ValidationException::withMessages([
                'document' => 'Unknown offer document type.',
            ]);
        }

        $path = $kind === 'offer' ? $offer->offer_document_path : $offer->acceptance_document_path;
        $filename = $kind === 'offer'
            ? ($offer->offer_document_original_file_name ?: 'offer-document')
            : ($offer->acceptance_document_original_file_name ?: 'acceptance-document');

        $validated = CandidateOfferStorage::validatedRelativePath(
            $path,
            $companyId,
            (int) $candidate->id,
            (int) $offer->id,
        );

        abort_unless($validated !== null && Storage::disk(CandidateOfferStorage::DISK)->exists($validated), 404);

        return Storage::disk(CandidateOfferStorage::DISK)->download($validated, $filename);
    }
}
