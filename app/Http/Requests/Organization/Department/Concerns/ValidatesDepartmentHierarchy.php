<?php

namespace App\Http\Requests\Organization\Department\Concerns;

use App\Models\Department;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait ValidatesDepartmentHierarchy
{
    /**
     * @return array<int, mixed>
     */
    protected function parentIdRules(int $companyId): array
    {
        return [
            'nullable',
            'integer',
            Rule::exists('departments', 'id')->where(fn ($q) => $q
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')),
        ];
    }

    protected function withHierarchyValidator(Validator $validator, int $companyId, ?int $departmentId = null): void
    {
        $validator->after(function (Validator $validator) use ($companyId, $departmentId): void {
            $parentId = $this->input('parent_id');

            if ($parentId === null || $parentId === '') {
                return;
            }

            $parentId = (int) $parentId;

            if ($departmentId !== null && $parentId === $departmentId) {
                $validator->errors()->add('parent_id', 'A department cannot be its own parent.');

                return;
            }

            if ($departmentId === null) {
                return;
            }

            if ($this->wouldCreateCycle($companyId, $departmentId, $parentId)) {
                $validator->errors()->add('parent_id', 'Selecting this parent would create a circular department hierarchy.');
            }
        });
    }

    private function wouldCreateCycle(int $companyId, int $departmentId, int $parentId): bool
    {
        $departmentsById = Department::query()
            ->where('company_id', $companyId)
            ->get(['id', 'parent_id'])
            ->keyBy('id');

        $visited = [];
        $currentId = $parentId;

        while ($currentId !== null && ! isset($visited[$currentId])) {
            if ($currentId === $departmentId) {
                return true;
            }

            $visited[$currentId] = true;
            $current = $departmentsById->get($currentId);

            if ($current === null) {
                return false;
            }

            $currentId = $current->parent_id !== null ? (int) $current->parent_id : null;
        }

        return false;
    }
}
