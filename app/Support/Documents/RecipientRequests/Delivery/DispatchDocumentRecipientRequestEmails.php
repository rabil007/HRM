<?php

namespace App\Support\Documents\RecipientRequests\Delivery;

use App\Enums\DocumentRecipientRequestDeliveryChannel;
use App\Enums\DocumentRecipientRequestDeliveryStatus;
use App\Enums\DocumentRecipientType;
use App\Jobs\DeliverDocumentRecipientRequestEmailJob;
use App\Models\DocumentRecipientRequestDelivery;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestToken;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DispatchDocumentRecipientRequestEmails
{
    /**
     * Reconcile queued deliveries that never completed queue handoff, and repair
     * Sent ledger rows after a remembered successful SMTP handoff.
     *
     * @return array{dispatched: int, skipped: int, repaired: int}
     */
    public function dispatchPending(?int $onlyCompanyId = null): array
    {
        $repaired = $this->repairRememberedSmtpHandoffs($onlyCompanyId);

        $query = DocumentRecipientRequestDelivery::query()
            ->where('channel', DocumentRecipientRequestDeliveryChannel::Email)
            ->where('status', DocumentRecipientRequestDeliveryStatus::Queued)
            ->whereNull('dispatched_at')
            ->whereNull('revoked_at')
            ->orderBy('id')
            ->limit(100);

        if ($onlyCompanyId !== null) {
            $query->where('company_id', $onlyCompanyId);
        }

        $ids = $query->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $dispatched = 0;
        $skipped = 0;

        foreach ($ids as $deliveryId) {
            try {
                if ($this->dispatchDelivery($deliveryId)) {
                    $dispatched++;
                } else {
                    $skipped++;
                }
            } catch (Throwable $exception) {
                $skipped++;
                Log::warning('Document recipient email dispatch reconciliation failed', [
                    'delivery_id' => $deliveryId,
                    'exception_class' => $exception::class,
                ]);
            }
        }

        return [
            'dispatched' => $dispatched,
            'skipped' => $skipped,
            'repaired' => $repaired,
        ];
    }

    /**
     * Persist Sent for deliveries where SMTP already succeeded but the ledger did not.
     */
    public function repairRememberedSmtpHandoffs(?int $onlyCompanyId = null): int
    {
        $query = DocumentRecipientRequestDelivery::query()
            ->where('channel', DocumentRecipientRequestDeliveryChannel::Email)
            ->where('status', DocumentRecipientRequestDeliveryStatus::Queued)
            ->whereNotNull('dispatched_at')
            ->whereNull('revoked_at')
            ->orderBy('id')
            ->limit(100);

        if ($onlyCompanyId !== null) {
            $query->where('company_id', $onlyCompanyId);
        }

        $repaired = 0;

        foreach ($query->get() as $delivery) {
            $handoffKey = DocumentRecipientRequestDeliveryHandoff::emailKey((int) $delivery->id);

            if (! DocumentRecipientRequestDeliveryHandoff::wasHandedOff($handoffKey)) {
                continue;
            }

            $persisted = DocumentRecipientRequestDeliveryHandoff::persistLedger(
                function () use ($delivery): void {
                    $delivery->refresh();
                    $delivery->update([
                        'status' => DocumentRecipientRequestDeliveryStatus::Sent,
                        'sent_at' => now(),
                        'failed_at' => null,
                        'failure_category' => null,
                    ]);
                },
                [
                    'company_id' => (int) $delivery->company_id,
                    'delivery_id' => (int) $delivery->id,
                    'failure_category' => 'email_ledger_persist',
                ],
            );

            if ($persisted) {
                $repaired++;
            }
        }

        return $repaired;
    }

    public function dispatchDelivery(int $deliveryId, ?string $rawAccessToken = null): bool
    {
        $queueHandoffKey = DocumentRecipientRequestDeliveryHandoff::queueKey($deliveryId);

        if (DocumentRecipientRequestDeliveryHandoff::wasHandedOff($queueHandoffKey)) {
            DocumentRecipientRequestDeliveryHandoff::persistLedger(
                fn () => ClaimDocumentRecipientRequestEmailDeliveries::markDispatched([$deliveryId]),
                [
                    'delivery_id' => $deliveryId,
                    'failure_category' => 'queue_ledger_persist',
                ],
            );

            return true;
        }

        $claimed = ClaimDocumentRecipientRequestEmailDeliveries::claimByIds([$deliveryId]);

        if ($claimed->isEmpty()) {
            return false;
        }

        /** @var DocumentRecipientRequestDelivery $delivery */
        $delivery = $claimed->first();
        $delivery->loadMissing('recipientRequest');
        $request = $delivery->recipientRequest;

        if ($request === null || (int) $request->company_id !== (int) $delivery->company_id) {
            ClaimDocumentRecipientRequestEmailDeliveries::releaseClaim([$deliveryId]);

            return false;
        }

        if ($request->recipient_type === DocumentRecipientType::SubjectEmployee) {
            if ($rawAccessToken === null || $rawAccessToken === '') {
                $rawAccessToken = DocumentRecipientRequestToken::generate();
                $delivery->update([
                    'access_token_hash' => DocumentRecipientRequestToken::hash($rawAccessToken),
                ]);
            }
        } else {
            $rawAccessToken = null;
        }

        try {
            DeliverDocumentRecipientRequestEmailJob::dispatch(
                (int) $delivery->id,
                (int) $delivery->company_id,
                $rawAccessToken,
            );

            DocumentRecipientRequestDeliveryHandoff::remember($queueHandoffKey);
            DocumentRecipientRequestDeliveryHandoff::persistLedger(
                fn () => ClaimDocumentRecipientRequestEmailDeliveries::markDispatched([$deliveryId]),
                [
                    'company_id' => (int) $delivery->company_id,
                    'delivery_id' => $deliveryId,
                    'failure_category' => 'queue_ledger_persist',
                ],
            );

            return true;
        } catch (Throwable $exception) {
            ClaimDocumentRecipientRequestEmailDeliveries::releaseClaim([$deliveryId]);

            throw $exception;
        }
    }
}
