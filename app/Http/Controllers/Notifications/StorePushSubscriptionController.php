<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\StorePushSubscriptionRequest;
use App\Support\Notifications\SyncPushSubscription;
use Illuminate\Http\JsonResponse;

class StorePushSubscriptionController extends Controller
{
    public function __invoke(
        StorePushSubscriptionRequest $request,
        SyncPushSubscription $sync,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 403);

        $sync->store($user, $request->subscriptionPayload());

        return response()->json([
            'ok' => true,
            'enabled' => true,
        ]);
    }
}
