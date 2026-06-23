<?php

namespace App\Support\Payroll;

use App\Models\User;

final class CrewPayrollPagePermissions
{
    /**
     * @return array{create: bool, update: bool, delete: bool, generate_payroll: bool, revert_to_draft: bool}
     */
    public static function for(?User $user): array
    {
        return [
            'create' => $user?->can('payroll.crew_timesheets.create') ?? false,
            'update' => $user?->can('payroll.crew_timesheets.update') ?? false,
            'delete' => $user?->can('payroll.crew_timesheets.delete') ?? false,
            'generate_payroll' => $user?->can('payroll.periods.update') ?? false,
            'revert_to_draft' => $user?->can('payroll.periods.revert_to_draft') ?? false,
        ];
    }
}
