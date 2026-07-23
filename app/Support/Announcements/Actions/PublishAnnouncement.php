<?php

namespace App\Support\Announcements\Actions;

use App\Enums\AnnouncementChannel;
use App\Enums\AnnouncementDeliveryStatus;
use App\Enums\AnnouncementStatus;
use App\Jobs\DeliverAnnouncementEmailJob;
use App\Jobs\DeliverAnnouncementInAppJob;
use App\Jobs\DeliverAnnouncementWhatsAppJob;
use App\Models\Announcement;
use App\Models\AnnouncementDelivery;
use App\Models\AnnouncementRecipient;
use App\Models\User;
use App\Services\WhatsAppService;
use App\Support\Announcements\ResolveAnnouncementAudience;
use App\Support\Announcements\ResolveEmployeeAnnouncementEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PublishAnnouncement
{
    public function __construct(
        private ResolveAnnouncementAudience $resolveAudience,
        private WhatsAppService $whatsApp,
    ) {}

    public function handle(Announcement $announcement, User $publisher): Announcement
    {
        if (! in_array($announcement->status, [AnnouncementStatus::Draft, AnnouncementStatus::Scheduled], true)) {
            throw ValidationException::withMessages([
                'status' => 'Only draft or scheduled announcements can be published.',
            ]);
        }

        return DB::transaction(function () use ($announcement, $publisher): Announcement {
            $locked = Announcement::query()
                ->whereKey($announcement->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($locked->status, [AnnouncementStatus::Draft, AnnouncementStatus::Scheduled], true)) {
                throw ValidationException::withMessages([
                    'status' => 'This announcement is already publishing or published.',
                ]);
            }

            $locked->update([
                'status' => AnnouncementStatus::Publishing,
                'published_by' => $publisher->id,
                'published_at' => now(),
                'scheduled_at' => null,
            ]);

            $audiences = $locked->audiences()
                ->get(['audience_type', 'audience_id'])
                ->map(fn ($row): array => [
                    'type' => $row->audience_type->value,
                    'id' => $row->audience_id,
                ])
                ->all();

            $employees = $this->resolveAudience->handle((int) $locked->company_id, $audiences);
            $channels = array_values(array_map('strval', $locked->channels ?? []));

            AnnouncementRecipient::query()
                ->where('announcement_id', $locked->id)
                ->delete();

            $deliveryIds = [];

            foreach ($employees as $employee) {
                $email = ResolveEmployeeAnnouncementEmail::for($employee);
                $phone = filled($employee->phone)
                    ? $this->whatsApp->normalizePhone((string) $employee->phone)
                    : null;

                $recipient = AnnouncementRecipient::query()->create([
                    'company_id' => $locked->company_id,
                    'announcement_id' => $locked->id,
                    'employee_id' => $employee->id,
                    'user_id' => $employee->user_id,
                    'employee_name' => (string) $employee->name,
                    'department_id' => $employee->department_id,
                    'email' => $email,
                    'phone' => $phone !== '' ? $phone : null,
                    'public_token' => Str::random(48),
                ]);

                foreach ($channels as $channelValue) {
                    $channel = AnnouncementChannel::from($channelValue);
                    $status = $this->initialStatus($channel, $recipient);

                    $delivery = AnnouncementDelivery::query()->create([
                        'company_id' => $locked->company_id,
                        'announcement_recipient_id' => $recipient->id,
                        'channel' => $channel,
                        'status' => $status,
                        'queued_at' => $status === AnnouncementDeliveryStatus::Queued ? now() : null,
                        'failed_at' => $status === AnnouncementDeliveryStatus::Skipped ? now() : null,
                        'failure_reason' => $status === AnnouncementDeliveryStatus::Skipped
                            ? $this->skipReason($channel)
                            : null,
                    ]);

                    if ($status === AnnouncementDeliveryStatus::Queued) {
                        $deliveryIds[] = [$channel, $delivery->id];
                    }
                }
            }

            $locked->update([
                'status' => AnnouncementStatus::Published,
            ]);

            activity()
                ->useLog('announcements')
                ->event('published')
                ->causedBy($publisher)
                ->performedOn($locked)
                ->tap(fn ($activity) => $activity->company_id = $locked->company_id)
                ->log('Announcement published');

            foreach ($deliveryIds as [$channel, $deliveryId]) {
                match ($channel) {
                    AnnouncementChannel::InApp => DeliverAnnouncementInAppJob::dispatch($deliveryId),
                    AnnouncementChannel::Email => DeliverAnnouncementEmailJob::dispatch($deliveryId),
                    AnnouncementChannel::WhatsApp => DeliverAnnouncementWhatsAppJob::dispatch($deliveryId),
                };
            }

            return $locked->fresh(['audiences', 'attachments', 'recipients.deliveries', 'creator', 'publisher']) ?? $locked;
        });
    }

    private function initialStatus(AnnouncementChannel $channel, AnnouncementRecipient $recipient): AnnouncementDeliveryStatus
    {
        return match ($channel) {
            AnnouncementChannel::InApp => $recipient->user_id !== null
                ? AnnouncementDeliveryStatus::Queued
                : AnnouncementDeliveryStatus::Skipped,
            AnnouncementChannel::Email => filled($recipient->email)
                ? AnnouncementDeliveryStatus::Queued
                : AnnouncementDeliveryStatus::Skipped,
            AnnouncementChannel::WhatsApp => filled($recipient->phone)
                ? AnnouncementDeliveryStatus::Queued
                : AnnouncementDeliveryStatus::Skipped,
        };
    }

    private function skipReason(AnnouncementChannel $channel): string
    {
        return match ($channel) {
            AnnouncementChannel::InApp => 'No linked user account.',
            AnnouncementChannel::Email => 'Missing email address.',
            AnnouncementChannel::WhatsApp => 'Missing or invalid phone number.',
        };
    }
}
