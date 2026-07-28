<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class DocumentComplianceWebPushNotification extends Notification
{
    public function __construct(public int $companyId) {}

    /**
     * @return list<class-string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        $url = route('notifications.documents.compliance.open', [
            'company' => $this->companyId,
        ]);

        return (new WebPushMessage)
            ->title('Document compliance alert')
            ->body('Documents require expiry or compliance attention.')
            ->icon('/icons/icon-192x192.png')
            ->badge('/icons/icon-96x96.png')
            ->tag('document-compliance-'.$this->companyId)
            ->data([
                'url' => $url,
                'company_id' => $this->companyId,
            ])
            ->options(['TTL' => 86400]);
    }
}
