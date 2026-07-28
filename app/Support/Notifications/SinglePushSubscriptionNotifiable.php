<?php

namespace App\Support\Notifications;

use Illuminate\Database\Eloquent\Collection;
use NotificationChannels\WebPush\PushSubscription;

/**
 * Temporary notifiable that exposes exactly one verified push subscription.
 */
final class SinglePushSubscriptionNotifiable
{
    public function __construct(private readonly PushSubscription $subscription) {}

    /**
     * @return Collection<int, PushSubscription>
     */
    public function routeNotificationForWebPush(): Collection
    {
        return new Collection([$this->subscription]);
    }
}
