<?php

namespace App\Http\Requests\Organization\Documents;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreDocumentWorkflowRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('documents.requests.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'workflow_preset_id' => ['nullable', 'integer', 'exists:document_workflow_presets,id'],
            'stages' => ['nullable', 'array', 'min:1'],
            'stages.*.action' => ['required_with:stages', 'in:review,approve'],
            'stages.*.completion_rule' => ['required_with:stages', 'in:all,any'],
            'stages.*.assignee_user_ids' => ['required_with:stages', 'array', 'min:1'],
            'stages.*.assignee_user_ids.*' => ['integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasPreset = $this->filled('workflow_preset_id');
            $hasStages = is_array($this->input('stages')) && $this->input('stages') !== [];

            if ($hasPreset && $hasStages) {
                $validator->errors()->add('workflow_preset_id', 'Choose either a workflow preset or manual stages, not both.');
            }

            if (! $hasPreset && ! $hasStages) {
                $validator->errors()->add('workflow_preset_id', 'Select a workflow preset or configure manual stages.');
            }
        });
    }
}
