<?php

namespace App\Support\Announcements\Resources;

use App\Models\Announcement;
use App\Models\AnnouncementAttachment;
use App\Models\AnnouncementAudience;
use App\Models\AnnouncementRecipient;
use App\Support\Announcements\AnnouncementDeliverySummary;

final class AnnouncementResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toListArray(Announcement $announcement): array
    {
        return [
            'id' => $announcement->id,
            'title' => $announcement->title,
            'category' => $announcement->category->value,
            'category_label' => $announcement->category->label(),
            'priority' => $announcement->priority->value,
            'priority_label' => $announcement->priority->label(),
            'status' => $announcement->status->value,
            'status_label' => $announcement->status->label(),
            'channels' => $announcement->channels ?? [],
            'audience_summary' => self::audienceSummary($announcement),
            'scheduled_at' => $announcement->scheduled_at?->toIso8601String(),
            'published_at' => $announcement->published_at?->toIso8601String(),
            'created_by' => $announcement->creator?->name,
            'created_at' => $announcement->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function toShowArray(Announcement $announcement): array
    {
        $announcement->loadMissing([
            'audiences',
            'attachments',
            'creator:id,name',
            'publisher:id,name',
            'recipients.department:id,name',
            'recipients.deliveries',
        ]);

        return [
            ...self::toListArray($announcement),
            'body_html' => $announcement->body_html,
            'expires_at' => $announcement->expires_at?->toIso8601String(),
            'published_by' => $announcement->publisher?->name,
            'audiences' => $announcement->audiences->map(fn (AnnouncementAudience $audience): array => [
                'type' => $audience->audience_type->value,
                'id' => $audience->audience_id,
            ])->values()->all(),
            'attachments' => $announcement->attachments->map(fn (AnnouncementAttachment $attachment): array => [
                'id' => $attachment->id,
                'original_name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size_bytes' => $attachment->size_bytes,
            ])->values()->all(),
            'delivery_summary' => AnnouncementDeliverySummary::for($announcement),
            'recipients' => $announcement->recipients->map(fn (AnnouncementRecipient $recipient): array => [
                'id' => $recipient->id,
                'employee_name' => $recipient->employee_name,
                'department' => $recipient->department?->name,
                'in_app' => $recipient->deliveries->first(fn ($d) => $d->channel->value === 'in_app')?->status->value,
                'email' => $recipient->deliveries->first(fn ($d) => $d->channel->value === 'email')?->status->value,
                'whatsapp' => $recipient->deliveries->first(fn ($d) => $d->channel->value === 'whatsapp')?->status->value,
                'read_at' => $recipient->read_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function toFormArray(Announcement $announcement): array
    {
        $announcement->loadMissing(['audiences', 'attachments']);

        return [
            'id' => $announcement->id,
            'title' => $announcement->title,
            'body_html' => $announcement->body_html,
            'category' => $announcement->category->value,
            'priority' => $announcement->priority->value,
            'status' => $announcement->status->value,
            'channels' => $announcement->channels ?? [],
            'expires_at' => $announcement->expires_at?->format('Y-m-d\TH:i'),
            'scheduled_at' => $announcement->scheduled_at?->format('Y-m-d\TH:i'),
            'audiences' => $announcement->audiences->map(fn (AnnouncementAudience $audience): array => [
                'type' => $audience->audience_type->value,
                'id' => $audience->audience_id,
            ])->values()->all(),
            'attachments' => $announcement->attachments->map(fn (AnnouncementAttachment $attachment): array => [
                'id' => $attachment->id,
                'original_name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size_bytes' => $attachment->size_bytes,
            ])->values()->all(),
        ];
    }

    private static function audienceSummary(Announcement $announcement): string
    {
        $audiences = $announcement->relationLoaded('audiences')
            ? $announcement->audiences
            : $announcement->audiences()->get();

        if ($audiences->contains(fn ($a) => $a->audience_type->value === 'all_employees')) {
            return 'All active employees';
        }

        $counts = $audiences->groupBy(fn ($a) => $a->audience_type->value)
            ->map(fn ($group) => $group->count());

        $parts = [];
        foreach ($counts as $type => $count) {
            $parts[] = $count.' '.str_replace('_', ' ', (string) $type);
        }

        return $parts !== [] ? implode(', ', $parts) : '—';
    }
}
