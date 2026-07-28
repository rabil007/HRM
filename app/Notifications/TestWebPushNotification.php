<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
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
        // Unique tag per send so macOS/Chrome re-alerts instead of silently
        // replacing an already-open notification with the same tag.
        $tag = 'oms-hrm-web-push-test-'.Str::uuid()->toString();

        return (new WebPushMessage)
            ->title('OMS-HRM Test')
            ->body('Browser notifications are working on this device.')
            ->icon('/icons/icon-192x192.png')
            ->badge('/icons/icon-96x96.png')
            ->tag($tag)
            ->data([
                'url' => route('dashboard'),
            ])
            ->options(['TTL' => 60]);
    }
}
