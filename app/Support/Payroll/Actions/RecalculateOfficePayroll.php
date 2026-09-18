<?php

namespace App\Support\Payroll\Actions;

use App\Enums\PayrollCategory;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\SalaryInput;
use App\Models\User;
use App\Support\Employees\EmployeeVisibilityScope;
use App\Support\Payroll\ApplyOfficeSalaryInputs;
use App\Support\Payroll\PayrollRecordAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecalculateOfficePayroll
{
    public function __construct(
        private readonly ApplyOfficeSalaryInputs $applySalaryInputs,
    ) {}

    public function handle(PayrollPeriod $period, ?int $employeeId = null, ?User $user = null): int
    {
        abort_unless($period->isOffice(), 404);

        if (! $period->canGenerateOfficePayroll()) {
            throw ValidationException::withMessages([
                'period_id' => 'Office payroll can only be recalculated for draft or processing periods.',
            ]);
        }

        if ($employeeId === null && $user !== null && ! EmployeeVisibilityScope::hasUnrestrictedAccess($user, (int) $period->company_id)) {
            throw ValidationException::withMessages([
                'period_id' => 'Whole-period recalculation requires unrestricted employee access.',
            ]);
        }

        $recordsQuery = PayrollRecord::query()
            ->where('company_id', $period->company_id)
            ->where('period_id', $period->id)
            ->where('payroll_category', PayrollCategory::Office);

        if ($employeeId !== null) {
            $recordsQuery->where('employee_id', $employeeId);
        }

        $recordsQuery = PayrollRecordAccess::apply($recordsQuery, $user, (int) $period->company_id);

        $records = $recordsQuery->get();

        if ($records->isEmpty()) {
            if ($employeeId !== null) {
                return 0;
            }

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
