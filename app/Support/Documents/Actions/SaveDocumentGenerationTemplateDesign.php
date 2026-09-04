<?php

namespace App\Support\Documents\Actions;

use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Support\Documents\DocumentTemplateAutomationBindings;
use App\Support\Documents\NormalizeDraftPdfOverlayPlacements;
use App\Support\Documents\RecipientRequests\DocumentSignaturePlacementValidator;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Spatie\Activitylog\Models\Activity;

final class SaveDocumentGenerationTemplateDesign
{
    /**
     * @param  list<array<string, mixed>>  $rawPlacements
     * @param  array{schema_version?: mixed, placements?: mixed}  $rawSignatureConfig
     * @param  array{
     *     document_workflow_mode?: mixed,
     *     document_workflow_preset_id?: mixed,
     *     document_signing_mode?: mixed,
     *     document_signing_preset_id?: mixed
     * }|null  $automationBindings
     */
    public function handle(
        DocumentGenerationTemplateVersion $version,
        array $rawPlacements,
        array $rawSignatureConfig,
        ?int $userId = null,
        ?array $automationBindings = null,
    ): DocumentGenerationTemplateVersion {
        return DB::transaction(function () use ($version, $rawPlacements, $rawSignatureConfig, $userId, $automationBindings): DocumentGenerationTemplateVersion {
            /** @var DocumentGenerationTemplate $lockedTemplate */
            $lockedTemplate = DocumentGenerationTemplate::query()
                ->whereKey($version->document_generation_template_id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var DocumentGenerationTemplateVersion $lockedVersion */
            $lockedVersion = DocumentGenerationTemplateVersion::query()
                ->whereKey($version->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $lockedVersion->document_generation_template_id !== (int) $lockedTemplate->id
                || (int) $lockedVersion->company_id !== (int) $lockedTemplate->company_id
            ) {
                throw new DomainException('Template version does not match parent template.');
            }

            if (! $lockedVersion->isDraft()) {
                throw ValidationException::withMessages([
                    'version' => 'Published or archived template versions cannot be edited.',
                ]);
            }

            if (! $lockedTemplate->isPdfOverlay()) {
                throw new DomainException('Cannot save design for a content template.');
            }

            $pageCount = (int) ($lockedVersion->source_pdf_page_count ?? 1);
            $validatedPlacements = NormalizeDraftPdfOverlayPlacements::handle($rawPlacements, $pageCount);

            // --- Validate signature placements ---
            try {
                $validatedSigConfig = DocumentSignaturePlacementValidator::normalizeForDraftSave(
                    $rawSignatureConfig,
                    $pageCount,
                );
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages([
                    'signature_placement_config' => $e->getMessage(),
                ]);
            }

            $normalizedSignatures = array_map(
                fn (array $placement): array => DocumentSignaturePlacementValidator::toPersistedPlacement($placement),
                $validatedSigConfig['placements'],
            );

            // --- Single save: both configs in one DB write ---
            $previousWorkflowMode = $lockedVersion->document_workflow_mode;
            $previousWorkflowPresetId = $lockedVersion->document_workflow_preset_id !== null
                ? (int) $lockedVersion->document_workflow_preset_id
                : null;
            $previousSigningMode = $lockedVersion->document_signing_mode;
            $previousSigningPresetId = $lockedVersion->document_signing_preset_id !== null
                ? (int) $lockedVersion->document_signing_preset_id
                : null;

            $validatedAutomation = null;
            if ($automationBindings !== null) {
                $validatedAutomation = DocumentTemplateAutomationBindings::validateForDraftSave(
                    $lockedTemplate,
                    $automationBindings,
                    (int) $lockedTemplate->company_id,
                );
                $lockedVersion->document_workflow_mode = $validatedAutomation['document_workflow_mode'];
                $lockedVersion->document_workflow_preset_id = $validatedAutomation['document_workflow_preset_id'];
                $lockedVersion->document_signing_mode = $validatedAutomation['document_signing_mode'];
                $lockedVersion->document_signing_preset_id = $validatedAutomation['document_signing_preset_id'];
            }

            $lockedVersion->placement_config = [
                'schema_version' => 2,
                'placements' => $validatedPlacements,
            ];
            $lockedVersion->signature_placement_config = [
                'schema_version' => $validatedSigConfig['schema_version'],
                'placements' => $normalizedSignatures,
            ];
            $lockedVersion->updated_by = $userId;
            $lockedVersion->save();

            $companyId = (int) $lockedTemplate->company_id;
            $properties = [
                'action' => 'template_design_saved',
                'template_id' => $lockedTemplate->id,
                'version' => $lockedVersion->version,
                'placement_count' => count($validatedPlacements),
                'signature_count' => count($normalizedSignatures),
                'page_count' => $pageCount,
            ];

            if ($validatedAutomation !== null) {
                $properties['document_workflow_mode'] = $validatedAutomation['document_workflow_mode']?->value;
                $properties['document_workflow_preset_id'] = $validatedAutomation['document_workflow_preset_id'];
                $properties['document_signing_mode'] = $validatedAutomation['document_signing_mode']?->value;
                $properties['document_signing_preset_id'] = $validatedAutomation['document_signing_preset_id'];
                $properties['review_decision_changed'] = ($previousWorkflowMode?->value ?? null) !== ($validatedAutomation['document_workflow_mode']?->value ?? null);
                $properties['review_preset_changed'] = $previousWorkflowPresetId !== $validatedAutomation['document_workflow_preset_id'];
                $properties['signing_decision_changed'] = ($previousSigningMode?->value ?? null) !== ($validatedAutomation['document_signing_mode']?->value ?? null);
                $properties['signing_preset_changed'] = $previousSigningPresetId !== $validatedAutomation['document_signing_preset_id'];
            }

            activity('document_templates')
                ->performedOn($lockedTemplate)
                ->causedBy($userId)
                ->tap(function (Activity $activity) use ($companyId): void {
                    $activity->company_id = $companyId;
                })
                ->withProperties($properties)
                ->log("Draft saved for template {$lockedTemplate->name} (v{$lockedVersion->version})");

            return $lockedVersion;
        });
    }
}
