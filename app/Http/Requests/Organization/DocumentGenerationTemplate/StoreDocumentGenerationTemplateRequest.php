<?php

namespace App\Http\Requests\Organization\DocumentGenerationTemplate;

use App\Enums\DocumentGenerationTemplateFormat;
use App\Enums\DocumentGenerationTemplateStatus;
use App\Support\Documents\DocumentTemplateMergeFields;
use Closure;
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
        $format = $this->input('template_format', DocumentGenerationTemplateFormat::Content->value);
        $isPdf = $format === DocumentGenerationTemplateFormat::PdfOverlay->value;

        return [
            'template_format' => ['nullable', Rule::enum(DocumentGenerationTemplateFormat::class)],
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
            'content' => [
                $isPdf ? 'nullable' : 'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail) use ($isPdf): void {
                    if ($isPdf || ! is_string($value)) {
                        return;
                    }

                    $unsupported = DocumentTemplateMergeFields::findUnsupported($value);
                    if ($unsupported !== []) {
                        $fail('The content contains unsupported merge fields: '.implode(', ', $unsupported).'.');
                    }
                },
            ],
            'file' => [
                $isPdf ? 'required' : 'prohibited',
                'file',
            ],
            'status' => ['nullable', Rule::enum(DocumentGenerationTemplateStatus::class)],
            'company_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
        ];
    }
}
