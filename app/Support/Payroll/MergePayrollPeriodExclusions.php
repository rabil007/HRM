<?php

namespace App\Support\Payroll;

use App\Models\User;
use App\Support\Employees\EmployeeVisibilityScope;

final class MergePayrollPeriodExclusions
{
    /**
     * Merge persisted and submitted payroll exclusions for a generation run.
     *
     * Restricted actors may change only exclusions for employees they can access.
     * Hidden existing exclusions are preserved exactly.
     *
     * @param  list<int>  $existingExcludedEmployeeIds
     * @param  list<int>  $submittedExcludedEmployeeIds
     * @return list<int>
     */
    public static function resolve(
        array $existingExcludedEmployeeIds,
        array $submittedExcludedEmployeeIds,
        ?User $user,
        int $companyId,
    ): array {
        $existing = self::normalizeIds($existingExcludedEmployeeIds);
        $submitted = self::normalizeIds($submittedExcludedEmployeeIds);

        if ($user === null || EmployeeVisibilityScope::hasUnrestrictedAccess($user, $companyId)) {
            return self::normalizeIds(array_merge($existing, $submitted));
        }

        $authorizedExisting = EmployeeVisibilityScope::filterAuthorizedEmployeeIds($user, $companyId, $existing);
        $hiddenExisting = array_values(array_diff($existing, $authorizedExisting));
        $authorizedSubmitted = EmployeeVisibilityScope::filterAuthorizedEmployeeIds($user, $companyId, $submitted);

        return self::normalizeIds(array_merge($hiddenExisting, $authorizedSubmitted));
    }

    /**
     * Exclusions whose payroll records may be removed during generation.
     *
     * @param  list<int>  $mergedExcludedEmployeeIds
     * @return list<int>
     */
    public static function enforceableForActor(
        array $mergedExcludedEmployeeIds,
        ?User $user,
        int $companyId,
    ): array {
        $merged = self::normalizeIds($mergedExcludedEmployeeIds);

        if ($user === null || EmployeeVisibilityScope::hasUnrestrictedAccess($user, $companyId)) {
            return $merged;
        }

        return EmployeeVisibilityScope::filterAuthorizedEmployeeIds($user, $companyId, $merged);
    }

    /**
     * @param  list<int>  $employeeIds
     * @return list<int>
     */
    private static function normalizeIds(array $employeeIds): array
    {
        return array_values(array_unique(array_filter(
            array_map(intval(...), $employeeIds),
            fn (int $id): bool => $id > 0,
        )));
    }
}
