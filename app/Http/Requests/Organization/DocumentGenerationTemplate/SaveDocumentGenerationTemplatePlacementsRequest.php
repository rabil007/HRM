<?php

namespace App\Http\Requests\Organization\DocumentGenerationTemplate;

use Illuminate\Foundation\Http\FormRequest;

class SaveDocumentGenerationTemplatePlacementsRequest extends FormRequest
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
        return [
            'placements' => ['present', 'array'],
            'placements.*.id' => ['nullable', 'string'],
            'placements.*.field' => ['required', 'string'],
            'placements.*.page' => ['required', 'integer', 'min:1'],
            'placements.*.x' => ['required', 'numeric', 'min:0', 'max:1'],
            'placements.*.y' => ['required', 'numeric', 'min:0', 'max:1'],
            'placements.*.width' => ['required', 'numeric', 'min:0.0001', 'max:1'],
            'placements.*.height' => ['required', 'numeric', 'min:0.0001', 'max:1'],
            'placements.*.font_size' => ['nullable', 'integer', 'min:8', 'max:48'],
            'placements.*.font_weight' => ['nullable', 'string', 'in:normal,bold'],
            'placements.*.text_align' => ['nullable', 'string', 'in:left,center,right'],
        ];
    }
}
