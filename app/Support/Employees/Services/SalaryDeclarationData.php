<?php

namespace App\Support\Employees\Services;

use App\Models\Company;
use App\Models\Employee;
use App\Services\Settings\SettingService;
use App\Support\Settings\SettingKey;

final class SalaryDeclarationData
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Employee $employee, int $companyId): array
    {
        $employee->load([
            'company:id,name',
            'position:id,title',
            'rank:id,name',
            'nationalityRef:id,name',
        ]);

        abort_unless((int) $employee->company_id === $companyId, 404);

        $settings = app(SettingService::class);
        $company = $employee->company ?? Company::query()->find($companyId);

        $companyName = (string) ($settings->get(SettingKey::CompanyName) ?: $company?->name ?: '');

        $identifier = filled($employee->emirates_id)
            ? (string) $employee->emirates_id
            : (string) ($employee->passport_number ?? '');

        return [
            'employee_name' => (string) ($employee->name ?? ''),
            'nationality' => (string) ($employee->nationalityRef?->name ?? ''),
            'eid_or_passport' => $identifier,
            'job_title' => (string) ($employee->position?->title ?? $employee->rank?->name ?? ''),
            'company_name' => $companyName,
        ];
    }
}
