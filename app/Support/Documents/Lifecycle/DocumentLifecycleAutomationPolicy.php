<?php

namespace App\Support\Documents\Lifecycle;

use App\Enums\DocumentGenerationTemplateFormat;
use App\Enums\DocumentRecipientRole;
use App\Enums\DocumentSigningPresetStatus;
use App\Enums\DocumentWorkflowPresetStatus;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\DocumentSigningPreset;
use App\Models\DocumentWorkflowPreset;
use App\Support\Documents\RecipientRequests\DocumentSignaturePlacementValidator;
use App\Support\Documents\Signing\DocumentSignatureSlot;
use DomainException;
use Illuminate\Validation\ValidationException;

final class DocumentLifecycleAutomationPolicy
{
    public const SCHEMA_VERSION = 1;

    public const BLOCK_INACTIVE_WORKFLOW_PRESET = 'inactive_workflow_preset';

    public const BLOCK_INACTIVE_SIGNING_PRESET = 'inactive_signing_preset';

    public const BLOCK_MISSING_INITIATOR = 'missing_initiator';

    public const BLOCK_WORKFLOW_START_FAILED = 'workflow_start_failed';

    public const BLOCK_SIGNING_START_FAILED = 'signing_start_failed';

    public const BLOCK_SOURCE_VERSION_CHANGED = 'lifecycle_source_version_changed';

    public const BLOCK_ROUTING_FAILED = 'routing_failed';

    public const STOP_WORKFLOW_REJECTED = 'workflow_rejected';

    public const STOP_WORKFLOW_CANCELLED = 'workflow_cancelled';

    public const STOP_SIGNING_CANCELLED = 'signing_cancelled';

    /**
     * @return array{
     *     schema_version: int,
     *     workflow_preset_id: int|null,
     *     workflow_preset_name: string|null,
     *     signing_preset_id: int|null,
     *     signing_preset_name: string|null
     * }
     */
    public function snapshotFromTemplateVersion(DocumentGenerationTemplateVersion $version): array
    {
        $workflowPreset = $version->document_workflow_preset_id !== null
            ? DocumentWorkflowPreset::query()
                ->whereKey($version->document_workflow_preset_id)
                ->where('company_id', $version->company_id)
                ->first()
            : null;

        $signingPreset = $version->document_signing_preset_id !== null
            ? DocumentSigningPreset::query()
                ->whereKey($version->document_signing_preset_id)
                ->where('company_id', $version->company_id)
                ->first()
            : null;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'workflow_preset_id' => $workflowPreset?->id,
            'workflow_preset_name' => $workflowPreset?->name,
            'signing_preset_id' => $signingPreset?->id,
            'signing_preset_name' => $signingPreset?->name,
        ];
    }

    public function templateVersionRequiresAutomation(DocumentGenerationTemplateVersion $version): bool
    {
        return $version->document_workflow_preset_id !== null
            || $version->document_signing_preset_id !== null;
    }

    /**
     * Soft validation for draft saves — presets must be same-company when set.
     * Placement completeness is deferred to publish.
     *
     * @param  array{document_workflow_preset_id?: int|null, document_signing_preset_id?: int|null}  $bindings
     * @return array{document_workflow_preset_id: int|null, document_signing_preset_id: int|null}
     */
    public function validateDraftBindings(
        DocumentGenerationTemplate $template,
        array $bindings,
        int $companyId,
    ): array {
        $workflowPresetId = $this->normalizeNullableId($bindings['document_workflow_preset_id'] ?? null);
        $signingPresetId = $this->normalizeNullableId($bindings['document_signing_preset_id'] ?? null);

        if ($signingPresetId !== null && $template->template_format !== DocumentGenerationTemplateFormat::PdfOverlay) {
            throw ValidationException::withMessages([
                'document_signing_preset_id' => 'Automatic signing is only available for PDF Overlay templates.',
            ]);
        }

        if ($workflowPresetId !== null) {
            $this->assertActiveWorkflowPreset($workflowPresetId, $companyId, allowInactive: true);
        }

        if ($signingPresetId !== null) {
            $this->assertActiveSigningPreset($signingPresetId, $companyId, allowInactive: true);
        }

        return [
            'document_workflow_preset_id' => $workflowPresetId,
            'document_signing_preset_id' => $signingPresetId,
        ];
    }

    /**
     * Strict validation before publishing a draft version.
     */
    public function assertPublishable(DocumentGenerationTemplateVersion $version, DocumentGenerationTemplate $template): void
    {
        $companyId = (int) $version->company_id;

        if ($version->document_workflow_preset_id !== null) {
            $this->assertActiveWorkflowPreset((int) $version->document_workflow_preset_id, $companyId, allowInactive: false);
        }

        if ($version->document_signing_preset_id !== null) {
            if ($template->template_format !== DocumentGenerationTemplateFormat::PdfOverlay) {
                throw new DomainException('Automatic signing is only available for PDF Overlay templates.');
            }

            $preset = $this->assertActiveSigningPreset(
                (int) $version->document_signing_preset_id,
                $companyId,
                allowInactive: false,
            );

            $this->assertSigningPlacementCoversPreset($version, $preset);
        }
    }

    public function assertActiveWorkflowPreset(int $presetId, int $companyId, bool $allowInactive = false): DocumentWorkflowPreset
    {
        $preset = DocumentWorkflowPreset::query()
            ->forCompany($companyId)
            ->whereKey($presetId)
            ->first();

        if ($preset === null) {
            throw ValidationException::withMessages([
                'document_workflow_preset_id' => 'The selected workflow preset was not found for this company.',
            ]);
        }

        if (! $allowInactive && $preset->status !== DocumentWorkflowPresetStatus::Active) {
            throw ValidationException::withMessages([
                'document_workflow_preset_id' => 'The selected workflow preset must be active.',
            ]);
        }

        return $preset;
    }

    public function assertActiveSigningPreset(int $presetId, int $companyId, bool $allowInactive = false): DocumentSigningPreset
    {
        $preset = DocumentSigningPreset::query()
            ->where('company_id', $companyId)
            ->whereKey($presetId)
            ->first();

        if ($preset === null) {
            throw ValidationException::withMessages([
                'document_signing_preset_id' => 'The selected signing preset was not found for this company.',
            ]);
        }

        if (! $allowInactive && $preset->status !== DocumentSigningPresetStatus::Active) {
            throw ValidationException::withMessages([
                'document_signing_preset_id' => 'The selected signing preset must be active.',
            ]);
        }

        return $preset;
    }

    public function assertSigningPlacementCoversPreset(
        DocumentGenerationTemplateVersion $version,
        DocumentSigningPreset $preset,
    ): void {
        $preset->loadMissing('steps');
        $config = $version->signature_placement_config;
        $pageCount = (int) ($version->source_pdf_page_count ?? 0);

        if (! is_array($config) || $preset->steps->isEmpty()) {
            throw new DomainException(
                'Publish requires signature placement for every signing preset step.',
            );
        }

        $managerOccurrence = 0;
        $companyOccurrence = 0;

        foreach ($preset->steps->sortBy('sequence') as $step) {
            $role = $step->recipient_role instanceof DocumentRecipientRole
                ? $step->recipient_role
                : DocumentRecipientRole::from((string) $step->recipient_role);

            $slotKey = match ($role) {
                DocumentRecipientRole::Subject => DocumentSignatureSlot::SUBJECT,
                DocumentRecipientRole::Manager => DocumentSignatureSlot::forRoleOccurrence(
                    DocumentRecipientRole::Manager,
                    ++$managerOccurrence,
                ),
                DocumentRecipientRole::CompanySignatory => DocumentSignatureSlot::forRoleOccurrence(
                    DocumentRecipientRole::CompanySignatory,
                    ++$companyOccurrence,
                ),
                default => throw new DomainException('Unsupported signing preset recipient role for publish validation.'),
            };

            try {
                DocumentSignaturePlacementValidator::validateSignatureForSlot(
                    $config,
                    $pageCount,
                    $slotKey,
                );
            } catch (\InvalidArgumentException $exception) {
                throw new DomainException(
                    'Publish requires signature placement for every signing preset step: '.$exception->getMessage(),
                    0,
                    $exception,
                );
            }
        }
    }

    public function behaviorSummary(?int $workflowPresetId, ?int $signingPresetId): string
    {
        return match (true) {
            $workflowPresetId !== null && $signingPresetId !== null => 'Generate → Review & Approval → Signing',
            $workflowPresetId !== null => 'Generate → Review & Approval',
            $signingPresetId !== null => 'Generate → Signing',
            default => 'Generate only',
        };
    }

    private function normalizeNullableId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        if (! is_numeric($value)) {
            throw ValidationException::withMessages([
                'document_workflow_preset_id' => 'Preset id must be an integer or null.',
            ]);
        }

        return (int) $value;
    }
}
