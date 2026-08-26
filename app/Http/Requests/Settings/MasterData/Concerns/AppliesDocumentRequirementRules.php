<?php

namespace App\Http\Requests\Settings\MasterData\Concerns;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait AppliesDocumentRequirementRules
{
    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    protected function documentRequirementRules(array $rules): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            ...$rules,
            'is_required' => ['sometimes', 'boolean'],
            'required_for_all' => ['sometimes', 'boolean'],
            'department_ids' => ['sometimes', 'array'],
            'department_ids.*' => [
                'integer',
                Rule::exists('departments', 'id')->where(fn ($query) => $query
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')),
            ],
            'position_ids' => ['sometimes', 'array'],
            'position_ids.*' => [
                'integer',
                Rule::exists('positions', 'id')->where(fn ($query) => $query
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')),
            ],
            'rank_ids' => ['sometimes', 'array'],
            'rank_ids.*' => [
                'integer',
                Rule::exists('ranks', 'id')->whereNull('deleted_at'),
            ],
            'project_ids' => ['sometimes', 'array'],
            'project_ids.*' => [
                'integer',
                Rule::exists('projects', 'id')->whereNull('deleted_at'),
            ],
            'require_issue_date' => ['sometimes', 'boolean'],
            'require_expiry_date' => ['sometimes', 'boolean'],
            'require_document_number' => ['sometimes', 'boolean'],
        ];
    }

    protected function validateSelectedRequirementScopes(Validator $validator): void
    {
        if (! $this->exists('is_required') || ! $this->boolean('is_required') || $this->boolean('required_for_all')) {
            return;
        }

        $departmentIds = $this->input('department_ids', []);
        $positionIds = $this->input('position_ids', []);
        $rankIds = $this->input('rank_ids', []);
        $projectIds = $this->input('project_ids', []);

        if (! is_array($departmentIds) || ! is_array($positionIds) || ! is_array($rankIds) || ! is_array($projectIds)) {
            return;
        }

        if ($departmentIds === [] && $positionIds === [] && $rankIds === [] && $projectIds === []) {
            $validator->errors()->add(
                'required_for_all',
                'Select at least one department, position, rank, or project, or require the document for all employees.',
            );
        }
    }
}
