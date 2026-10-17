<?php

namespace App\Support\Contracts;

use App\Models\CompanyVisaType;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\User;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateResolver;
use App\Support\Employees\Resources\EmployeeContractResource;

final class ContractFormContext
{
    /**
     * @return array{
     *     mode: 'create'|'edit',
     *     employee: array{id: int, name: string, employee_no: string, company_visa_type_id: int|null},
     *     contract: array<string, mixed>|null,
     *     template_contract_fields: mixed,
     *     company_visa_types: list<array{id: int, name: string}>
     * }|null
     */
    public static function fromRequest(
        int $companyId,
        ?User $user,
        ?int $createForEmployeeId,
        ?int $editContractId,
    ): ?array {
        if ($editContractId !== null && $editContractId > 0) {
            return self::forEdit($companyId, $user, $editContractId);
        }

        if ($createForEmployeeId !== null && $createForEmployeeId > 0) {
            return self::forCreate($companyId, $user, $createForEmployeeId);
        }

        return null;
    }

    /**
     * @return array{
     *     mode: 'create',
     *     employee: array{id: int, name: string, employee_no: string, company_visa_type_id: int|null},
     *     contract: null,
     *     template_contract_fields: mixed,
     *     company_visa_types: list<array{id: int, name: string}>
     * }
     */
    private static function forCreate(int $companyId, ?User $user, int $employeeId): array
    {
        $employee = Employee::query()
            ->where('company_id', $companyId)
            ->whereKey($employeeId)
            ->firstOrFail();

        ContractAccess::assertEmployeeInCompany($employee, $companyId, 404, $user);

        return [
            'mode' => 'create',
            'employee' => self::employeeSummary($employee),
            'contract' => null,
            'template_contract_fields' => self::templateFields($employee),
            'company_visa_types' => self::companyVisaTypes(),
        ];
    }

    /**
     * @return array{
     *     mode: 'edit',
     *     employee: array{id: int, name: string, employee_no: string, company_visa_type_id: int|null},
     *     contract: array<string, mixed>,
     *     template_contract_fields: mixed,
     *     company_visa_types: list<array{id: int, name: string}>
     * }
     */
    private static function forEdit(int $companyId, ?User $user, int $contractId): array
    {
        $contract = EmployeeContract::query()
            ->where('company_id', $companyId)
            ->whereKey($contractId)
            ->with([
                'companyVisaType:id,name',
                'salaryRevisions' => fn ($query) => $query
                    ->with('lines')
                    ->orderByDesc('version'),
            ])
            ->firstOrFail();

        $employee = Employee::query()
            ->where('company_id', $companyId)
            ->whereKey($contract->employee_id)
            ->firstOrFail();

        ContractAccess::assertEmployeeInCompany($employee, $companyId, 404, $user);

        return [
            'mode' => 'edit',
            'employee' => self::employeeSummary($employee),
            'contract' => EmployeeContractResource::toArray($contract),
            'template_contract_fields' => self::templateFields($employee),
            'company_visa_types' => self::companyVisaTypes(),
        ];
    }

    /**
     * @return array{id: int, name: string, employee_no: string, company_visa_type_id: int|null}
     */
    private static function employeeSummary(Employee $employee): array
    {
        return [
            'id' => (int) $employee->id,
            'name' => (string) $employee->name,
            'employee_no' => (string) $employee->employee_no,
            'company_visa_type_id' => $employee->company_visa_type_id !== null
                ? (int) $employee->company_visa_type_id
                : null,
        ];
    }

    private static function templateFields(Employee $employee): mixed
    {
        $employee->loadMissing('employeeProfileTemplate:id,name,configuration_json');
        $resolved = EmployeeProfileTemplateResolver::resolve($employee->employeeProfileTemplate);

        return $resolved['fields']['employee_contracts'] ?? null;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private static function companyVisaTypes(): array
    {
        return CompanyVisaType::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (CompanyVisaType $type): array => [
                'id' => (int) $type->id,
                'name' => (string) $type->name,
            ])
            ->values()
            ->all();
    }
}
