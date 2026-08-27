<?php

namespace App\Http\Requests\Organization\DocumentGenerationTemplate;

use Illuminate\Foundation\Http\FormRequest;

class PreviewDocumentGenerationTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return ($user?->can('documents.templates.create') ?? false)
            || ($user?->can('documents.templates.update') ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:200'],
            'content' => ['required', 'string'],
            'company_id' => ['prohibited'],
        ];
    }
}
