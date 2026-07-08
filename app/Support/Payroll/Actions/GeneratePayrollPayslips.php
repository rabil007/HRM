<?php

namespace App\Support\Payroll\Actions;

use App\Enums\PayrollCategory;
use App\Jobs\GeneratePayrollPayslipsJob;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\SalaryInput;
use App\Support\Media\CompanyLogoDataUri;
use App\Support\Payroll\SalaryInputResource;
use Illuminate\Support\Collection;

final class GeneratePayrollPayslips
{
    public const RECORDS_PER_JOB = 10;

    public function dispatchForPeriod(PayrollPeriod $period): void
    {
        $recordIds = $this->pendingRecordIds(
            (int) $period->company_id,
            (int) $period->id,
        );

        if ($recordIds === []) {
            return;
        }

        foreach (array_chunk($recordIds, self::RECORDS_PER_JOB) as $chunk) {
            GeneratePayrollPayslipsJob::dispatch(
                (int) $period->company_id,
                (int) $period->id,
                $chunk,
            );
        }
    }

    /**
     * @param  list<int>  $recordIds
     */
    public function handle(int $companyId, int $periodId, array $recordIds, GeneratePayslip $generatePayslip): void
    {
        if ($recordIds === []) {
            return;
        }

        $records = PayrollRecord::query()
            ->where('company_id', $companyId)
            ->where('period_id', $periodId)
            ->whereIn('id', $recordIds)
            ->where(function ($query): void {
                $query
                    ->whereNull('payslip_path')
                    ->orWhere('payslip_path', '');
            })
            ->with([
                'employee.position:id,title',
                'period',
                'company.currency:id,code,symbol',
            ])
            ->orderBy('id')
            ->get();

        if ($records->isEmpty()) {
            return;
        }

        CompanyLogoDataUri::resolve($records->first()->company);

        $salaryInputsByEmployee = $this->salaryInputsByEmployee($companyId, $periodId, $records);

        foreach ($records as $record) {
            $generatePayslip->handle(
                $record,
                $salaryInputsByEmployee[$record->employee_id] ?? null,
            );
        }
    }

    /**
     * @return list<int>
     */
    private function pendingRecordIds(int $companyId, int $periodId): array
    {
        return PayrollRecord::query()
            ->where('company_id', $companyId)
            ->where('period_id', $periodId)
            ->where(function ($query): void {
                $query
                    ->whereNull('payslip_path')
                    ->orWhere('payslip_path', '');
            })
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, PayrollRecord>  $records
     * @return array<int, list<array<string, mixed>>>
     */
    private function salaryInputsByEmployee(int $companyId, int $periodId, Collection $records): array
    {
        $employeeIds = $records
            ->filter(fn (PayrollRecord $record): bool => $this->recordNeedsSalaryInputQuery($record))
            ->pluck('employee_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($employeeIds === []) {
            return [];
        }

        return SalaryInput::query()
            ->where('company_id', $companyId)
            ->where('period_id', $periodId)
            ->whereIn('employee_id', $employeeIds)
            ->with('salaryInputType:id,name,code,is_addition')
            ->orderBy('id')
            ->get()
            ->groupBy('employee_id')
            ->map(fn (Collection $inputs) => $inputs
                ->map(fn (SalaryInput $input) => SalaryInputResource::toArray($input))
                ->values()
                ->all())
            ->all();
    }

    private function recordNeedsSalaryInputQuery(PayrollRecord $record): bool
    {
        $breakdown = is_array($record->calculation_breakdown) ? $record->calculation_breakdown : [];
        $stored = is_array($breakdown['salary_inputs'] ?? null) ? $breakdown['salary_inputs'] : [];

        if ($stored !== []) {
            return false;
        }

        $category = $record->payroll_category ?? PayrollCategory::Office;

        if ($category === PayrollCategory::Office) {
            return true;
        }

        return ($breakdown['salary_structure'] ?? 'daily') === 'monthly';
    }
}
