<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class CrewOperationalAlertWebPushNotification extends Notification
{
    public function __construct(
        public int $companyId,
        public int $recipientId,
        public int $deliveryId,
    ) {}

    /**
     * @return list<class-string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        $url = route('notifications.crew-operational-alerts.open', [
            'recipient' => $this->recipientId,
        ]);

        return (new WebPushMessage)
            ->title('Crew Operations')
            ->body('Crew Operations requires attention. Open OMS-HRM to review.')
            ->icon('/icons/icon-192x192.png')
            ->badge('/icons/icon-96x96.png')
            ->tag('crew-operational-alert-'.$this->companyId.'-'.$this->deliveryId)
            ->data([
                'url' => $url,
                'company_id' => $this->companyId,
                'delivery_id' => $this->deliveryId,
            ])
            ->options(['TTL' => 86400]);
    }
}
