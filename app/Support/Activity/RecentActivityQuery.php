<?php

namespace App\Support\Activity;

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

        return Activity::query()
            ->where('company_id', $companyId)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->with(['causer:id,name,email'])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (Activity $log) => [
                'id' => $log->id,
                'event' => $log->event,
                'description' => $log->description,
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
