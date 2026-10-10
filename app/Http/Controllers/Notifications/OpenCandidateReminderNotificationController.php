<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Models\RecruitmentCandidateInternalReminder;
use App\Support\Companies\ActivateCompanySession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OpenCandidateReminderNotificationController extends Controller
{
    public function __invoke(
        Request $request,
        RecruitmentCandidateInternalReminder $reminder,
        ActivateCompanySession $activateCompany,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user !== null && (int) $reminder->user_id === (int) $user->id, 404);

        $activateCompany->handle($user, (int) $reminder->company_id, $request);

        if ($reminder->read_at === null) {
            $reminder->update(['read_at' => now()]);
        }

        $url = $reminder->url ?: route('organization.recruitment.candidates.show', $reminder->recruitment_candidate_id);

        return redirect()->to($url);
    }
}
