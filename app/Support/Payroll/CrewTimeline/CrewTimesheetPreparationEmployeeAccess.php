<?php

namespace App\Support\Payroll\CrewTimeline;

use App\Models\CrewTimesheetPreparation;
use App\Models\CrewTimesheetPreparationLine;
use App\Models\User;
use App\Support\Employees\EmployeeVisibilityScope;
use Illuminate\Validation\ValidationException;

final class CrewTimesheetPreparationEmployeeAccess
{
    public function assertActorCanAccessAllPreparationEmployees(
        CrewTimesheetPreparation $preparation,
        User $actor,
        int $companyId,
    ): void {
        if (EmployeeVisibilityScope::hasUnrestrictedAccess($actor, $companyId)) {
            return;
        }

        $employeeIds = CrewTimesheetPreparationLine::query()
            ->where('company_id', $companyId)
            ->where('crew_timesheet_preparation_id', $preparation->id)
            ->whereNotNull('employee_id')
            ->distinct()
            ->pluck('employee_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        if ($employeeIds === []) {
            return;
        }

        $authorizedIds = EmployeeVisibilityScope::filterAuthorizedEmployeeIds($actor, $companyId, $employeeIds);

        if (count($authorizedIds) !== count($employeeIds)) {
            throw ValidationException::withMessages([
                'preparation' => 'You cannot perform this action because this preparation contains employees outside your authorized employee scope.',
            ]);
        }
    }
}
