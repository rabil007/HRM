<?php

namespace App\Support\Payroll\Actions;

use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\SalaryInput;
use Illuminate\Validation\ValidationException;

final class StoreSalaryInput
{
    /**
     * @param  array{salary_input_type_id: int, amount: float|string, notes?: string|null}  $data
     */
    public function handle(PayrollPeriod $period, Employee $employee, array $data): SalaryInput
    {
        $this->assertPeriodAllowsSalaryInputs($period);

        return SalaryInput::query()->create([
            'company_id' => $period->company_id,
            'employee_id' => $employee->id,
            'period_id' => $period->id,
            'salary_input_type_id' => $data['salary_input_type_id'],
            'amount' => $data['amount'],
            'notes' => $data['notes'] ?? null,
        ]);
    }

    private function assertPeriodAllowsSalaryInputs(PayrollPeriod $period): void
    {
        if (! $period->canGeneratePayroll()) {
            throw ValidationException::withMessages([
                'period_id' => 'Salary inputs can only be managed for draft or processing periods.',
            ]);
        }
    }
}
