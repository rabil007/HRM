<?php

namespace App\Http\Controllers\Notifications;

use App\Enums\AnnouncementStatus;
use App\Http\Controllers\Controller;
use App\Models\AnnouncementRecipient;
use App\Support\Companies\ActivateCompanySession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OpenAnnouncementNotificationController extends Controller
{
    public function __invoke(
        Request $request,
        AnnouncementRecipient $recipient,
        ActivateCompanySession $activateCompany,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user !== null && (int) $recipient->user_id === (int) $user->id, 404);

        $announcement = $recipient->announcement;
        abort_unless($announcement !== null, 404);
        abort_unless(in_array($announcement->status, [
            AnnouncementStatus::Published,
            AnnouncementStatus::PartiallyDelivered,
            AnnouncementStatus::Expired,
        ], true), 404);

        $activateCompany->handle($user, (int) $recipient->company_id, $request);

        return redirect()->route('organization.announcements.inbox.show', $recipient);
    }
}
