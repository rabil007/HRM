<?php

namespace App\Http\Requests\Organization\Documents;

use Illuminate\Foundation\Http\FormRequest;

class CancelDocumentWorkflowRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('documents.requests.cancel') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
