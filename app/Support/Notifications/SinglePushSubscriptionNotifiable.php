<?php

namespace App\Support\Notifications;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\RoutesNotifications;
use NotificationChannels\WebPush\PushSubscription;

/**
 * Temporary notifiable that exposes exactly one verified push subscription.
 *
 * WebPushChannel calls routeNotificationFor('WebPush'), which is provided by
 * RoutesNotifications and delegates to routeNotificationForWebPush().
 */
final class SinglePushSubscriptionNotifiable
{
    use RoutesNotifications;

    public function __construct(private readonly PushSubscription $subscription) {}

    /**
     * @return Collection<int, PushSubscription>
     */
    public function routeNotificationForWebPush(): Collection
    {
        return new Collection([$this->subscription]);
    }
}
