<?php

namespace App\Support\Notifications;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use NotificationChannels\WebPush\PushSubscription;

final class SyncPushSubscription
{
    public const MAX_SUBSCRIPTIONS_PER_USER = 10;

    /**
     * @param  array{endpoint: string, keys: array{p256dh: string, auth: string}, contentEncoding: string}  $payload
     */
    public function store(User $user, array $payload): void
    {
        DB::transaction(function () use ($user, $payload): void {
            $existing = PushSubscription::query()
                ->where('endpoint', $payload['endpoint'])
                ->lockForUpdate()
                ->first();

            // Cap applies only to brand-new endpoints. Updating an owned endpoint or
            // taking over a shared-browser endpoint remains allowed.
            if ($existing === null) {
                $count = $user->pushSubscriptions()->lockForUpdate()->count();

                if ($count >= self::MAX_SUBSCRIPTIONS_PER_USER) {
                    throw ValidationException::withMessages([
                        'endpoint' => 'You may register at most '.self::MAX_SUBSCRIPTIONS_PER_USER.' browser notification subscriptions.',
                    ]);
                }
            }

            $user->updatePushSubscription(
                $payload['endpoint'],
                $payload['keys']['p256dh'],
                $payload['keys']['auth'],
                $payload['contentEncoding'],
            );
        });
    }

    public function destroy(User $user, string $endpoint): void
    {
        DB::transaction(function () use ($user, $endpoint): void {
            $user->deletePushSubscription($endpoint);
        });
    }
}
