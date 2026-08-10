<?php

namespace App\Support\Notifications;

use App\Enums\AnnouncementChannel;
use App\Enums\AnnouncementDeliveryStatus;
use App\Enums\AnnouncementStatus;
use App\Enums\CrewOperationalAlertStatus;
use App\Models\AnnouncementRecipient;
use App\Models\CrewOperationalAlertRecipient;
use App\Models\User;
use App\Support\CrewOperations\ResolveCrewOperationalAlertUrl;
use Illuminate\Support\Collection;

/**
 * Builds a normalized notification feed combining announcements and Crew operational alerts.
 */
final class BuildUnifiedNotificationFeed
{
    public function __construct(
        private readonly ResolveCrewOperationalAlertUrl $resolveCrewUrl = new ResolveCrewOperationalAlertUrl,
    ) {}

    /**
     * @return array{
     *     unread_count: int,
     *     items: list<array{
     *         id: string,
     *         source: 'announcement'|'crew_operational_alert',
     *         title: string|null,
     *         summary: string,
     *         severity: string|null,
     *         created_at: string|null,
     *         read_at: string|null,
     *         is_read: bool,
     *         url: string|null,
     *         source_label: string
     *     }>
     * }
     */
    public function forUser(User $user, int $companyId, int $limit = 20): array
    {
        $announcementItems = $this->announcementItems($user, $companyId);
        $crewItems = $this->crewItems($user, $companyId);

        $items = $announcementItems
            ->concat($crewItems)
            ->sortByDesc(fn (array $item): string => $item['created_at'] ?? '')
            ->take($limit)
            ->values()
            ->all();

        return [
            'unread_count' => $this->unreadAnnouncementCount($user, $companyId)
                + $this->unreadCrewCount($user, $companyId),
            'items' => $items,
        ];
    }

    /**
     * @return Collection<int, array{
     *     id: string,
     *     source: 'announcement',
     *     title: string|null,
     *     summary: string,
     *     severity: string|null,
     *     created_at: string|null,
     *     read_at: string|null,
     *     is_read: bool,
     *     url: string|null,
     *     source_label: string
     * }>
     */
    private function announcementItems(User $user, int $companyId): Collection
    {
        return AnnouncementRecipient::query()
            ->where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->whereHas('announcement', fn ($q) => $q->whereIn('status', [
                AnnouncementStatus::Published->value,
                AnnouncementStatus::PartiallyDelivered->value,
            ]))
            ->whereHas('deliveries', fn ($q) => $q->where('channel', AnnouncementChannel::InApp->value)
                ->whereNotIn('status', [AnnouncementDeliveryStatus::Skipped->value]))
            ->with(['announcement:id,title,body_html,priority,published_at'])
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (AnnouncementRecipient $recipient): array => [
                'id' => 'announcement:'.$recipient->id,
                'source' => 'announcement',
                'title' => $recipient->announcement?->title,
                'summary' => str($recipient->announcement?->body_html ?? '')->stripTags()->limit(100)->toString(),
                'severity' => $recipient->announcement?->priority->value,
                'created_at' => $recipient->announcement?->published_at?->toIso8601String()
                    ?? $recipient->created_at?->toIso8601String(),
                'read_at' => $recipient->read_at?->toIso8601String(),
                'is_read' => $recipient->read_at !== null,
                'url' => route('organization.announcements.inbox.show', $recipient),
                'source_label' => 'Announcement',
            ]);
    }

    /**
     * @return Collection<int, array{
     *     id: string,
     *     source: 'crew_operational_alert',
     *     title: string|null,
     *     summary: string,
     *     severity: string|null,
     *     created_at: string|null,
     *     read_at: string|null,
     *     is_read: bool,
     *     url: string|null,
     *     source_label: string
     * }>
     */
    private function crewItems(User $user, int $companyId): Collection
    {
        return CrewOperationalAlertRecipient::query()
            ->where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->whereHas('alert')
            ->with(['alert'])
            ->latest('id')
            ->limit(20)
            ->get()
            ->filter(fn (CrewOperationalAlertRecipient $recipient): bool => $recipient->alert !== null)
            ->map(function (CrewOperationalAlertRecipient $recipient) use ($user): array {
                $alert = $recipient->alert;

                return [
                    'id' => 'crew_operational_alert:'.$recipient->id,
                    'source' => 'crew_operational_alert',
                    'title' => $alert?->title,
                    'summary' => (string) ($alert?->message ?? ''),
                    'severity' => $alert?->severity->value,
                    'created_at' => $alert?->last_detected_at?->toIso8601String()
                        ?? $recipient->created_at?->toIso8601String(),
                    'read_at' => $recipient->read_at?->toIso8601String(),
                    'is_read' => $recipient->read_at !== null,
                    'url' => $alert !== null
                        ? $this->resolveCrewUrl->forUser($user, $alert)
                        : null,
                    'source_label' => 'Crew Operations',
                ];
            });
    }

    private function unreadAnnouncementCount(User $user, int $companyId): int
    {
        return AnnouncementRecipient::query()
            ->where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->whereHas('announcement', fn ($q) => $q->whereIn('status', [
                AnnouncementStatus::Published->value,
                AnnouncementStatus::PartiallyDelivered->value,
            ]))
            ->whereHas('deliveries', fn ($q) => $q->where('channel', AnnouncementChannel::InApp->value)
                ->whereNotIn('status', [AnnouncementDeliveryStatus::Skipped->value]))
            ->count();
    }

    private function unreadCrewCount(User $user, int $companyId): int
    {
        return CrewOperationalAlertRecipient::query()
            ->where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->whereHas('alert', fn ($q) => $q->where('status', CrewOperationalAlertStatus::Active->value))
            ->count();
    }
}
