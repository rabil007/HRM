<?php

namespace App\Support\Documents\RecipientRequests\Delivery;

use App\Enums\DocumentRecipientRequestDeliveryStatus;
use App\Models\DocumentRecipientRequestDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ClaimDocumentRecipientRequestEmailDeliveries
{
    public const STALE_CLAIM_TIMEOUT_MINUTES = 5;

    /**
     * @param  list<int>  $deliveryIds
     * @return Collection<int, DocumentRecipientRequestDelivery>
     */
    public static function claimByIds(array $deliveryIds): Collection
    {
        if ($deliveryIds === []) {
            return collect();
        }

        return DB::transaction(function () use ($deliveryIds): Collection {
            $deliveries = DocumentRecipientRequestDelivery::query()
                ->whereIn('id', $deliveryIds)
                ->where('status', DocumentRecipientRequestDeliveryStatus::Queued)
                ->whereNull('dispatched_at')
                ->whereNull('revoked_at')
                ->where(function (Builder $query): void {
                    $query->whereNull('claimed_at')
                        ->orWhere(
                            'claimed_at',
                            '<',
                            CarbonImmutable::now()->subMinutes(self::STALE_CLAIM_TIMEOUT_MINUTES),
                        );
                })
                ->lockForUpdate()
                ->get();

            if ($deliveries->isEmpty()) {
                return collect();
            }

            $claimedIds = $deliveries->pluck('id')->map(fn ($id): int => (int) $id)->all();

            DocumentRecipientRequestDelivery::query()
                ->whereIn('id', $claimedIds)
                ->update([
                    'claimed_at' => CarbonImmutable::now(),
                ]);

            return $deliveries;
        });
    }

    /**
     * @param  list<int>  $deliveryIds
     */
    public static function markDispatched(array $deliveryIds): void
    {
        if ($deliveryIds === []) {
            return;
        }

        DocumentRecipientRequestDelivery::query()
            ->whereIn('id', $deliveryIds)
            ->update([
                'dispatched_at' => CarbonImmutable::now(),
                'claimed_at' => null,
            ]);
    }

    /**
     * @param  list<int>  $deliveryIds
     */
    public static function releaseClaim(array $deliveryIds): void
    {
        if ($deliveryIds === []) {
            return;
        }

        DocumentRecipientRequestDelivery::query()
            ->whereIn('id', $deliveryIds)
            ->whereNull('dispatched_at')
            ->update([
                'claimed_at' => null,
            ]);
    }
}
