<?php

namespace App\Support\Documents;

use App\Enums\DocumentGenerationTemplateFormat;
use App\Enums\DocumentRecipientRole;
use App\Enums\DocumentSigningPresetStatus;
use App\Enums\DocumentTemplateAutomationMode;
use App\Enums\DocumentWorkflowPresetStatus;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\DocumentSigningPreset;
use App\Support\Documents\Lifecycle\DocumentLifecycleAutomationPolicy;
use App\Support\Documents\RecipientRequests\DocumentSignaturePlacementValidator;
use App\Support\Documents\Signing\DocumentSignatureSlot;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class DocumentGenerationTemplateReadiness
{
    public const CODE_SOURCE_PDF_MISSING = 'source_pdf_missing';

    public const CODE_PLACEMENT_CONFIG_INVALID = 'placement_config_invalid';

    public const CODE_SIGNATURE_PLACEMENT_INVALID = 'signature_placement_invalid';

    public const CODE_WORKFLOW_DECISION_MISSING = 'workflow_decision_missing';

    public const CODE_WORKFLOW_PRESET_MISSING = 'workflow_preset_missing';

    public const CODE_WORKFLOW_PRESET_CONFLICT = 'workflow_preset_conflict';

    public const CODE_WORKFLOW_PRESET_UNAVAILABLE = 'workflow_preset_unavailable';

    public const CODE_WORKFLOW_PRESET_INACTIVE = 'workflow_preset_inactive';

    public const CODE_SIGNING_DECISION_MISSING = 'signing_decision_missing';

    public const CODE_SIGNING_PRESET_MISSING = 'signing_preset_missing';

    public const CODE_SIGNING_PRESET_CONFLICT = 'signing_preset_conflict';

    public const CODE_SIGNING_PRESET_UNAVAILABLE = 'signing_preset_unavailable';

    public const CODE_SIGNING_PRESET_INACTIVE = 'signing_preset_inactive';

    public const CODE_SIGNING_PLACEMENT_MISSING = 'signing_placement_missing';

    public const CODE_SIGNING_PLACEMENTS_CONFLICT = 'signing_placements_conflict';

    public const CODE_VERSION_NOT_DRAFT = 'version_not_draft';

    public const CODE_LEGACY_WORKFLOW_UNCONFIGURED = 'legacy_workflow_unconfigured';

    public const CODE_LEGACY_SIGNING_UNCONFIGURED = 'legacy_signing_unconfigured';

    public function __construct(
        private DocumentLifecycleAutomationPolicy $policy = new DocumentLifecycleAutomationPolicy,
    ) {}

    /**
     * @return array{
     *     ready: bool,
     *     blocking_count: int,
     *     warning_count: int,
     *     historical: bool,
     *     sections: array{
     *         design: list<array<string, mixed>>,
     *         workflow: list<array<string, mixed>>,
     *         signing: list<array<string, mixed>>,
     *         version: list<array<string, mixed>>
     *     },
     *     issues: list<array{
     *         code: string,
     *         section: string,
     *         severity: string,
     *         blocking: bool,
     *         message: string,
     *         meta: array<string, mixed>
     *     }>
     * }
     */
    public function evaluate(
        DocumentGenerationTemplateVersion $version,
        DocumentGenerationTemplate $template,
    ): array {
        $historical = ! $version->isDraft();
        $issues = [];

        if ($template->isPdfOverlay()) {
            $issues = array_merge($issues, $this->designIssues($version, $template));
            $issues = array_merge($issues, $this->workflowIssues($version, $historical));
            $issues = array_merge($issues, $this->signingIssues($version, $template, $historical));
        } else {
            $issues = array_merge($issues, $this->workflowIssues($version, $historical));
        }

        $issues = array_merge($issues, $this->versionIssues($version, $historical));

        $blocking = array_values(array_filter($issues, fn (array $issue): bool => $issue['blocking']));
        $warnings = array_values(array_filter(
            $issues,
            fn (array $issue): bool => ! $issue['blocking'] && $issue['severity'] === 'warning',
        ));

        $sections = [
            'design' => [],
            'workflow' => [],
            'signing' => [],
            'version' => [],
        ];

        foreach ($issues as $issue) {
            $sections[$issue['section']][] = $issue;
        }

        return [
            'ready' => $blocking === [] && $version->isDraft(),
            'blocking_count' => count($blocking),
            'warning_count' => count($warnings),
            'historical' => $historical,
            'sections' => $sections,
            'issues' => $issues,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function evaluateForPublish(
        DocumentGenerationTemplateVersion $version,
        DocumentGenerationTemplate $template,
    ): array {
        $result = $this->evaluate($version, $template);

        if ($result['blocking_count'] > 0) {
            $first = $result['issues'][array_key_first(array_filter(
                $result['issues'],
                fn (array $issue): bool => $issue['blocking'],
            ))] ?? null;

            $message = is_array($first)
                ? (string) $first['message']
                : 'Workflow setup is incomplete.';

            $errors = [];
            foreach ($result['issues'] as $issue) {
                if (! $issue['blocking']) {
                    continue;
                }

                $field = match ($issue['section']) {
                    'workflow' => 'document_workflow_mode',
                    'signing' => 'document_signing_mode',
                    'design' => 'placement_config',
                    default => 'version',
                };

                $errors[$field][] = $issue['message'];
            }

            throw ValidationException::withMessages($errors === [] ? ['version' => $message] : $errors);
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function designIssues(
        DocumentGenerationTemplateVersion $version,
        DocumentGenerationTemplate $template,
    ): array {
        $issues = [];
        $pageCount = (int) ($version->source_pdf_page_count ?? 0);

        if ($version->source_pdf_path === null || trim((string) $version->source_pdf_path) === ''
            || ! DocumentTemplateStorage::exists((string) $version->source_pdf_path, (int) $template->company_id)
        ) {
            $issues[] = $this->issue(
                self::CODE_SOURCE_PDF_MISSING,
                'design',
                'error',
                blocking: $version->isDraft(),
                message: 'Upload a valid PDF source before publishing.',
            );
        }

        $placementConfig = $version->placement_config;
        if ($placementConfig === null) {
            $placementConfig = PdfOverlayPlacementValidator::emptyConfig();
        }

        try {
            PdfOverlayPlacementValidator::validate(
                is_array($placementConfig) ? $placementConfig : null,
                $pageCount > 0 ? $pageCount : 1,
            );
        } catch (InvalidArgumentException $exception) {
            $issues[] = $this->issue(
                self::CODE_PLACEMENT_CONFIG_INVALID,
                'design',
                'error',
                blocking: $version->isDraft(),
                message: $exception->getMessage(),
            );
        }

        $signatureConfig = $version->signature_placement_config;
        if (is_array($signatureConfig) && $signatureConfig !== []) {
            try {
                DocumentSignaturePlacementValidator::validateSignaturePlacementConfig(
                    $signatureConfig,
                    $pageCount > 0 ? $pageCount : 1,
                );
            } catch (InvalidArgumentException $exception) {
                $issues[] = $this->issue(
                    self::CODE_SIGNATURE_PLACEMENT_INVALID,
                    'design',
                    'error',
                    blocking: $version->isDraft(),
                    message: $exception->getMessage(),
                );
            }
        }

        return $issues;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function workflowIssues(DocumentGenerationTemplateVersion $version, bool $historical): array
    {
        $storedMode = DocumentTemplateAutomationBindings::parseStoredMode($version->document_workflow_mode);
        $presetId = $version->document_workflow_preset_id !== null
            ? (int) $version->document_workflow_preset_id
            : null;
        $effective = DocumentTemplateAutomationBindings::effectiveMode($storedMode, $presetId);
        $companyId = (int) $version->company_id;

        if ($historical && $storedMode === null) {
            return [
                $this->issue(
                    self::CODE_LEGACY_WORKFLOW_UNCONFIGURED,
                    'workflow',
                    'info',
                    blocking: false,
                    message: 'Legacy version. No explicit workflow decision was recorded.',
                ),
            ];
        }

        if ($effective === null) {
            return [
                $this->issue(
                    self::CODE_WORKFLOW_DECISION_MISSING,
                    'workflow',
                    'error',
                    blocking: ! $historical,
                    message: 'Choose whether review and approval are required.',
                    meta: ['fix' => 'configure_workflow'],
                ),
            ];
        }

        if ($storedMode === DocumentTemplateAutomationMode::None && $presetId !== null) {
            return [
                $this->issue(
                    self::CODE_WORKFLOW_PRESET_CONFLICT,
                    'workflow',
                    'error',
                    blocking: ! $historical,
                    message: 'Review is set to none, so a workflow preset cannot be attached.',
                ),
            ];
        }

        if ($effective === DocumentTemplateAutomationMode::Preset) {
            if ($presetId === null) {
                return [
                    $this->issue(
                        self::CODE_WORKFLOW_PRESET_MISSING,
                        'workflow',
                        'error',
                        blocking: ! $historical,
                        message: 'Select an approval flow.',
                        meta: ['fix' => 'configure_workflow'],
                    ),
                ];
            }

            try {
                $preset = $this->policy->assertActiveWorkflowPreset($presetId, $companyId, allowInactive: true);
            } catch (ValidationException) {
                return [
                    $this->issue(
                        self::CODE_WORKFLOW_PRESET_UNAVAILABLE,
                        'workflow',
                        'error',
                        blocking: ! $historical,
                        message: 'The selected workflow preset was not found for this company.',
                    ),
                ];
            }

            if ($preset->status !== DocumentWorkflowPresetStatus::Active) {
                return [
                    $this->issue(
                        self::CODE_WORKFLOW_PRESET_INACTIVE,
                        'workflow',
                        'error',
                        blocking: ! $historical,
                        message: 'The selected workflow preset must be active.',
                    ),
                ];
            }
        }

        return [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function signingIssues(
        DocumentGenerationTemplateVersion $version,
        DocumentGenerationTemplate $template,
        bool $historical,
    ): array {
        $storedMode = DocumentTemplateAutomationBindings::parseStoredMode($version->document_signing_mode);
        $presetId = $version->document_signing_preset_id !== null
            ? (int) $version->document_signing_preset_id
            : null;
        $effective = DocumentTemplateAutomationBindings::effectiveMode($storedMode, $presetId);
        $companyId = (int) $version->company_id;

        if ($historical && $storedMode === null) {
            return [
                $this->issue(
                    self::CODE_LEGACY_SIGNING_UNCONFIGURED,
                    'signing',
                    'info',
                    blocking: false,
                    message: 'Legacy version. No explicit signing decision was recorded.',
                ),
            ];
        }

        if ($effective === null) {
            return [
                $this->issue(
                    self::CODE_SIGNING_DECISION_MISSING,
                    'signing',
                    'error',
                    blocking: ! $historical,
                    message: 'Choose whether signatures are required.',
                    meta: ['fix' => 'configure_signing'],
                ),
            ];
        }

        if ($storedMode === DocumentTemplateAutomationMode::None && $presetId !== null) {
            return [
                $this->issue(
                    self::CODE_SIGNING_PRESET_CONFLICT,
                    'signing',
                    'error',
                    blocking: ! $historical,
                    message: 'Signing is set to none, so a signing preset cannot be attached.',
                ),
            ];
        }

        $issues = [];

        if ($effective === DocumentTemplateAutomationMode::None) {
            if (DocumentTemplateAutomationBindings::hasSignaturePlacements($version->signature_placement_config)) {
                $issues[] = $this->issue(
                    self::CODE_SIGNING_PLACEMENTS_CONFLICT,
                    'signing',
                    'error',
                    blocking: ! $historical,
                    message: 'Signature placements remain on the PDF, but signing is not required.',
                    meta: ['fix' => 'remove_signature_placements'],
                );
            }

            return $issues;
        }

        if ($template->template_format !== DocumentGenerationTemplateFormat::PdfOverlay) {
            $issues[] = $this->issue(
                self::CODE_SIGNING_PRESET_UNAVAILABLE,
                'signing',
                'error',
                blocking: ! $historical,
                message: 'Automatic signing is only available for PDF Overlay templates.',
            );

            return $issues;
        }

        if ($presetId === null) {
            return [
                $this->issue(
                    self::CODE_SIGNING_PRESET_MISSING,
                    'signing',
                    'error',
                    blocking: ! $historical,
                    message: 'Select a signing flow.',
                    meta: ['fix' => 'configure_signing'],
                ),
            ];
        }

        try {
            $preset = $this->policy->assertActiveSigningPreset($presetId, $companyId, allowInactive: true);
        } catch (ValidationException) {
            return [
                $this->issue(
                    self::CODE_SIGNING_PRESET_UNAVAILABLE,
                    'signing',
                    'error',
                    blocking: ! $historical,
                    message: 'The selected signing preset was not found for this company.',
                ),
            ];
        }

        if ($preset->status !== DocumentSigningPresetStatus::Active) {
            $issues[] = $this->issue(
                self::CODE_SIGNING_PRESET_INACTIVE,
                'signing',
                'error',
                blocking: ! $historical,
                message: 'The selected signing preset must be active.',
            );
        }

        $preset->loadMissing('steps');
        $issues = array_merge($issues, $this->signingPlacementIssues($version, $preset, $historical));

        return $issues;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function signingPlacementIssues(
        DocumentGenerationTemplateVersion $version,
        DocumentSigningPreset $preset,
        bool $historical,
    ): array {
        $issues = [];
        $config = $version->signature_placement_config;
        $pageCount = (int) ($version->source_pdf_page_count ?? 0);
        $managerOccurrence = 0;
        $companyOccurrence = 0;

        foreach ($preset->steps->sortBy('sequence') as $step) {
            $role = $step->recipient_role instanceof DocumentRecipientRole
                ? $step->recipient_role
                : DocumentRecipientRole::from((string) $step->recipient_role);

            $occurrence = match ($role) {
                DocumentRecipientRole::Manager => ++$managerOccurrence,
                DocumentRecipientRole::CompanySignatory => ++$companyOccurrence,
                default => 1,
            };

            $slotKey = DocumentSignatureSlot::forRoleOccurrence($role, $occurrence);
            $label = DocumentSignatureSlot::defaultLabel($role, $occurrence);

            try {
                if (! is_array($config)) {
                    throw new InvalidArgumentException('Signature placement is missing.');
                }

                DocumentSignaturePlacementValidator::validateSignatureForSlot(
                    $config,
                    $pageCount,
                    $slotKey,
                );
            } catch (InvalidArgumentException) {
                $issues[] = $this->issue(
                    self::CODE_SIGNING_PLACEMENT_MISSING,
                    'signing',
                    'error',
                    blocking: ! $historical,
                    message: "{$label} signature placement is missing.",
                    meta: [
                        'fix' => 'place_on_pdf',
                        'slot_key' => $slotKey,
                        'label' => $label,
                        'sequence' => (int) $step->sequence,
                    ],
                );
            }
        }

        return $issues;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function versionIssues(DocumentGenerationTemplateVersion $version, bool $historical): array
    {
        if ($historical) {
            return [
                $this->issue(
                    self::CODE_VERSION_NOT_DRAFT,
                    'version',
                    'info',
                    blocking: false,
                    message: 'Published and archived versions are read-only.',
                ),
            ];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{
     *     code: string,
     *     section: string,
     *     severity: string,
     *     blocking: bool,
     *     message: string,
     *     meta: array<string, mixed>
     * }
     */
    private function issue(
        string $code,
        string $section,
        string $severity,
        bool $blocking,
        string $message,
        array $meta = [],
    ): array {
        return [
            'code' => $code,
            'section' => $section,
            'severity' => $severity,
            'blocking' => $blocking,
            'message' => $message,
            'meta' => $meta,
        ];
    }
}
