<?php

namespace App\Http\Requests\Organization\Documents;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDocumentGenerationTemplateAutomationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('documents.templates.update') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'document_workflow_preset_id' => [
                'nullable',
                'integer',
                Rule::exists('document_workflow_presets', 'id')
                    ->where('company_id', $companyId),
            ],
            'document_signing_preset_id' => [
                'nullable',
                'integer',
                Rule::exists('document_signing_presets', 'id')
                    ->where('company_id', $companyId),
            ],
        ];
    }
}
