<?php

namespace App\Support\CrewOperations;

use App\Models\CrewPlanningAssignment;
use App\Models\EmployeeDeployment;
use App\Models\User;
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

        return Activity::query()
            ->where('company_id', $companyId)
            ->whereIn('subject_type', [
                EmployeeDeployment::class,
                CrewPlanningAssignment::class,
            ])
            ->with(['causer:id,name,email'])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (Activity $log) => [
                'id' => $log->id,
                'event' => $log->event,
                'description' => $log->description ?? '',
                'causer' => $log->causer ? [
                    'id' => $log->causer->id,
                    'name' => $log->causer->name,
                    'email' => $log->causer->email,
                ] : null,
                'old_values' => $log->attribute_changes?->get('old'),
                'new_values' => $log->attribute_changes?->get('attributes'),
                'created_at' => $log->created_at,
            ])
            ->all();
    }
}
