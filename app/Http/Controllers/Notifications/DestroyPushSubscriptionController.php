<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\DestroyPushSubscriptionRequest;
use App\Support\Notifications\SyncPushSubscription;
use Illuminate\Http\JsonResponse;

class DestroyPushSubscriptionController extends Controller
{
    public function __invoke(
        DestroyPushSubscriptionRequest $request,
        SyncPushSubscription $sync,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 403);

        $sync->destroy($user, (string) $request->validated('endpoint'));

        return response()->json([
            'ok' => true,
            'enabled' => false,
        ]);
    }
}
