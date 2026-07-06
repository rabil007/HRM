<?php

namespace App\Support\Payroll\Wps;

use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\PayrollRecord;

final class WpsLaborIdentifier
{
    /**
     * UAE WPS EDR lines require the employee labour identifier from the payroll contract.
     */
    public static function forPayrollRecord(PayrollRecord $record): ?string
    {
        $record->loadMissing([
            'contract' => fn ($query) => $query->withTrashed(),
            'employee.currentContract',
            'employee.contracts',
        ]);

        $contract = $record->contract ?? ($record->employee !== null
            ? self::resolveContract($record->employee, $record)
            : null);

        if (filled($contract?->labor_contract_id)) {
            return (string) $contract->labor_contract_id;
        }

        return null;
    }

    private static function resolveContract(Employee $employee, PayrollRecord $record): ?EmployeeContract
    {
        $category = $record->payroll_category;

        if ($category !== null) {
            $matched = $employee->contracts
                ->where('status', 'active')
                ->where('payroll_category', $category)
                ->sortByDesc('id')
                ->first();

            if ($matched instanceof EmployeeContract) {
                return $matched;
            }
        }

        return $employee->currentContract;
    }
}
