<?php

namespace App\Support\CrewOperations;

use App\Enums\CrewOperationalAlertEmailDeliveryStatus;
use App\Models\CrewOperationalAlertEmailDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ClaimCrewOperationalAlertEmailDeliveries
{
    /**
     * Stale claim timeout in minutes.
     * If a worker crashed or was killed between claim and enqueue finalize,
     * the claim will expire after 5 minutes and become eligible for retry.
     */
    public const STALE_CLAIM_TIMEOUT_MINUTES = 5;

    /**
     * Atomically claims eligible queued deliveries by their IDs.
     *
     * @param  list<int>  $deliveryIds
     * @return Collection<int, CrewOperationalAlertEmailDelivery>
     */
    public static function claimByIds(array $deliveryIds): Collection
    {
        if ($deliveryIds === []) {
            return collect();
        }

        return DB::transaction(function () use ($deliveryIds): Collection {
            $deliveries = CrewOperationalAlertEmailDelivery::query()
                ->with('alert')
                ->whereIn('id', $deliveryIds)
                ->where('status', CrewOperationalAlertEmailDeliveryStatus::Queued)
                ->whereNull('dispatched_at')
                ->where(function (Builder $query): void {
                    $query->whereNull('dispatch_claimed_at')
                        ->orWhere('dispatch_claimed_at', '<', CarbonImmutable::now()->subMinutes(self::STALE_CLAIM_TIMEOUT_MINUTES));
                })
                ->lockForUpdate()
                ->get();

            if ($deliveries->isEmpty()) {
                return collect();
            }

            $claimedIds = $deliveries->pluck('id')->map(fn ($id): int => (int) $id)->all();

            CrewOperationalAlertEmailDelivery::query()
                ->whereIn('id', $claimedIds)
                ->update([
                    'dispatch_claimed_at' => CarbonImmutable::now(),
                ]);

            return $deliveries;
        });
    }

    /**
     * Finalizes successful dispatch: sets dispatched_at = now() and clears dispatch_claimed_at.
     *
     * @param  list<int>  $deliveryIds
     */
    public static function markDispatched(array $deliveryIds): void
    {
        if ($deliveryIds === []) {
            return;
        }

        CrewOperationalAlertEmailDelivery::query()
            ->whereIn('id', $deliveryIds)
            ->update([
                'dispatched_at' => CarbonImmutable::now(),
                'dispatch_claimed_at' => null,
            ]);
    }

    /**
     * Releases an in-flight claim immediately (e.g. after dispatch exception),
     * allowing immediate retry on next execution without waiting for timeout.
     *
     * @param  list<int>  $deliveryIds
     */
    public static function releaseClaim(array $deliveryIds): void
    {
        if ($deliveryIds === []) {
            return;
        }

        CrewOperationalAlertEmailDelivery::query()
            ->whereIn('id', $deliveryIds)
            ->whereNull('dispatched_at')
            ->update([
                'dispatch_claimed_at' => null,
            ]);
    }
}
