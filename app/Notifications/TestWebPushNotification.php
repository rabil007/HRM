<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class TestWebPushNotification extends Notification
{
    /**
     * @return list<class-string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('OMS-HRM Test')
            ->body('Browser notifications are working on this device.')
            ->icon('/icons/icon-192x192.png')
            ->badge('/icons/icon-96x96.png')
            ->tag('oms-hrm-web-push-test')
            ->data([
                'url' => route('dashboard'),
            ])
            ->options(['TTL' => 60]);
    }
}
