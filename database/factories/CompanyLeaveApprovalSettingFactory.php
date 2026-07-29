<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyLeaveApprovalSetting;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyLeaveApprovalSetting>
 */
class CompanyLeaveApprovalSettingFactory extends Factory
{
    protected $model = CompanyLeaveApprovalSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => 0,
            'default_hr_approver_employee_id' => null,
            'fallback_approver_employee_id' => null,
            'updated_by' => null,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (): array => [
            'company_id' => $company->id,
        ]);
    }

    public function withHrApprover(Employee $employee): static
    {
        return $this->state(fn (): array => [
            'company_id' => $employee->company_id,
            'default_hr_approver_employee_id' => $employee->id,
        ]);
    }

    public function withFallbackApprover(Employee $employee): static
    {
        return $this->state(fn (): array => [
            'company_id' => $employee->company_id,
            'fallback_approver_employee_id' => $employee->id,
        ]);
    }
}
