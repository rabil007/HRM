<?php

namespace App\Support\Payroll\Actions;

use App\Enums\PayrollCategory;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\SalaryInput;
use App\Support\Payroll\ApplyCrewSalaryInputs;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecalculateCrewPayroll
{
    public function __construct(
        private readonly ApplyCrewSalaryInputs $applySalaryInputs,
    ) {}

    public function handle(PayrollPeriod $period): int
    {
        abort_unless($period->isCrew(), 404);

        if (! $period->canGenerateCrewPayroll()) {
            throw ValidationException::withMessages([
                'period_id' => 'Crew payroll can only be recalculated for draft or processing periods.',
            ]);
        }

        $records = PayrollRecord::query()
            ->where('company_id', $period->company_id)
            ->where('period_id', $period->id)
            ->where('payroll_category', PayrollCategory::Crew)
            ->get();

        if ($records->isEmpty()) {
            throw ValidationException::withMessages([
                'period_id' => 'Generate payroll before recalculating salary inputs.',
            ]);
        }

        /** @var Collection<int, Collection<int, SalaryInput>> $inputsByEmployee */
        $inputsByEmployee = SalaryInput::query()
            ->where('company_id', $period->company_id)
            ->where('period_id', $period->id)
            ->with('salaryInputType')
            ->orderBy('id')
            ->get()
            ->groupBy('employee_id');

        $updatedCount = 0;

        DB::transaction(function () use ($records, $inputsByEmployee, &$updatedCount): void {
            foreach ($records as $record) {
                /** @var PayrollRecord $record */
                $inputs = $inputsByEmployee->get($record->employee_id, Collection::make());
                $adjusted = $this->applySalaryInputs->apply($record, $inputs);

                $record->update($adjusted);
                $updatedCount++;
            }
        });

        return $updatedCount;
    }
}
