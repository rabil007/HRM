<?php

namespace App\Support\CrewMovements\Scheduling;

use App\Models\CrewAssignment;
use App\Models\CrewScheduledMovement;
use App\Models\User;
use App\Support\CrewMovements\CrewAssignmentAccess;
use App\Support\Employees\EmployeeVisibilityScope;
use Illuminate\Database\Eloquent\Builder;

final class CrewScheduledMovementAccess
{
    /**
     * @param  Builder<CrewScheduledMovement>  $query
     * @return Builder<CrewScheduledMovement>
     */
    public static function applyScope(Builder $query, int $companyId, ?User $user = null): Builder
    {
        $query->where('crew_scheduled_movements.company_id', $companyId);

        if ($user !== null) {
            EmployeeVisibilityScope::whereHas($query, $user, $companyId, 'employee');
        }

        return $query;
    }

    public static function findForCompany(int $companyId, int $id, ?User $user = null): ?CrewScheduledMovement
    {
        /** @var CrewScheduledMovement|null $schedule */
        $schedule = self::applyScope(CrewScheduledMovement::query(), $companyId, $user)
            ->whereKey($id)
            ->first();

        return $schedule;
    }

    public static function assertInCompany(
        CrewScheduledMovement $schedule,
        int $companyId,
        ?User $user = null,
    ): void {
        abort_unless((int) $schedule->company_id === $companyId, 404);

        if ($user !== null) {
            $schedule->loadMissing('employee');
            abort_unless(
                $schedule->employee !== null
                    && EmployeeVisibilityScope::canAccess($user, $schedule->employee, $companyId),
                404,
            );
        }
    }

    public static function assertAssignmentAccessible(
        CrewAssignment $assignment,
        int $companyId,
        ?User $user = null,
    ): void {
        CrewAssignmentAccess::assertInCompany($assignment, $companyId, $user);
    }

    public static function canView(?User $user): bool
    {
        return $user?->can('crew_operations.movements.schedule.view') ?? false;
    }

    public static function canSchedule(?User $user): bool
    {
        return $user?->can('crew_operations.movements.schedule') ?? false;
    }

    public static function canManage(?User $user): bool
    {
        return $user?->can('crew_operations.movements.schedule.manage') ?? false;
    }
}
