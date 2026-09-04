<?php

namespace App\Notifications;

use App\Models\DocumentGenerationRun;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class DocumentGenerationFinishedWebPushNotification extends Notification
{
    public function __construct(
        public int $runId,
        public string $title,
        public string $body,
    ) {}

    public static function fromRun(DocumentGenerationRun $run): self
    {
        $templateName = (string) ($run->template?->name ?: 'Document');
        $generated = (int) $run->generated_count;
        $skipped = (int) $run->skipped_count;
        $failed = (int) $run->failed_count;

        if ($run->status === 'failed') {
            return new self(
                $run->id,
                'Document generation failed',
                "{$templateName} could not be completed.",
            );
        }

        if ($failed > 0) {
            return new self(
                $run->id,
                'Document generation completed with issues',
                "{$templateName}: {$generated} generated, {$skipped} skipped, {$failed} failed.",
            );
        }

        return new self(
            $run->id,
            'Document generation completed',
            "{$templateName}: {$generated} documents generated.",
        );
    }

    /**
     * @return list<class-string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        $url = route('notifications.documents.generation-runs.open', [
            'run' => $this->runId,
        ]);

        return (new WebPushMessage)
            ->title($this->title)
            ->body($this->body)
            ->icon('/icons/icon-192x192.png')
            ->badge('/icons/icon-96x96.png')
            ->tag('document-generation-'.$this->runId)
            ->data([
                'url' => $url,
                'run_id' => $this->runId,
            ])
            ->options(['TTL' => 86400]);
    }
}
