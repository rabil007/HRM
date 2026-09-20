<?php

namespace App\Support\CrewMovements;

use App\Models\CrewAssignment;
use App\Models\User;
use App\Support\Employees\EmployeeVisibilityScope;
use Illuminate\Support\Collection;

final class CrewReliefVisibility
{
    /**
     * Resolve authorized relief employee IDs for a result set in one visibility query.
     *
     * @param  Collection<int, CrewAssignment>|list<CrewAssignment>  $assignments
     * @return list<int>|null null = trusted internal context (no authenticated viewer); array = authorized IDs
     */
    public static function authorizedReliefEmployeeIds(Collection|array $assignments, ?User $user, int $companyId): ?array
    {
        if ($user === null) {
            return null;
        }

        $collection = $assignments instanceof Collection ? $assignments : collect($assignments);

        $reliefEmployeeIds = $collection
            ->map(function (CrewAssignment $assignment): ?int {
                if (! $assignment->relief_readiness instanceof CrewReliefReadinessResult) {
                    return null;
                }

                $id = $assignment->relief_readiness->reliefEmployee['id'] ?? null;

                return $id !== null ? (int) $id : null;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        return self::authorizeReliefEmployeeIds($reliefEmployeeIds, $user, $companyId);
    }

    /**
     * @param  iterable<CrewReliefReadinessResult>  $reliefs
     * @return list<int>|null
     */
    public static function authorizedReliefEmployeeIdsFromResults(iterable $reliefs, ?User $user, int $companyId): ?array
    {
        if ($user === null) {
            return null;
        }

        $reliefEmployeeIds = [];

        foreach ($reliefs as $relief) {
            if (! $relief instanceof CrewReliefReadinessResult) {
                continue;
            }

            $id = $relief->reliefEmployee['id'] ?? null;

            if ($id !== null) {
                $reliefEmployeeIds[(int) $id] = true;
            }
        }

        return self::authorizeReliefEmployeeIds(array_keys($reliefEmployeeIds), $user, $companyId);
    }

    /**
     * @param  list<int>  $reliefEmployeeIds
     * @return list<int>
     */
    private static function authorizeReliefEmployeeIds(array $reliefEmployeeIds, User $user, int $companyId): array
    {
        if ($reliefEmployeeIds === []) {
            return [];
        }

        return EmployeeVisibilityScope::filterAuthorizedEmployeeIds($user, $companyId, $reliefEmployeeIds);
    }
}
