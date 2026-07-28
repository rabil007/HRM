<?php

namespace App\Notifications;

use App\Models\AnnouncementRecipient;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class AnnouncementWebPushNotification extends Notification
{
    public function __construct(public AnnouncementRecipient $recipient) {}

    /**
     * @return list<class-string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        $url = route('notifications.announcements.open', $this->recipient);

        return (new WebPushMessage)
            ->title('OMS-HRM')
            ->body('A new announcement is available. Click to view.')
            ->icon('/icons/icon-192x192.png')
            ->badge('/icons/icon-96x96.png')
            ->tag('announcement-recipient-'.$this->recipient->id)
            ->data([
                'url' => $url,
                'recipient_id' => $this->recipient->id,
            ])
            ->options(['TTL' => 86400]);
    }
}
