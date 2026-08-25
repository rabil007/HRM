<?php

namespace App\Support\EmployeeTrainings;

use App\Models\Employee;
use App\Models\EmployeeTraining;
use App\Models\EmployeeTrainingVersion;
use App\Models\User;

class TrainingAccess
{
    public static function assertEmployeeInCompany(Employee $employee, int $companyId, int $status = 403): void
    {
        abort_unless($employee->company_id === $companyId, $status);
    }

    public static function assertTrainingBelongsToEmployee(
        Employee $employee,
        EmployeeTraining $training,
        int $companyId,
        int $status = 403,
    ): void {
        abort_unless(
            $training->employee_id === $employee->id
            && $training->company_id === $companyId,
            $status,
        );
    }

    public static function assertTrainingInCompany(EmployeeTraining $training, int $companyId, int $status = 403): void
    {
        abort_unless($training->company_id === $companyId, $status);
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
