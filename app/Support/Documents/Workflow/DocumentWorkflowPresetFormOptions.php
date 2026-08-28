<?php

namespace App\Support\Documents\Workflow;

use Spatie\Permission\Models\Role;

final class DocumentWorkflowPresetFormOptions
{
    /**
     * @return list<array{id: int, name: string, email: string|null}>
     */
    public function users(int $companyId): array
    {
        return (new DocumentWorkflowAssigneeOptionsQuery)->forCompany($companyId);
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function roles(int $companyId): array
    {
        return Role::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Role $role): array => [
                'id' => (int) $role->id,
                'name' => (string) $role->name,
            ])
            ->all();
    }

    /**
     * @return list<array{
     *     value: string,
     *     label: string,
     *     requires_user: bool,
     *     requires_role: bool
     * }>
     */
    public function targetTypes(): array
    {
        return [
            [
                'value' => 'specific_user',
                'label' => 'Specific user',
                'requires_user' => true,
                'requires_role' => false,
            ],
            [
                'value' => 'department_manager',
                'label' => 'Department manager',
                'requires_user' => false,
                'requires_role' => false,
            ],
            [
                'value' => 'parent_manager',
                'label' => 'Parent manager',
                'requires_user' => false,
                'requires_role' => false,
            ],
            [
                'value' => 'company_role',
                'label' => 'Company role',
                'requires_user' => false,
                'requires_role' => true,
            ],
        ];
    }
}
