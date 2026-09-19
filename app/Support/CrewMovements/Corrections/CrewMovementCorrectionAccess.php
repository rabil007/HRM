<?php

namespace App\Support\CrewMovements\Corrections;

use App\Models\CrewAssignment;
use App\Models\CrewMovementCorrection;
use App\Models\Employee;
use App\Models\User;
use App\Support\Employees\EmployeeVisibilityScope;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CrewMovementCorrectionAccess
{
    public static function assertInCompany(CrewMovementCorrection $correction, int $companyId, ?User $user = null): void
    {
        if ((int) $correction->company_id !== $companyId) {
            throw new HttpException(404);
        }

        if ($user !== null) {
            $assignment = CrewAssignment::withTrashed()
                ->whereKey($correction->crew_assignment_id)
                ->where('company_id', $companyId)
                ->first();

            if ($assignment === null) {
                throw new HttpException(404);
            }

            $employee = Employee::withTrashed()
                ->whereKey($assignment->employee_id)
                ->where('company_id', $companyId)
                ->first();

            if ($employee === null) {
                throw new HttpException(404);
            }

            if (! EmployeeVisibilityScope::canAccess($user, $employee, $companyId)) {
                throw new HttpException(404);
            }
        }
    }

    public static function canSelfApprove(?User $user): bool
    {
        return $user?->can('crew_operations.corrections.override') ?? false;
    }

    public static function canApproveCorrection(?User $user, CrewMovementCorrection $correction): bool
    {
        if ($user === null || ! $user->can('crew_operations.corrections.approve')) {
            return false;
        }

        if ((int) $correction->requested_by === (int) $user->id) {
            return self::canSelfApprove($user);
        }

        return true;
    }
}
