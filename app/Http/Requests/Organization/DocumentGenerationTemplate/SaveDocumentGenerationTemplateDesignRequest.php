<?php

namespace App\Http\Requests\Organization\DocumentGenerationTemplate;

use App\Enums\DocumentTemplateAutomationMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveDocumentGenerationTemplateDesignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller handles authorization
    }

    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');
        $rules = [
            'placement_config' => ['required', 'array'],
            'placement_config.schema_version' => ['required', 'integer', 'in:2'],
            'placement_config.placements' => ['present', 'array'],
            'placement_config.placements.*.type' => ['required', 'string', 'in:field,text'],
            'signature_placement_config' => ['required', 'array'],
            'signature_placement_config.schema_version' => ['required', 'integer', 'in:1,2,3'],
            'signature_placement_config.placements' => ['present', 'array'],
        ];

        if ($this->hasAutomationPayload()) {
            $rules['document_workflow_mode'] = ['nullable', Rule::enum(DocumentTemplateAutomationMode::class)];
            $rules['document_signing_mode'] = ['nullable', Rule::enum(DocumentTemplateAutomationMode::class)];
            $rules['document_workflow_preset_id'] = [
                'nullable',
                'integer',
                Rule::requiredIf($this->input('document_workflow_mode') === DocumentTemplateAutomationMode::Preset->value),
                Rule::exists('document_workflow_presets', 'id')->where('company_id', $companyId),
            ];
            $rules['document_signing_preset_id'] = [
                'nullable',
                'integer',
                Rule::requiredIf($this->input('document_signing_mode') === DocumentTemplateAutomationMode::Preset->value),
                Rule::exists('document_signing_presets', 'id')->where('company_id', $companyId),
            ];
        }

        return $rules;
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->hasAutomationPayload()) {
                    return;
                }

                $this->assertModePresetPair(
                    $validator,
                    $this->input('document_workflow_mode'),
                    $this->input('document_workflow_preset_id'),
                    'document_workflow_preset_id',
                    'A review preset cannot be set unless review uses a preset.',
                );
                $this->assertModePresetPair(
                    $validator,
                    $this->input('document_signing_mode'),
                    $this->input('document_signing_preset_id'),
                    'document_signing_preset_id',
                    'A signing preset cannot be set unless signing uses a preset.',
                );
            },
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

    public function hasAutomationPayload(): bool
    {
        return $this->exists('document_workflow_mode') || $this->exists('document_signing_mode');
    }

    /**
     * @return array{
     *     document_workflow_mode: mixed,
     *     document_workflow_preset_id: mixed,
     *     document_signing_mode: mixed,
     *     document_signing_preset_id: mixed
     * }|null
     */
    public function automationBindings(): ?array
    {
        if (! $this->hasAutomationPayload()) {
            return null;
        }

        return [
            'document_workflow_mode' => $this->input('document_workflow_mode'),
            'document_workflow_preset_id' => $this->input('document_workflow_preset_id'),
            'document_signing_mode' => $this->input('document_signing_mode'),
            'document_signing_preset_id' => $this->input('document_signing_preset_id'),
        ];
    }

    private function assertModePresetPair(
        Validator $validator,
        mixed $mode,
        mixed $presetId,
        string $presetField,
        string $message,
    ): void {
        $hasPreset = $presetId !== null && $presetId !== '';

        if (($mode === null || $mode === '' || $mode === DocumentTemplateAutomationMode::None->value) && $hasPreset) {
            $validator->errors()->add($presetField, $message);
        }
    }
}
