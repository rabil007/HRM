<?php

namespace App\Http\Requests\Organization\DocumentGenerationTemplate;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ValidateDocumentGenerationTemplateDesignRequest extends FormRequest
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
            'mode' => ['required', 'string', Rule::in(['sample', 'employee'])],
            'employee_id' => ['nullable', 'integer', 'required_if:mode,employee'],
            'placement_config' => ['nullable', 'array'],
            'placement_config.schema_version' => ['required_with:placement_config', 'integer', 'in:2'],
            'placement_config.placements' => ['required_with:placement_config', 'array'],
            'placement_config.placements.*.type' => ['required_with:placement_config.placements', 'string', 'in:field,text'],
            'company_id' => ['prohibited'],
            'source_pdf_path' => ['prohibited'],
        ];
    }

    public function mode(): string
    {
        return (string) $this->validated('mode');
    }

    public function employeeId(): ?int
    {
        $id = $this->validated('employee_id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Return the submitted canvas config, not validated() nested data.
     *
     * Laravel's validated() subset would drop placement width/height because
     * those keys are normalized later, not listed as FormRequest rules.
     *
     * @return array<string, mixed>|null
     */
    public function placementConfig(): ?array
    {
        if (! $this->exists('placement_config') || $this->input('placement_config') === null) {
            return null;
        }

        $config = $this->input('placement_config');

        return is_array($config) ? $config : null;
    }
}
