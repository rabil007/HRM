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
            // Test push must deliver immediately so the user can verify the popup
            // without a running queue worker (common when only `npm run dev` is used).
            SendTestWebPushJob::dispatchSync($user->id, $subscription->id);
        } catch (Throwable $exception) {
            $stillExists = $user->pushSubscriptions()
                ->where('endpoint', $endpoint)
                ->exists();

            Log::warning('Test web push delivery failed', [
                'user_id' => $user->id,
                'subscription_id' => $subscription->id,
                'exception_class' => $exception::class,
                'failure_category' => $stillExists
                    ? 'test_web_push_delivery'
                    : 'test_web_push_expired',
            ]);

            // Provider 404/410 deletes the row via ReportHandler — treat as expired
            // so the UI asks the user to enable again instead of a generic 503.
            if (! $stillExists) {
                return response()->json([
                    'ok' => false,
                    'expired' => true,
                    'message' => 'This browser is no longer subscribed. Enable notifications again.',
                ], 404);
            }

            return response()->json([
                'ok' => false,
                'message' => 'The test notification could not be sent.',
            ], 503);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Test notification sent.',
        ]);
    }
}
