<?php

namespace App\Http\Requests\Organization\DocumentGenerationTemplate;

use Illuminate\Foundation\Http\FormRequest;

class SaveDocumentGenerationTemplateDesignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller handles authorization
    }

    public function rules(): array
    {
        return [
            'placement_config' => ['required', 'array'],
            'placement_config.schema_version' => ['required', 'integer', 'in:2'],
            'placement_config.placements' => ['required', 'array'],
            'placement_config.placements.*.type' => ['required', 'string', 'in:field,text'],
            'signature_placement_config' => ['required', 'array'],
            'signature_placement_config.schema_version' => ['required', 'integer'],
            'signature_placement_config.placements' => ['present', 'array'],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function placements(): array
    {
        return (array) ($this->input('placement_config.placements') ?? []);
    }

    /** @return array{schema_version: mixed, placements: mixed} */
    public function signaturePlacementConfig(): array
    {
        return (array) ($this->input('signature_placement_config') ?? []);
    }
}
