<?php

namespace App\Support\Contracts\Actions;

use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Support\Payroll\Actions\SyncContractSalaryComponentsFromContract;

final class UpsertEmployeeContract
{
    public function __construct(
        private readonly SyncContractSalaryComponentsFromContract $syncSalaryComponents,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(
        int $companyId,
        Employee $employee,
        array $attributes,
        ?EmployeeContract $existing = null,
    ): EmployeeContract {
        if (($attributes['status'] ?? $existing?->status ?? 'active') === 'active') {
            $this->deactivateOtherContracts($companyId, $employee->id, $existing?->id);
        }

        if ($existing === null) {
            $contract = EmployeeContract::query()->create([
                'company_id' => $companyId,
                'employee_id' => $employee->id,
                ...$attributes,
            ]);
        } else {
            $existing->update($attributes);
            $contract = $existing->fresh();
        }

        $this->syncSalaryComponents->handle($contract);

        return $contract;
    }

    private function deactivateOtherContracts(int $companyId, int $employeeId, ?int $exceptId = null): void
    {
        EmployeeContract::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('status', 'active')
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->update(['status' => 'ended']);
    }
}
