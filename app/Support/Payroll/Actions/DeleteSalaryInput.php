<?php

namespace App\Support\Payroll\Actions;

use App\Models\PayrollPeriod;
use App\Models\SalaryInput;
use Illuminate\Validation\ValidationException;

final class DeleteSalaryInput
{
    public function handle(PayrollPeriod $period, SalaryInput $salaryInput): void
    {
        abort_unless(
            (int) $salaryInput->company_id === (int) $period->company_id
            && (int) $salaryInput->period_id === (int) $period->id,
            404,
        );

        abort_unless($period->isOffice(), 404);

        if (! $period->canGenerateOfficePayroll()) {
            throw ValidationException::withMessages([
                'period_id' => 'Salary inputs can only be managed for draft or processing office periods.',
            ]);
        }

        $salaryInput->delete();
    }
}
