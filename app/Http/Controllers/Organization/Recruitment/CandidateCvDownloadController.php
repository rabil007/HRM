<?php

namespace App\Http\Controllers\Organization\Recruitment;

use App\Http\Controllers\Controller;
use App\Models\RecruitmentCandidate;
use App\Support\Recruitment\Candidates\CandidateCvStorage;
use App\Support\Recruitment\Candidates\CandidateWorkflowAuthorization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CandidateCvDownloadController extends Controller
{
    public function __invoke(Request $request, RecruitmentCandidate $candidate): StreamedResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $user = $request->user();
        abort_unless($user !== null, 403);
        abort_unless((int) $candidate->company_id === $companyId, 404);
        abort_unless(CandidateWorkflowAuthorization::canDownloadCv($user, $candidate), 403);
        abort_unless($candidate->hasCv(), 404);

        $path = CandidateCvStorage::validatedRelativePath(
            $candidate->cv_path,
            $companyId,
            (int) $candidate->id,
        );

        abort_unless($path !== null && Storage::disk(CandidateCvStorage::DISK)->exists($path), 404);

        $filename = $candidate->cv_original_file_name ?: basename($path);

        return Storage::disk(CandidateCvStorage::DISK)->download($path, $filename);
    }
}
