<?php

namespace App\Support\Activity;

use App\Models\RecruitmentCandidate;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

final class RecentActivityQuery
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
    public static function for(
        ?User $user,
        int $companyId,
        string $subjectType,
        int $subjectId,
        int $limit = 5,
    ): array {
        if (! $user?->can('audit.view')) {
            return [];
        }

        $logs = Activity::query()
            ->where('company_id', $companyId)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->with(['causer:id,name,email'])
            ->latest('id')
            ->limit($limit)
            ->get();

        $presented = ActivityChangePresenter::presentLogs($logs, $companyId, $user)
            ->map(fn (Activity $log): array => ActivityChangePresenter::toRecentActivityArray($log));

        if ($subjectType === RecruitmentCandidate::class && ($user === null || ! $user->can('employees.view'))) {
            $presented = $presented->map(function (array $item): array {
                if (isset($item['old_values']) && is_array($item['old_values']) && array_key_exists('employee_id', $item['old_values'])) {
                    $item['old_values']['employee_id'] = null;
                }
                if (isset($item['new_values']) && is_array($item['new_values']) && array_key_exists('employee_id', $item['new_values'])) {
                    $item['new_values']['employee_id'] = null;
                }

                return $item;
            });
        }

        return $presented->values()->all();
    }
}
