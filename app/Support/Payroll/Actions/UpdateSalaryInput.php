<?php

namespace App\Support\Payroll\Actions;

use App\Models\PayrollPeriod;
use App\Models\SalaryInput;
use Illuminate\Validation\ValidationException;

final class UpdateSalaryInput
{
    /**
     * @param  array{salary_input_type_id?: int, amount?: float|string, notes?: string|null}  $data
     */
    public function handle(PayrollPeriod $period, SalaryInput $salaryInput, array $data): SalaryInput
    {
        $this->assertBelongsToPeriod($period, $salaryInput);
        $this->assertPeriodAllowsSalaryInputs($period);

        $salaryInput->update([
            'salary_input_type_id' => $data['salary_input_type_id'] ?? $salaryInput->salary_input_type_id,
            'amount' => $data['amount'] ?? $salaryInput->amount,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $salaryInput->notes,
        ]);

        return $salaryInput->refresh();
    }

    private function assertBelongsToPeriod(PayrollPeriod $period, SalaryInput $salaryInput): void
    {
        abort_unless(
            (int) $salaryInput->company_id === (int) $period->company_id
            && (int) $salaryInput->period_id === (int) $period->id,
            404,
        );
    }

    private function assertPeriodAllowsSalaryInputs(PayrollPeriod $period): void
    {
        abort_unless($period->isOffice(), 404);

        if (! $period->canGenerateOfficePayroll()) {
            throw ValidationException::withMessages([
                'period_id' => 'Salary inputs can only be managed for draft or processing office periods.',
            ]);
        }
    }
}
