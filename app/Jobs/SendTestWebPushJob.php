<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\TestWebPushNotification;
use App\Support\Notifications\SinglePushSubscriptionNotifiable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use NotificationChannels\WebPush\Events\NotificationFailed;
use NotificationChannels\WebPush\PushSubscription;
use NotificationChannels\WebPush\WebPushChannel;
use RuntimeException;
use Throwable;

class SendTestWebPushJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /**
     * @var list<int>
     */
    public array $backoff = [15, 30];

    public function __construct(
        public int $userId,
        public int $subscriptionId,
    ) {}

    public function handle(WebPushChannel $channel): void
    {
        $user = User::query()->find($this->userId);

        if ($user === null) {
            return;
        }

        $subscription = PushSubscription::query()->find($this->subscriptionId);

        if ($subscription === null || ! $user->ownsPushSubscription($subscription)) {
            return;
        }

        try {
            $failed = 0;

            Event::listen(NotificationFailed::class, function () use (&$failed): void {
                $failed++;
            });

            $channel->send(
                new SinglePushSubscriptionNotifiable($subscription),
                new TestWebPushNotification,
            );

            if ($failed > 0) {
                throw new RuntimeException('Web push provider rejected the test notification.');
            }
        } catch (Throwable $exception) {
            Log::warning('Test web push delivery failed', [
                'user_id' => $this->userId,
                'subscription_id' => $this->subscriptionId,
                'attempt' => $this->attempts(),
                'exception_class' => $exception::class,
                'failure_category' => 'test_web_push_transport',
            ]);

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('Test web push delivery exhausted retries', [
            'user_id' => $this->userId,
            'subscription_id' => $this->subscriptionId,
            'attempt' => $this->attempts(),
            'exception_class' => $exception::class,
            'failure_category' => 'test_web_push_exhausted',
        ]);
    }
}
