<?php

namespace App\Support\CrewOperations;

use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\User;
use App\Support\Activity\ActivityChangePresenter;
use Spatie\Activitylog\Models\Activity;

final class CrewOperationsRecentActivityQuery
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
    public static function forCompany(?User $user, int $companyId, int $limit = 10): array
    {
        if (! $user?->can('audit.view')) {
            return [];
        }

        $logs = Activity::query()
            ->where('company_id', $companyId)
            ->whereIn('subject_type', [
                CrewAssignment::class,
                CrewPlanningAssignment::class,
            ])
            ->with(['causer:id,name,email'])
            ->latest('id')
            ->limit($limit)
            ->get();

        return ActivityChangePresenter::presentLogs($logs, $companyId)
            ->map(function (Activity $log): array {
                $row = ActivityChangePresenter::toRecentActivityArray($log);
                $row['description'] = $log->description ?? '';

                return $row;
            })
            ->values()
            ->all();
    }
}
