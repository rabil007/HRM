<?php

namespace App\Support\Payroll;

use App\Models\PayrollRecord;
use App\Models\User;
use App\Support\Employees\EmployeeVisibilityScope;
use Illuminate\Database\Eloquent\Builder;

final class PayrollRecordAccess
{
    /**
     * @param  Builder<PayrollRecord>  $query
     * @return Builder<PayrollRecord>
     */
    public static function apply(Builder $query, ?User $user, int $companyId): Builder
    {
        return EmployeeVisibilityScope::whereHas($query, $user, $companyId, 'employee');
    }

    public static function assertRecord(
        ?User $user,
        PayrollRecord $record,
        int $companyId,
        bool $allowSelf = true,
    ): void {
        abort_unless((int) $record->company_id === $companyId, 404);

        $record->loadMissing('employee');

        abort_unless(
            $record->employee !== null
            && EmployeeVisibilityScope::canAccess($user, $record->employee, $companyId, $allowSelf),
            404,
        );
    }
}
