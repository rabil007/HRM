<?php

namespace App\Http\Controllers\Organization\Announcements;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Support\Announcements\Actions\CancelScheduledAnnouncement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CancelAnnouncementController extends Controller
{
    public function __invoke(
        Request $request,
        Announcement $announcement,
        CancelScheduledAnnouncement $cancel,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $announcement->company_id === $companyId, 404);

        $user = $request->user();
        abort_unless($user !== null, 403);

        $cancel->handle($announcement, $user);

        return redirect()
            ->route('organization.announcements.show', $announcement)
            ->with('success', 'Scheduled announcement cancelled.');
    }
}
