<?php

namespace App\Support\EmployeeTrainings;

use App\Models\Employee;
use App\Models\EmployeeTraining;
use App\Models\EmployeeTrainingVersion;
use App\Models\User;
use App\Support\Employees\EmployeeVisibilityScope;

class TrainingAccess
{
    public static function assertEmployeeInCompany(
        Employee $employee,
        int $companyId,
        int $status = 403,
        ?User $user = null,
    ): void {
        abort_unless((int) $employee->company_id === $companyId, $status);

        $currentUser = $user ?? auth()->user();
        if ($currentUser instanceof User) {
            abort_unless(EmployeeVisibilityScope::canAccess($currentUser, $employee, $companyId, allowSelf: true), 404);
        }
    }

    public static function assertTrainingBelongsToEmployee(
        Employee $employee,
        EmployeeTraining $training,
        int $companyId,
        int $status = 403,
        ?User $user = null,
    ): void {
        abort_unless(
            (int) $training->employee_id === (int) $employee->id
            && (int) $training->company_id === $companyId,
            $status,
        );

        $currentUser = $user ?? auth()->user();
        if ($currentUser instanceof User) {
            abort_unless(EmployeeVisibilityScope::canAccess($currentUser, $employee, $companyId, allowSelf: true), 404);
        }
    }

    public static function assertTrainingInCompany(
        EmployeeTraining $training,
        int $companyId,
        int $status = 403,
        ?User $user = null,
    ): void {
        abort_unless((int) $training->company_id === $companyId, $status);

        $currentUser = $user ?? auth()->user();
        if ($currentUser instanceof User) {
            $employee = $training->relationLoaded('employee')
                ? $training->employee
                : Employee::query()->where('company_id', $companyId)->find($training->employee_id);

            if ($employee !== null) {
                abort_unless(EmployeeVisibilityScope::canAccess($currentUser, $employee, $companyId, allowSelf: true), 404);
            }
        }
    }

    public static function assertCanAccessCertificate(?User $user): void
    {
        abort_unless(
            $user !== null && ($user->can('training.view') || $user->can('employees.view')),
            403,
        );
    }

    public static function assertVersionBelongsToTraining(
        EmployeeTraining $training,
        EmployeeTrainingVersion $version,
        int $companyId,
        int $status = 404,
    ): void {
        abort_unless(
            (int) $training->company_id === $companyId
            && (int) $version->company_id === $companyId
            && (int) $version->employee_training_id === $training->id,
            $status,
        );
    }
}
