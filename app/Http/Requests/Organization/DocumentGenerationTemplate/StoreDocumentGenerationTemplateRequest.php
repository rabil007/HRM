<?php

namespace App\Http\Requests\Organization\DocumentGenerationTemplate;

use App\Enums\DocumentGenerationTemplateFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentGenerationTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('documents.templates.create') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'template_format' => [
                'required',
                Rule::in([DocumentGenerationTemplateFormat::PdfOverlay->value]),
            ],
            'name' => [
                'required',
                'string',
                'max:200',
                Rule::unique('document_generation_templates', 'name')
                    ->where('company_id', $companyId),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'document_type_id' => [
                'nullable',
                'integer',
                Rule::exists('document_types', 'id')
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'file' => [
                'required',
                'file',
            ],
            'content' => ['prohibited'],
            'status' => ['prohibited'],
            'company_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
        ];
    }
}
