<?php

namespace App\Http\Requests\Organization\DocumentGenerationTemplate;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewDocumentGenerationTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return ($user?->can('documents.templates.view') ?? false)
            || ($user?->can('documents.templates.create') ?? false)
            || ($user?->can('documents.templates.update') ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'name' => ['nullable', 'string', 'max:200'],
            'content' => ['required', 'string'],
            'employee_id' => [
                'nullable',
                'integer',
                Rule::exists('employees', 'id')
                    ->where('company_id', $companyId),
            ],
            'company_id' => ['prohibited'],
        ];
    }
}
