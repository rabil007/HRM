<?php

namespace App\Support\VesselManning;

use App\Models\User;
use App\Models\VesselManning;
use App\Support\Activity\ActivityChangePresenter;
use Spatie\Activitylog\Models\Activity;

final class VesselManningRecentActivityQuery
{
    /**
     * @return list<array{
     *     id: int,
     *     event: string|null,
     *     description: string|null,
     *     causer: array{id: int, name: string, email: string}|null,
     *     old_values: mixed,
     *     new_values: mixed,
     *     created_at: mixed
     * }>
     */
    public static function forVessel(
        ?User $user,
        int $companyId,
        int $vesselId,
        int $limit = 20,
    ): array {
        if (! $user?->can('audit.view')) {
            return [];
        }

        $manningIds = VesselManning::query()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->where('vessel_id', $vesselId)
            ->pluck('id');

        if ($manningIds->isEmpty()) {
            return [];
        }

        $logs = Activity::query()
            ->where('company_id', $companyId)
            ->where('subject_type', VesselManning::class)
            ->whereIn('subject_id', $manningIds)
            ->with(['causer:id,name,email'])
            ->latest('id')
            ->limit($limit)
            ->get();

        return ActivityChangePresenter::presentLogs($logs, $companyId)
            ->map(fn (Activity $log): array => ActivityChangePresenter::toRecentActivityArray($log))
            ->values()
            ->all();
    }
}
