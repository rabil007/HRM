<?php

namespace App\Http\Controllers\Organization\Announcements;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Support\Announcements\Actions\RetryFailedAnnouncementDeliveries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RetryAnnouncementDeliveriesController extends Controller
{
    public function __invoke(
        Request $request,
        Announcement $announcement,
        RetryFailedAnnouncementDeliveries $retry,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $announcement->company_id === $companyId, 404);

        $user = $request->user();
        abort_unless($user !== null, 403);

        $deliveryIds = $request->input('delivery_ids');
        $retried = $retry->handle(
            $announcement,
            $user,
            is_array($deliveryIds) ? array_map('intval', $deliveryIds) : null,
        );

        return redirect()
            ->route('organization.announcements.show', $announcement)
            ->with('success', $retried > 0
                ? "Retried {$retried} failed delivery(ies)."
                : 'No failed deliveries to retry.');
    }
}
