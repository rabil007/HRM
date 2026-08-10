<?php

namespace App\Console\Commands\Contracts;

use App\Models\Employee;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Safe, idempotent backfill for the new `employee_contracts.company_visa_type_id`
 * column. For every employee with a current Company Visa Type, this copies
 * that value onto the employee's latest non-deleted active contract only
 * when the contract's own value is still null. Historical/ended contracts
 * and contracts that already carry a value are left untouched.
 */
#[Signature('contracts:backfill-company-visa-types')]
#[Description('Copy each employee current Company Visa Type onto their latest active contract when it is missing')]
class BackfillContractCompanyVisaTypesCommand extends Command
{
    public function handle(): int
    {
        $updated = 0;
        $skippedNoActiveContract = 0;
        $skippedAlreadySet = 0;

        Employee::query()
            ->whereNotNull('company_visa_type_id')
            ->with('currentContract')
            ->orderBy('id')
            ->chunkById(200, function ($employees) use (&$updated, &$skippedNoActiveContract, &$skippedAlreadySet): void {
                foreach ($employees as $employee) {
                    $contract = $employee->currentContract;

                    if ($contract === null) {
                        $skippedNoActiveContract++;

                        continue;
                    }

                    if ($contract->company_visa_type_id !== null) {
                        $skippedAlreadySet++;

                        continue;
                    }

                    $contract->update(['company_visa_type_id' => $employee->company_visa_type_id]);
                    $updated++;
                }
            });

        $employeesWithNoCurrentValue = Employee::query()->whereNull('company_visa_type_id')->count();

        $this->info(sprintf(
            'Backfilled %d contract(s). Skipped %d employee(s) without a current active contract, %d contract(s) that already had a value, and %d employee(s) with no current Company Visa Type (left null for manual review).',
            $updated,
            $skippedNoActiveContract,
            $skippedAlreadySet,
            $employeesWithNoCurrentValue,
        ));

        return self::SUCCESS;
    }
}
