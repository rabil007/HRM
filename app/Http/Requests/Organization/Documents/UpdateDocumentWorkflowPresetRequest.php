<?php

namespace App\Http\Requests\Organization\Documents;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDocumentWorkflowPresetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('documents.workflow-presets.update') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('document_workflow_presets', 'name')
                    ->where('company_id', $companyId)
                    ->ignore($this->route('workflowPreset')),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'stages' => ['required', 'array', 'min:1'],
            'stages.*.action' => ['required', 'in:review,approve'],
            'stages.*.completion_rule' => ['required', 'in:all,any'],
            'stages.*.targets' => ['required', 'array', 'min:1'],
            'stages.*.targets.*.target_type' => ['required', 'in:specific_user,department_manager,parent_manager,company_role'],
            'stages.*.targets.*.target_user_id' => ['nullable', 'integer'],
            'stages.*.targets.*.target_role_id' => ['nullable', 'integer'],
        ];
    }
}
