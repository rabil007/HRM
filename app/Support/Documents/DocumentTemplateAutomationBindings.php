<?php

namespace App\Support\Documents;

use App\Enums\DocumentGenerationTemplateFormat;
use App\Enums\DocumentTemplateAutomationMode;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Support\Documents\Lifecycle\DocumentLifecycleAutomationPolicy;
use Illuminate\Validation\ValidationException;

final class DocumentTemplateAutomationBindings
{
    /**
     * @return DocumentTemplateAutomationMode|null Null means not explicitly configured.
     */
    public static function parseStoredMode(mixed $value): ?DocumentTemplateAutomationMode
    {
        if ($value instanceof DocumentTemplateAutomationMode) {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            return null;
        }

        return DocumentTemplateAutomationMode::tryFrom($value);
    }

    /**
     * Legacy: mode null + preset id set reads as preset. Null + null stays unconfigured.
     */
    public static function effectiveMode(?DocumentTemplateAutomationMode $storedMode, ?int $presetId): ?DocumentTemplateAutomationMode
    {
        if ($storedMode !== null) {
            return $storedMode;
        }

        if ($presetId !== null) {
            return DocumentTemplateAutomationMode::Preset;
        }

        return null;
    }

    /**
     * Copy bindings onto a new draft without rewriting the source version.
     *
     * @return array{mode: DocumentTemplateAutomationMode|null, preset_id: int|null}
     */
    public static function forBranchedDraft(?DocumentTemplateAutomationMode $sourceMode, ?int $sourcePresetId): array
    {
        if ($sourceMode === null && $sourcePresetId !== null) {
            return [
                'mode' => DocumentTemplateAutomationMode::Preset,
                'preset_id' => $sourcePresetId,
            ];
        }

        return [
            'mode' => $sourceMode,
            'preset_id' => $sourcePresetId,
        ];
    }

    public static function fromVersionWorkflow(DocumentGenerationTemplateVersion $version): array
    {
        $presetId = $version->document_workflow_preset_id !== null
            ? (int) $version->document_workflow_preset_id
            : null;

        return self::forBranchedDraft(
            self::parseStoredMode($version->document_workflow_mode),
            $presetId,
        );
    }

    public static function fromVersionSigning(DocumentGenerationTemplateVersion $version): array
    {
        $presetId = $version->document_signing_preset_id !== null
            ? (int) $version->document_signing_preset_id
            : null;

        return self::forBranchedDraft(
            self::parseStoredMode($version->document_signing_mode),
            $presetId,
        );
    }

    public static function hasSignaturePlacements(?array $config): bool
    {
        if (! is_array($config)) {
            return false;
        }

        $placements = $config['placements'] ?? null;

        return is_array($placements) && $placements !== [];
    }

    /**
     * Soft validation for draft Save Draft — inactive same-company presets are allowed.
     *
     * @param  array{
     *     document_workflow_mode?: mixed,
     *     document_workflow_preset_id?: mixed,
     *     document_signing_mode?: mixed,
     *     document_signing_preset_id?: mixed
     * }  $bindings
     * @return array{
     *     document_workflow_mode: DocumentTemplateAutomationMode|null,
     *     document_workflow_preset_id: int|null,
     *     document_signing_mode: DocumentTemplateAutomationMode|null,
     *     document_signing_preset_id: int|null
     * }
     */
    public static function validateForDraftSave(
        DocumentGenerationTemplate $template,
        array $bindings,
        int $companyId,
        DocumentLifecycleAutomationPolicy $policy = new DocumentLifecycleAutomationPolicy,
    ): array {
        return self::validateBindings($template, $bindings, $companyId, $policy, allowInactive: true);
    }

    /**
     * Legacy After generation endpoint: a null preset id is an explicit "none" decision.
     *
     * @param  array{document_workflow_preset_id?: mixed, document_signing_preset_id?: mixed}  $bindings
     * @return array{
     *     document_workflow_mode: DocumentTemplateAutomationMode,
     *     document_workflow_preset_id: int|null,
     *     document_signing_mode: DocumentTemplateAutomationMode,
     *     document_signing_preset_id: int|null
     * }
     */
    public static function validateForLegacyAutomationUpdate(
        DocumentGenerationTemplate $template,
        array $bindings,
        int $companyId,
        DocumentLifecycleAutomationPolicy $policy = new DocumentLifecycleAutomationPolicy,
    ): array {
        $workflowPresetId = self::normalizeNullableId($bindings['document_workflow_preset_id'] ?? null, 'document_workflow_preset_id');
        $signingPresetId = self::normalizeNullableId($bindings['document_signing_preset_id'] ?? null, 'document_signing_preset_id');

        $normalized = self::validateBindings($template, [
            'document_workflow_mode' => $workflowPresetId !== null
                ? DocumentTemplateAutomationMode::Preset->value
                : DocumentTemplateAutomationMode::None->value,
            'document_workflow_preset_id' => $workflowPresetId,
            'document_signing_mode' => $signingPresetId !== null
                ? DocumentTemplateAutomationMode::Preset->value
                : DocumentTemplateAutomationMode::None->value,
            'document_signing_preset_id' => $signingPresetId,
        ], $companyId, $policy, allowInactive: true);

        return [
            'document_workflow_mode' => $normalized['document_workflow_mode'] ?? DocumentTemplateAutomationMode::None,
            'document_workflow_preset_id' => $normalized['document_workflow_preset_id'],
            'document_signing_mode' => $normalized['document_signing_mode'] ?? DocumentTemplateAutomationMode::None,
            'document_signing_preset_id' => $normalized['document_signing_preset_id'],
        ];
    }

    /**
     * @param  array{
     *     document_workflow_mode?: mixed,
     *     document_workflow_preset_id?: mixed,
     *     document_signing_mode?: mixed,
     *     document_signing_preset_id?: mixed
     * }  $bindings
     * @return array{
     *     document_workflow_mode: DocumentTemplateAutomationMode|null,
     *     document_workflow_preset_id: int|null,
     *     document_signing_mode: DocumentTemplateAutomationMode|null,
     *     document_signing_preset_id: int|null
     * }
     */
    private static function validateBindings(
        DocumentGenerationTemplate $template,
        array $bindings,
        int $companyId,
        DocumentLifecycleAutomationPolicy $policy,
        bool $allowInactive,
    ): array {
        $workflowMode = self::parseIncomingMode($bindings['document_workflow_mode'] ?? null, 'document_workflow_mode');
        $signingMode = self::parseIncomingMode($bindings['document_signing_mode'] ?? null, 'document_signing_mode');
        $workflowPresetId = self::normalizeNullableId($bindings['document_workflow_preset_id'] ?? null, 'document_workflow_preset_id');
        $signingPresetId = self::normalizeNullableId($bindings['document_signing_preset_id'] ?? null, 'document_signing_preset_id');

        if ($signingMode === DocumentTemplateAutomationMode::Preset
            && $template->template_format !== DocumentGenerationTemplateFormat::PdfOverlay
        ) {
            throw ValidationException::withMessages([
                'document_signing_preset_id' => 'Automatic signing is only available for PDF Overlay templates.',
            ]);
        }

        if ($signingPresetId !== null && $template->template_format !== DocumentGenerationTemplateFormat::PdfOverlay) {
            throw ValidationException::withMessages([
                'document_signing_preset_id' => 'Automatic signing is only available for PDF Overlay templates.',
            ]);
        }

        self::assertModePresetPair(
            $workflowMode,
            $workflowPresetId,
            'document_workflow_preset_id',
            'A review preset is required when using an approval flow.',
            'A review preset cannot be set unless review uses a preset.',
        );

        self::assertModePresetPair(
            $signingMode,
            $signingPresetId,
            'document_signing_preset_id',
            'A signing preset is required when using a signing flow.',
            'A signing preset cannot be set unless signing uses a preset.',
        );

        if ($workflowMode === DocumentTemplateAutomationMode::Preset && $workflowPresetId !== null) {
            $policy->assertActiveWorkflowPreset($workflowPresetId, $companyId, allowInactive: $allowInactive);
        }

        if ($signingMode === DocumentTemplateAutomationMode::Preset && $signingPresetId !== null) {
            $policy->assertActiveSigningPreset($signingPresetId, $companyId, allowInactive: $allowInactive);
        }

        return [
            'document_workflow_mode' => $workflowMode,
            'document_workflow_preset_id' => $workflowPresetId,
            'document_signing_mode' => $signingMode,
            'document_signing_preset_id' => $signingPresetId,
        ];
    }

    private static function parseIncomingMode(mixed $value, string $field): ?DocumentTemplateAutomationMode
    {
        if ($value instanceof DocumentTemplateAutomationMode) {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw ValidationException::withMessages([
                $field => 'The selected automation mode is invalid.',
            ]);
        }

        $mode = DocumentTemplateAutomationMode::tryFrom($value);

        if ($mode === null) {
            throw ValidationException::withMessages([
                $field => 'The selected automation mode is invalid.',
            ]);
        }

        return $mode;
    }

    private static function assertModePresetPair(
        ?DocumentTemplateAutomationMode $mode,
        ?int $presetId,
        string $presetField,
        string $missingPresetMessage,
        string $unexpectedPresetMessage,
    ): void {
        if ($mode === DocumentTemplateAutomationMode::Preset && $presetId === null) {
            throw ValidationException::withMessages([
                $presetField => $missingPresetMessage,
            ]);
        }

        if (($mode === null || $mode === DocumentTemplateAutomationMode::None) && $presetId !== null) {
            throw ValidationException::withMessages([
                $presetField => $unexpectedPresetMessage,
            ]);
        }
    }

    private static function normalizeNullableId(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        if (! is_numeric($value)) {
            throw ValidationException::withMessages([
                $field => 'Preset id must be an integer or null.',
            ]);
        }

        return (int) $value;
    }
}
