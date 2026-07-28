<?php

namespace App\Support\Notifications;

use App\Models\User;
use Illuminate\Support\Facades\DB;
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
                $this->evictLeastRecentlyUsed($user, self::MAX_SUBSCRIPTIONS_PER_USER - 1);
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

    /**
     * Keep only the most recently used subscriptions before storing a new endpoint.
     *
     * Browsers mint a brand-new endpoint whenever site data is cleared or the
     * service worker is unregistered, and the replaced rows are unreachable
     * forever. Evicting them keeps the cap a storage bound instead of a state
     * that locks the user out of push permanently.
     */
    private function evictLeastRecentlyUsed(User $user, int $keep): void
    {
        $stale = $user->pushSubscriptions()
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->pluck('id')
            ->slice($keep);

        if ($stale->isEmpty()) {
            return;
        }

        PushSubscription::query()->whereKey($stale->all())->delete();
    }
}
