<?php

namespace App\Support\Contracts\Actions;

use App\Models\CompanyVisaType;
use App\Models\Employee;
use App\Models\EmployeeContract;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ends an employee's current contract and opens a new one when the
 * employee changes visa company before the previous contract is
 * complete.
 *
 * The old contract is never soft-deleted: its end date is capped to one
 * day before the transfer date and its status becomes `ended`, so all of
 * its salary revisions and historical payroll references remain intact
 * and resolvable for their own effective dates. The new contract starts
 * on the transfer date, carries the new Company Visa Type, and receives
 * its own initial salary revision through the existing contract upsert
 * behavior.
 */
final class TransferEmployeeVisaCompanyContract
{
    public function __construct(
        private readonly UpsertEmployeeContract $upsertEmployeeContract,
    ) {}

    /**
     * @param  array<string, mixed>  $newContractAttributes
     */
    public function handle(
        int $companyId,
        Employee $employee,
        EmployeeContract $oldContract,
        int $newCompanyVisaTypeId,
        string $transferDate,
        array $newContractAttributes,
        ?int $actorId = null,
        ?string $reason = null,
    ): EmployeeContract {
        return DB::transaction(function () use (
            $companyId,
            $employee,
            $oldContract,
            $newCompanyVisaTypeId,
            $transferDate,
            $newContractAttributes,
            $actorId,
            $reason,
        ): EmployeeContract {
            $lockedEmployee = Employee::query()
                ->where('company_id', $companyId)
                ->whereKey($employee->id)
                ->lockForUpdate()
                ->first();

            if ($lockedEmployee === null) {
                throw ValidationException::withMessages([
                    'employee' => 'Employee not found in this company.',
                ]);
            }

            $lockedOldContract = EmployeeContract::query()
                ->where('company_id', $companyId)
                ->where('employee_id', $lockedEmployee->id)
                ->whereKey($oldContract->id)
                ->lockForUpdate()
                ->first();

            if ($lockedOldContract === null) {
                throw ValidationException::withMessages([
                    'employee_contract' => 'Contract not found for this employee and company.',
                ]);
            }

            if ($lockedOldContract->status !== 'active') {
                throw ValidationException::withMessages([
                    'employee_contract' => 'Only an active contract can be transferred to a new sponsor.',
                ]);
            }

            $companyVisaType = CompanyVisaType::query()
                ->whereKey($newCompanyVisaTypeId)
                ->where('is_active', true)
                ->first();

            if ($companyVisaType === null) {
                throw ValidationException::withMessages([
                    'company_visa_type_id' => 'The selected sponsor is invalid or inactive.',
                ]);
            }

            $transferAt = Carbon::parse($transferDate)->startOfDay();
            $oldStart = $lockedOldContract->start_date?->copy()->startOfDay();

            if ($oldStart !== null && $transferAt->lessThanOrEqualTo($oldStart)) {
                throw ValidationException::withMessages([
                    'transfer_date' => 'The transfer date must be after the current contract start date.',
                ]);
            }

            $previousCompanyVisaTypeId = $lockedOldContract->company_visa_type_id;
            $oldContractEndDate = $transferAt->copy()->subDay();

            $lockedOldContract->update([
                'end_date' => $oldContractEndDate->toDateString(),
                'status' => 'ended',
            ]);

            $attributes = [
                'start_date' => $transferAt->toDateString(),
                'status' => 'active',
                'company_visa_type_id' => $companyVisaType->id,
                'payroll_category' => $newContractAttributes['payroll_category']
                    ?? $lockedOldContract->payroll_category?->value,
                'salary_structure' => $newContractAttributes['salary_structure']
                    ?? $lockedOldContract->salary_structure?->value,
                'end_date' => $newContractAttributes['end_date'] ?? null,
                'labor_contract_id' => $newContractAttributes['labor_contract_id'] ?? null,
                'basic_salary' => $newContractAttributes['basic_salary'] ?? null,
                'housing_allowance' => $newContractAttributes['housing_allowance'] ?? null,
                'transport_allowance' => $newContractAttributes['transport_allowance'] ?? null,
                'other_allowances' => $newContractAttributes['other_allowances'] ?? null,
                'supplementary_allowance' => $newContractAttributes['supplementary_allowance'] ?? null,
                'site_allowance' => $newContractAttributes['site_allowance'] ?? null,
                'note' => $newContractAttributes['note'] ?? null,
            ];

            $newContract = $this->upsertEmployeeContract->handle(
                $companyId,
                $lockedEmployee,
                $attributes,
                existing: null,
                createdBy: $actorId,
                revisionReason: $reason !== null && $reason !== ''
                    ? "Visa company transfer: {$reason}"
                    : 'Visa company transfer',
            );

            $this->logTransfer(
                $lockedEmployee,
                $lockedOldContract->fresh(),
                $newContract,
                $previousCompanyVisaTypeId,
                $companyVisaType->id,
                $transferAt->toDateString(),
                $actorId,
                $reason,
            );

            return $newContract;
        });
    }

    private function logTransfer(
        Employee $employee,
        EmployeeContract $oldContract,
        EmployeeContract $newContract,
        ?int $previousCompanyVisaTypeId,
        int $newCompanyVisaTypeId,
        string $transferDate,
        ?int $actorId,
        ?string $reason,
    ): void {
        activity()
            ->performedOn($newContract)
            ->causedBy($actorId)
            ->withProperties([
                'event' => 'contract_visa_company_transferred',
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'old_contract_id' => $oldContract->id,
                'new_contract_id' => $newContract->id,
                'previous_company_visa_type_id' => $previousCompanyVisaTypeId,
                'new_company_visa_type_id' => $newCompanyVisaTypeId,
                'transfer_date' => $transferDate,
                'old_contract_end_date' => $oldContract->end_date?->toDateString(),
                'reason' => $reason,
            ])
            ->tap(function ($activity) use ($employee): void {
                $activity->company_id = $employee->company_id;
            })
            ->log('Employee visa company transferred');
    }
}
