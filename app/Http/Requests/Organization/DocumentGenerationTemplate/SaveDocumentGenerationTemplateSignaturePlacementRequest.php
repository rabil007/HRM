<?php

namespace App\Http\Requests\Organization\DocumentGenerationTemplate;

use Illuminate\Foundation\Http\FormRequest;

class SaveDocumentGenerationTemplateSignaturePlacementRequest extends FormRequest
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
            'schema_version' => ['required', 'integer', 'in:1'],
            'placements' => ['required', 'array', 'min:1', 'max:1'],
            'placements.*.id' => ['required', 'string', 'max:100'],
            'placements.*.type' => ['required', 'string', 'in:signature'],
            'placements.*.role' => ['required', 'string', 'in:subject'],
            'placements.*.page' => ['required', 'integer', 'min:1'],
            'placements.*.x' => ['required', 'numeric', 'min:0', 'max:1'],
            'placements.*.y' => ['required', 'numeric', 'min:0', 'max:1'],
            'placements.*.width' => ['required', 'numeric', 'min:0.0001', 'max:1'],
            'placements.*.height' => ['required', 'numeric', 'min:0.0001', 'max:1'],
            'placements.*.required' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array{schema_version: int, placements: list<array<string, mixed>>}
     */
    public function signaturePlacementConfig(): array
    {
        /** @var array{schema_version: int, placements: list<array<string, mixed>>} $validated */
        $validated = $this->validated();

        return [
            'schema_version' => (int) $validated['schema_version'],
            'placements' => $validated['placements'],
        ];
    }
}
