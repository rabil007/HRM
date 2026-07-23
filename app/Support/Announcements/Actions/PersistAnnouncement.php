<?php

namespace App\Support\Announcements\Actions;

use App\Enums\AnnouncementAudienceType;
use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementChannel;
use App\Enums\AnnouncementPriority;
use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Models\AnnouncementAudience;
use App\Models\User;
use App\Support\Announcements\ResolveAnnouncementAudience;
use App\Support\Announcements\SanitizeAnnouncementHtml;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PersistAnnouncement
{
    public function __construct(private ResolveAnnouncementAudience $resolveAudience) {}

    /**
     * @param  array{
     *     title: string,
     *     body_html: string,
     *     category: string,
     *     priority: string,
     *     channels: list<string>,
     *     audiences: list<array{type: string, id?: int|null}>,
     *     expires_at?: string|null,
     *     requires_acknowledgement?: bool,
     *     publish_mode: string,
     *     scheduled_at?: string|null
     * }  $data
     */
    public function create(int $companyId, User $user, array $data): Announcement
    {
        $this->resolveAudience->assertAudiencesBelongToCompany($companyId, $data['audiences']);

        return DB::transaction(function () use ($companyId, $user, $data): Announcement {
            $status = $this->statusForPublishMode($data['publish_mode']);

            $announcement = Announcement::query()->create([
                'company_id' => $companyId,
                'title' => $data['title'],
                'body_html' => SanitizeAnnouncementHtml::handle($data['body_html']),
                'category' => AnnouncementCategory::from($data['category']),
                'priority' => AnnouncementPriority::from($data['priority']),
                'status' => $status,
                'channels' => array_values($data['channels']),
                'scheduled_at' => $status === AnnouncementStatus::Scheduled ? $data['scheduled_at'] : null,
                'expires_at' => $data['expires_at'] ?? null,
                'requires_acknowledgement' => (bool) ($data['requires_acknowledgement'] ?? false),
                'created_by' => $user->id,
            ]);

            $this->syncAudiences($announcement, $companyId, $data['audiences']);

            return $announcement->fresh(['audiences', 'attachments', 'creator']) ?? $announcement;
        });
    }

    /**
     * @param  array{
     *     title: string,
     *     body_html: string,
     *     category: string,
     *     priority: string,
     *     channels: list<string>,
     *     audiences: list<array{type: string, id?: int|null}>,
     *     expires_at?: string|null,
     *     requires_acknowledgement?: bool,
     *     publish_mode: string,
     *     scheduled_at?: string|null
     * }  $data
     */
    public function update(Announcement $announcement, array $data): Announcement
    {
        if (! $announcement->status->isEditable()) {
            throw ValidationException::withMessages([
                'status' => 'Only draft or scheduled announcements can be edited.',
            ]);
        }

        $this->resolveAudience->assertAudiencesBelongToCompany((int) $announcement->company_id, $data['audiences']);

        return DB::transaction(function () use ($announcement, $data): Announcement {
            $status = $this->statusForPublishMode($data['publish_mode']);

            $announcement->update([
                'title' => $data['title'],
                'body_html' => SanitizeAnnouncementHtml::handle($data['body_html']),
                'category' => AnnouncementCategory::from($data['category']),
                'priority' => AnnouncementPriority::from($data['priority']),
                'status' => $status === AnnouncementStatus::Draft || $status === AnnouncementStatus::Scheduled
                    ? $status
                    : $announcement->status,
                'channels' => array_values($data['channels']),
                'scheduled_at' => $status === AnnouncementStatus::Scheduled ? $data['scheduled_at'] : null,
                'expires_at' => $data['expires_at'] ?? null,
                'requires_acknowledgement' => (bool) ($data['requires_acknowledgement'] ?? false),
            ]);

            AnnouncementAudience::query()
                ->where('announcement_id', $announcement->id)
                ->delete();

            $this->syncAudiences($announcement, (int) $announcement->company_id, $data['audiences']);

            return $announcement->fresh(['audiences', 'attachments', 'creator']) ?? $announcement;
        });
    }

    /**
     * @param  list<array{type: string, id?: int|null}>  $audiences
     */
    private function syncAudiences(Announcement $announcement, int $companyId, array $audiences): void
    {
        foreach ($audiences as $audience) {
            $type = AnnouncementAudienceType::from((string) $audience['type']);

            AnnouncementAudience::query()->create([
                'company_id' => $companyId,
                'announcement_id' => $announcement->id,
                'audience_type' => $type,
                'audience_id' => $type === AnnouncementAudienceType::AllEmployees
                    ? null
                    : (int) ($audience['id'] ?? 0),
            ]);
        }
    }

    private function statusForPublishMode(string $mode): AnnouncementStatus
    {
        return match ($mode) {
            'schedule' => AnnouncementStatus::Scheduled,
            'send_now' => AnnouncementStatus::Draft,
            default => AnnouncementStatus::Draft,
        };
    }

    /**
     * @param  list<string>  $channels
     */
    public static function assertChannels(array $channels): void
    {
        $valid = AnnouncementChannel::values();
        $normalized = array_values(array_unique(array_map('strval', $channels)));

        if ($normalized === []) {
            throw ValidationException::withMessages([
                'channels' => 'Select at least one delivery channel.',
            ]);
        }

        foreach ($normalized as $channel) {
            if (! in_array($channel, $valid, true)) {
                throw ValidationException::withMessages([
                    'channels' => 'Invalid delivery channel.',
                ]);
            }
        }
    }
}
