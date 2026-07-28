<?php

namespace App\Support\Notifications;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use NotificationChannels\WebPush\PushSubscription;

final class SyncPushSubscription
{
    /**
     * @param  array{endpoint: string, keys: array{p256dh: string, auth: string}, contentEncoding?: string|null}  $payload
     */
    public function store(User $user, array $payload): void
    {
        DB::transaction(function () use ($user, $payload): void {
            $user->updatePushSubscription(
                $payload['endpoint'],
                $payload['keys']['p256dh'],
                $payload['keys']['auth'],
                $payload['contentEncoding'] ?? $payload['content_encoding'] ?? null,
            );
        });
    }

    public function destroy(User $user, string $endpoint): void
    {
        DB::transaction(function () use ($user, $endpoint): void {
            $user->deletePushSubscription($endpoint);
        });
    }

    public function subscriptionCount(User $user): int
    {
        return PushSubscription::query()
            ->where('subscribable_type', $user->getMorphClass())
            ->where('subscribable_id', $user->getKey())
            ->count();
    }
}
