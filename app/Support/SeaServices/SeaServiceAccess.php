<?php

namespace App\Support\SeaServices;

use App\Models\Employee;
use App\Models\EmployeeSeaService;

final class SeaServiceAccess
{
    public static function assertEmployeeInCompany(Employee $employee, int $companyId, int $status = 403): void
    {
        abort_unless($employee->company_id === $companyId, $status);
    }

    public static function assertSeaServiceBelongsToEmployee(
        Employee $employee,
        EmployeeSeaService $seaService,
        int $companyId,
        int $status = 403,
    ): void {
        abort_unless(
            $seaService->employee_id === $employee->id
            && $seaService->company_id === $companyId,
            $status,
        );
    }

    public static function assertSeaServiceInCompany(EmployeeSeaService $seaService, int $companyId, int $status = 403): void
    {
        abort_unless($seaService->company_id === $companyId, $status);
    }
}
