<?php

namespace App\Support\Announcements\Actions;

use App\Enums\AnnouncementChannel;
use App\Enums\AnnouncementDeliveryStatus;
use App\Enums\AnnouncementStatus;
use App\Jobs\DeliverAnnouncementEmailJob;
use App\Jobs\DeliverAnnouncementWhatsAppJob;
use App\Models\Announcement;
use App\Models\AnnouncementDelivery;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class RetryFailedAnnouncementDeliveries
{
    public function handle(Announcement $announcement, User $user, ?array $deliveryIds = null): int
    {
        if (! in_array($announcement->status, [
            AnnouncementStatus::Published,
            AnnouncementStatus::PartiallyDelivered,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => 'Only published announcements can retry deliveries.',
            ]);
        }

        $query = AnnouncementDelivery::query()
            ->where('company_id', $announcement->company_id)
            ->where('status', AnnouncementDeliveryStatus::Failed)
            ->whereIn('channel', [
                AnnouncementChannel::Email->value,
                AnnouncementChannel::WhatsApp->value,
            ])
            ->whereHas('recipient', fn ($q) => $q->where('announcement_id', $announcement->id));

        if ($deliveryIds !== null && $deliveryIds !== []) {
            $query->whereIn('id', $deliveryIds);
        }

        $deliveries = $query->get();
        $retried = 0;

        foreach ($deliveries as $delivery) {
            $delivery->update([
                'status' => AnnouncementDeliveryStatus::Queued,
                'queued_at' => now(),
                'failed_at' => null,
                'failure_reason' => null,
            ]);

            match ($delivery->channel) {
                AnnouncementChannel::Email => DeliverAnnouncementEmailJob::dispatch($delivery->id),
                AnnouncementChannel::WhatsApp => DeliverAnnouncementWhatsAppJob::dispatch($delivery->id),
                default => null,
            };

            $retried++;
        }

        if ($retried > 0) {
            activity()
                ->useLog('announcements')
                ->event('retry')
                ->causedBy($user)
                ->performedOn($announcement)
                ->withProperties(['retried' => $retried])
                ->tap(fn ($activity) => $activity->company_id = $announcement->company_id)
                ->log("Retried {$retried} failed delivery(ies)");
        }

        return $retried;
    }
}
