<?php

namespace App\Jobs;

use App\Models\AnnouncementRecipient;
use App\Models\User;
use App\Notifications\AnnouncementWebPushNotification;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class DeliverAnnouncementWebPushJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 300;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 60, 120];

    public function __construct(public int $recipientId) {}

    public function uniqueId(): string
    {
        return 'announcement-web-push-'.$this->recipientId;
    }

    public function handle(): void
    {
        $recipient = AnnouncementRecipient::query()
            ->with(['announcement', 'user'])
            ->find($this->recipientId);

        if ($recipient === null || $recipient->announcement === null) {
            return;
        }

        $user = $recipient->user;

        if (! $user instanceof User || (int) $recipient->user_id !== (int) $user->id) {
            return;
        }

        if ($user->pushSubscriptions()->doesntExist()) {
            return;
        }

        try {
            Notification::send($user, new AnnouncementWebPushNotification($recipient));
        } catch (Throwable $exception) {
            Log::warning('Announcement web push delivery failed', [
                'recipient_id' => $this->recipientId,
                'user_id' => $user->id,
                'attempt' => $this->attempts(),
                'exception_class' => $exception::class,
                'failure_category' => 'web_push_transport',
            ]);

            // Rethrow so queue retries/backoff can run. Never touch delivery rows.
            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('Announcement web push delivery exhausted retries', [
            'recipient_id' => $this->recipientId,
            'attempt' => $this->attempts(),
            'exception_class' => $exception::class,
            'failure_category' => 'web_push_exhausted',
        ]);
    }
}
