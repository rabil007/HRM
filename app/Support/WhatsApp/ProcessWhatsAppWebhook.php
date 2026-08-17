<?php

namespace App\Support\WhatsApp;

use App\Enums\AnnouncementChannel;
use App\Enums\AnnouncementDeliveryStatus;
use App\Models\AnnouncementDelivery;
use App\Models\WhatsAppSetting;
use App\Support\Announcements\Actions\RefreshAnnouncementDeliveryStatus;

final class ProcessWhatsAppWebhook
{
    public function __construct(private RefreshAnnouncementDeliveryStatus $refreshStatus) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload, WhatsAppSetting $settings): void
    {
        if (($payload['object'] ?? null) !== 'whatsapp_business_account') {
            return;
        }

        $entries = $payload['entry'] ?? [];

        if (! is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if (! is_array($entry) || ! $this->entryBelongsToIntegration($entry, $settings)) {
                continue;
            }

            $changes = $entry['changes'] ?? [];

            if (! is_array($changes)) {
                continue;
            }

            foreach ($changes as $change) {
                if (! is_array($change) || ! $this->changeBelongsToIntegration($change, $settings)) {
                    continue;
                }

                $statuses = $change['value']['statuses'] ?? [];

                if (! is_array($statuses)) {
                    continue;
                }

                foreach ($statuses as $statusPayload) {
                    if (is_array($statusPayload)) {
                        $this->applyStatus($statusPayload);
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function entryBelongsToIntegration(array $entry, WhatsAppSetting $settings): bool
    {
        return (string) ($entry['id'] ?? '') === (string) $settings->business_account_id;
    }

    /**
     * @param  array<string, mixed>  $change
     */
    private function changeBelongsToIntegration(array $change, WhatsAppSetting $settings): bool
    {
        return ($change['field'] ?? null) === 'messages'
            && ($change['value']['messaging_product'] ?? null) === 'whatsapp'
            && (string) ($change['value']['metadata']['phone_number_id'] ?? '') === (string) $settings->phone_number_id;
    }

    /**
     * @param  array<string, mixed>  $statusPayload
     */
    private function applyStatus(array $statusPayload): void
    {
        $messageId = (string) ($statusPayload['id'] ?? '');
        $status = strtolower((string) ($statusPayload['status'] ?? ''));

        if ($messageId === '' || $status === '') {
            return;
        }

        $deliveries = AnnouncementDelivery::query()
            ->where('channel', AnnouncementChannel::WhatsApp)
            ->where('provider_reference', $messageId)
            ->with('recipient.announcement')
            ->limit(2)
            ->get();

        if ($deliveries->count() !== 1) {
            return;
        }

        $delivery = $deliveries->first();
        $recipient = $delivery?->recipient;
        $announcement = $recipient?->announcement;

        if (
            $delivery === null
            || $recipient === null
            || $announcement === null
            || (int) $delivery->company_id <= 0
            || (int) $delivery->company_id !== (int) $recipient->company_id
            || (int) $delivery->company_id !== (int) $announcement->company_id
        ) {
            return;
        }

        $mapped = match ($status) {
            'sent' => AnnouncementDeliveryStatus::Sent,
            'delivered' => AnnouncementDeliveryStatus::Delivered,
            'read' => AnnouncementDeliveryStatus::Read,
            'failed' => AnnouncementDeliveryStatus::Failed,
            default => null,
        };

        if ($mapped === null) {
            return;
        }

        if (! $this->canApplyStatus($delivery->status, $mapped)) {
            return;
        }

        $updates = [
            'status' => $mapped,
        ];

        if ($mapped === AnnouncementDeliveryStatus::Delivered) {
            $updates['delivered_at'] = now();
        }

        if ($mapped === AnnouncementDeliveryStatus::Read) {
            $updates['read_at'] = now();
            $updates['delivered_at'] = $delivery->delivered_at ?? now();
        }

        if ($mapped === AnnouncementDeliveryStatus::Failed) {
            $updates['failed_at'] = now();
            $updates['failure_reason'] = 'WhatsApp provider reported failure.';
        }

        if ($mapped === AnnouncementDeliveryStatus::Sent && $delivery->sent_at === null) {
            $updates['sent_at'] = now();
        }

        $delivery->update($updates);
        $this->refreshStatus->handle($announcement);
    }

    private function canApplyStatus(
        AnnouncementDeliveryStatus $current,
        AnnouncementDeliveryStatus $incoming,
    ): bool {
        if ($current === $incoming) {
            return false;
        }

        return match ($current) {
            AnnouncementDeliveryStatus::Pending,
            AnnouncementDeliveryStatus::Queued => in_array($incoming, [
                AnnouncementDeliveryStatus::Sent,
                AnnouncementDeliveryStatus::Delivered,
                AnnouncementDeliveryStatus::Read,
                AnnouncementDeliveryStatus::Failed,
            ], true),
            AnnouncementDeliveryStatus::Sent => in_array($incoming, [
                AnnouncementDeliveryStatus::Delivered,
                AnnouncementDeliveryStatus::Read,
                AnnouncementDeliveryStatus::Failed,
            ], true),
            AnnouncementDeliveryStatus::Delivered => $incoming === AnnouncementDeliveryStatus::Read,
            AnnouncementDeliveryStatus::Read,
            AnnouncementDeliveryStatus::Failed,
            AnnouncementDeliveryStatus::Skipped => false,
        };
    }
}
