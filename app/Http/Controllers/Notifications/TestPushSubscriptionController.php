<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\TestPushSubscriptionRequest;
use App\Jobs\SendTestWebPushJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class TestPushSubscriptionController extends Controller
{
    public function __invoke(TestPushSubscriptionRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 403);

        if (
            blank(config('webpush.vapid.public_key'))
            || blank(config('webpush.vapid.private_key'))
        ) {
            return response()->json([
                'ok' => false,
                'message' => 'The test notification could not be sent.',
            ], 503);
        }

        $endpoint = $request->endpoint();

        $subscription = $user->pushSubscriptions()
            ->where('endpoint', $endpoint)
            ->first();

        if ($subscription === null) {
            return response()->json([
                'ok' => false,
                'expired' => true,
                'message' => 'This browser is no longer subscribed. Enable notifications again.',
            ], 404);
        }

        try {
            SendTestWebPushJob::dispatch($user->id, $subscription->id);
        } catch (Throwable $exception) {
            Log::warning('Test web push queue dispatch failed', [
                'user_id' => $user->id,
                'subscription_id' => $subscription->id,
                'exception_class' => $exception::class,
                'failure_category' => 'test_web_push_queue',
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'The test notification could not be sent.',
            ], 503);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Test notification queued.',
        ]);
    }
}
