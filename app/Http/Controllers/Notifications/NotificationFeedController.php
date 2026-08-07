<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Support\Notifications\BuildUnifiedNotificationFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationFeedController extends Controller
{
    public function __invoke(
        Request $request,
        BuildUnifiedNotificationFeed $feed,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 403);

        $companyId = (int) $request->attributes->get('current_company_id');

        return response()->json($feed->forUser($user, $companyId));
    }
}
