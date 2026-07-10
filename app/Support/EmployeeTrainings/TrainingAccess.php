<?php

namespace App\Support\EmployeeTrainings;

use App\Models\Employee;
use App\Models\EmployeeTraining;

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
}
