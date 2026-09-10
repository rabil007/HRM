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
use App\Support\Announcements\ResolveAnnouncementWhatsAppTemplate;
use App\Support\Announcements\SanitizeAnnouncementHtml;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PersistAnnouncement
{
    public function __construct(
        private ResolveAnnouncementAudience $resolveAudience,
        private ResolveAnnouncementWhatsAppTemplate $resolveWhatsAppTemplate,
    ) {}

    /**
     * @param  array{
     *     title: string,
     *     body_html: string,
     *     category: string,
     *     priority: string,
     *     channels: list<string>,
     *     whatsapp_link?: string|null,
     *     whatsapp_message?: string|null,
     *     whatsapp_template_id?: int|null,
     *     audiences: list<array{type: string, id?: int|null}>,
     *     expires_at?: string|null,
     *     publish_mode: string,
     *     scheduled_at?: string|null
     * }  $data
     */
    public function create(int $companyId, User $user, array $data): Announcement
    {
        $this->resolveAudience->assertAudiencesBelongToCompany($companyId, $data['audiences']);
        $data['audiences'] = $this->resolveAudience->normalizeAudiences($companyId, $data['audiences']);
        $whatsAppFields = $this->whatsAppFields($data);

        return DB::transaction(function () use ($companyId, $user, $data, $whatsAppFields): Announcement {
            $status = $this->statusForPublishMode($data['publish_mode']);
            $channels = array_values($data['channels']);

            $announcement = Announcement::query()->create([
                'company_id' => $companyId,
                'title' => $data['title'],
                'body_html' => SanitizeAnnouncementHtml::handle($data['body_html']),
                'category' => AnnouncementCategory::from($data['category']),
                'priority' => AnnouncementPriority::from($data['priority']),
                'status' => $status,
                'channels' => $channels,
                'whatsapp_link' => $whatsAppFields['whatsapp_link'],
                'whatsapp_message' => $whatsAppFields['whatsapp_message'],
                'whatsapp_template_id' => $whatsAppFields['whatsapp_template_id'],
                'scheduled_at' => $status === AnnouncementStatus::Scheduled ? $data['scheduled_at'] : null,
                'expires_at' => $data['expires_at'] ?? null,
                'created_by' => $user->id,
            ]);

            $this->syncAudiences($announcement, $companyId, $data['audiences']);

            return $announcement->fresh(['audiences', 'attachments', 'creator', 'whatsappTemplate']) ?? $announcement;
        });
    }

    /**
     * @param  array{
     *     title: string,
     *     body_html: string,
     *     category: string,
     *     priority: string,
     *     channels: list<string>,
     *     whatsapp_link?: string|null,
     *     whatsapp_message?: string|null,
     *     whatsapp_template_id?: int|null,
     *     audiences: list<array{type: string, id?: int|null}>,
     *     expires_at?: string|null,
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
        $data['audiences'] = $this->resolveAudience->normalizeAudiences((int) $announcement->company_id, $data['audiences']);
        $whatsAppFields = $this->whatsAppFields($data);

        return DB::transaction(function () use ($announcement, $data, $whatsAppFields): Announcement {
            $status = $this->statusForPublishMode($data['publish_mode']);
            $channels = array_values($data['channels']);

            $announcement->update([
                'title' => $data['title'],
                'body_html' => SanitizeAnnouncementHtml::handle($data['body_html']),
                'category' => AnnouncementCategory::from($data['category']),
                'priority' => AnnouncementPriority::from($data['priority']),
                'status' => $status === AnnouncementStatus::Draft || $status === AnnouncementStatus::Scheduled
                    ? $status
                    : $announcement->status,
                'channels' => $channels,
                'whatsapp_link' => $whatsAppFields['whatsapp_link'],
                'whatsapp_message' => $whatsAppFields['whatsapp_message'],
                'whatsapp_template_id' => $whatsAppFields['whatsapp_template_id'],
                'scheduled_at' => $status === AnnouncementStatus::Scheduled ? $data['scheduled_at'] : null,
                'expires_at' => $data['expires_at'] ?? null,
            ]);

            AnnouncementAudience::query()
                ->where('announcement_id', $announcement->id)
                ->delete();

            $this->syncAudiences($announcement, (int) $announcement->company_id, $data['audiences']);

            return $announcement->fresh(['audiences', 'attachments', 'creator', 'whatsappTemplate']) ?? $announcement;
        });
    }

    /**
     * @param  array{
     *     channels: list<string>,
     *     whatsapp_link?: string|null,
     *     whatsapp_message?: string|null,
     *     whatsapp_template_id?: int|null
     * }  $data
     * @return array{whatsapp_link: string|null, whatsapp_message: string|null, whatsapp_template_id: int|null}
     */
    private function whatsAppFields(array $data): array
    {
        $channels = array_values(array_map('strval', $data['channels'] ?? []));
        $usesWhatsApp = in_array(AnnouncementChannel::WhatsApp->value, $channels, true);

        if (! $usesWhatsApp) {
            return [
                'whatsapp_link' => null,
                'whatsapp_message' => null,
                'whatsapp_template_id' => null,
            ];
        }

        $templateId = array_key_exists('whatsapp_template_id', $data) && $data['whatsapp_template_id'] !== null
            ? (int) $data['whatsapp_template_id']
            : null;

        if ($templateId !== null && $this->resolveWhatsAppTemplate->findEnabledAnnouncementTemplate($templateId) === null) {
            throw ValidationException::withMessages([
                'whatsapp_template_id' => 'Select an enabled Announcement WhatsApp template.',
            ]);
        }

        $message = isset($data['whatsapp_message']) ? trim((string) $data['whatsapp_message']) : '';

        return [
            'whatsapp_link' => $data['whatsapp_link'] ?? null,
            'whatsapp_message' => $message !== '' ? $message : null,
            'whatsapp_template_id' => $templateId,
        ];
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
