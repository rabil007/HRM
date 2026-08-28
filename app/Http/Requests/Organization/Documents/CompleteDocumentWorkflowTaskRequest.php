<?php

namespace App\Http\Requests\Organization\Documents;

use Illuminate\Foundation\Http\FormRequest;

class CompleteDocumentWorkflowTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('documents.requests.review') ?? false)
            || ($this->user()?->can('documents.requests.approve') ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
