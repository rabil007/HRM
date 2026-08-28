<?php

namespace App\Http\Requests\Organization\Documents;

use Illuminate\Foundation\Http\FormRequest;

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
            'stages' => ['required', 'array', 'min:1'],
            'stages.*.action' => ['required', 'in:review,approve'],
            'stages.*.completion_rule' => ['required', 'in:all,any'],
            'stages.*.assignee_user_ids' => ['required', 'array', 'min:1'],
            'stages.*.assignee_user_ids.*' => ['integer', 'distinct'],
        ];
    }
}
