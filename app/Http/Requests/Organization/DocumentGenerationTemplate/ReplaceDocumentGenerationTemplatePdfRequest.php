<?php

namespace App\Http\Requests\Organization\DocumentGenerationTemplate;

use Illuminate\Foundation\Http\FormRequest;

class ReplaceDocumentGenerationTemplatePdfRequest extends FormRequest
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
            'file' => ['required', 'file'],
        ];
    }
}
