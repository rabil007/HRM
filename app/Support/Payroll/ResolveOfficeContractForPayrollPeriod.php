<?php

namespace App\Support\Payroll;

use App\Enums\PayrollCategory;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use Illuminate\Support\Collection;

final class ResolveOfficeContractForPayrollPeriod
{
    /**
     * @param  list<int>  $employeeIds
     * @param  list<string>  $with
     * @return Collection<int, array{
     *     contract: EmployeeContract|null,
     *     issue: array{code: string, message: string}|null
     * }>
     */
    public function resolveMany(PayrollPeriod $period, array $employeeIds, array $with = []): Collection
    {
        $employeeIds = array_values(array_unique(array_map(intval(...), $employeeIds)));

        if ($employeeIds === []) {
            return collect();
        }

        $companyId = (int) $period->company_id;
        $asOfDate = $period->start_date->toDateString();

        $contracts = EmployeeContract::query()
            ->where('company_id', $companyId)
            ->whereIn('employee_id', $employeeIds)
            ->where('payroll_category', PayrollCategory::Office)
            ->whereDate('start_date', '<=', $asOfDate)
            ->where(function ($query) use ($asOfDate): void {
                $query->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $asOfDate);
            })
            ->when($with !== [], fn ($query) => $query->with($with))
            ->orderBy('start_date')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (EmployeeContract $contract): int => (int) $contract->employee_id);

        return collect($employeeIds)->mapWithKeys(function (int $employeeId) use ($contracts, $asOfDate): array {
            $candidates = $contracts->get($employeeId, collect())->values();

            if ($candidates->isEmpty()) {
                return [$employeeId => [
                    'contract' => null,
                    'issue' => [
                        'code' => 'missing_historical_contract',
                        'message' => "No Office contract covers payroll date {$asOfDate}.",
                    ],
                ]];
            }

            if ($candidates->count() > 1) {
                return [$employeeId => [
                    'contract' => null,
                    'issue' => [
                        'code' => 'overlapping_historical_contracts',
                        'message' => "Multiple Office contracts cover payroll date {$asOfDate}.",
                    ],
                ]];
            }

            return [$employeeId => [
                'contract' => $candidates->first(),
                'issue' => null,
            ]];
        });
    }

    /**
     * @return array{
     *     contract: EmployeeContract|null,
     *     issue: array{code: string, message: string}|null
     * }
     */
    public function resolve(Employee|int $employee, PayrollPeriod $period, array $with = []): array
    {
        $employeeId = $employee instanceof Employee ? (int) $employee->id : (int) $employee;

        return $this->resolveMany($period, [$employeeId], $with)->get($employeeId);
    }
}
