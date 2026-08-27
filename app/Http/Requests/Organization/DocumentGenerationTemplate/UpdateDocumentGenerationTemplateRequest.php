<?php

namespace App\Http\Requests\Organization\DocumentGenerationTemplate;

use App\Models\DocumentGenerationTemplate;
use App\Support\Documents\DocumentTemplateMergeFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDocumentGenerationTemplateRequest extends FormRequest
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
        $template = $this->route('template');
        $templateId = $template instanceof DocumentGenerationTemplate ? $template->id : (int) $template;

        return [
            'name' => [
                'required',
                'string',
                'max:200',
                Rule::unique('document_generation_templates', 'name')
                    ->where('company_id', $companyId)
                    ->ignore($templateId),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'document_type_id' => [
                'nullable',
                'integer',
                Rule::exists('document_types', 'id')
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'content' => [
                'required',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value)) {
                        return;
                    }

                    $unsupported = DocumentTemplateMergeFields::findUnsupported($value);
                    if ($unsupported !== []) {
                        $fail('The content contains unsupported merge fields: '.implode(', ', $unsupported).'.');
                    }
                },
            ],
            'status' => ['prohibited'],
            'company_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
        ];
    }
}
