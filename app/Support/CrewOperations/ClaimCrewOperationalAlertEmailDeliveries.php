<?php

namespace App\Support\CrewOperations;

use App\Enums\CrewOperationalAlertEmailDeliveryStatus;
use App\Models\CrewOperationalAlertEmailDelivery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ClaimCrewOperationalAlertEmailDeliveries
{
    /**
     * Reclaim threshold: if a delivery was claimed > 1 hour ago but is still in queued status,
     * it is considered abandoned (e.g. queue worker crashed / killed before execution) and can be reclaimed.
     */
    public const ABANDONED_CLAIM_HOURS = 1;

    /**
     * Atomically claims candidate queued deliveries by their IDs.
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
                ->whereIn('id', $deliveryIds)
                ->where('status', CrewOperationalAlertEmailDeliveryStatus::Queued)
                ->where(function (Builder $query): void {
                    $query->whereNull('dispatched_at')
                        ->orWhere('dispatched_at', '<', now()->subHours(self::ABANDONED_CLAIM_HOURS));
                })
                ->lockForUpdate()
                ->get();

            if ($deliveries->isEmpty()) {
                return collect();
            }

            $ids = $deliveries->pluck('id')->map(fn ($id): int => (int) $id)->all();

            CrewOperationalAlertEmailDelivery::query()
                ->whereIn('id', $ids)
                ->update(['dispatched_at' => now()]);

            return $deliveries;
        });
    }

    /**
     * Atomically claims all eligible queued deliveries for a company for scheduled digest.
     *
     * @return Collection<int, CrewOperationalAlertEmailDelivery>
     */
    public static function claimForCompany(int $companyId): Collection
    {
        return DB::transaction(function () use ($companyId): Collection {
            $deliveries = CrewOperationalAlertEmailDelivery::query()
                ->where('company_id', $companyId)
                ->where('status', CrewOperationalAlertEmailDeliveryStatus::Queued)
                ->where(function (Builder $query): void {
                    $query->whereNull('dispatched_at')
                        ->orWhere('dispatched_at', '<', now()->subHours(self::ABANDONED_CLAIM_HOURS));
                })
                ->lockForUpdate()
                ->get();

            if ($deliveries->isEmpty()) {
                return collect();
            }

            $ids = $deliveries->pluck('id')->map(fn ($id): int => (int) $id)->all();

            CrewOperationalAlertEmailDelivery::query()
                ->whereIn('id', $ids)
                ->update(['dispatched_at' => now()]);

            return $deliveries;
        });
    }
}
