<?php

namespace App\Support\Documents;

use App\Enums\DocumentRecipientRole;
use App\Enums\DocumentSigningPresetStatus;
use App\Enums\DocumentWorkflowPresetStatus;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\DocumentSigningPreset;
use App\Models\DocumentWorkflowPreset;
use App\Models\User;
use App\Support\Documents\Signing\DocumentSignatureSlot;
use App\Support\Documents\Signing\DocumentSigningPresetFormOptions;
use App\Support\Documents\Signing\DocumentSigningPresetPagePermissions;
use App\Support\Documents\Workflow\DocumentWorkflowPresetFormOptions;
use App\Support\Documents\Workflow\DocumentWorkflowPresetPagePermissions;

final class DocumentGenerationTemplateDesignerOptions
{
    public function __construct(
        private DocumentGenerationTemplateReadiness $readiness,
        private DocumentWorkflowPresetFormOptions $workflowFormOptions,
        private DocumentSigningPresetFormOptions $signingFormOptions,
    ) {}

    /**
     * @return array{
     *     workflow_presets: list<array<string, mixed>>,
     *     signing_presets: list<array<string, mixed>>,
     *     workflow_form_options: array<string, mixed>|null,
     *     signing_form_options: array<string, mixed>|null,
     *     readiness: array<string, mixed>,
     *     can: array{create_workflow_presets: bool, create_signing_presets: bool}
     * }
     */
    public function for(
        ?User $user,
        int $companyId,
        DocumentGenerationTemplate $template,
        DocumentGenerationTemplateVersion $version,
    ): array {
        $workflowCan = DocumentWorkflowPresetPagePermissions::for($user);
        $signingCan = DocumentSigningPresetPagePermissions::for($user);

        return [
            'workflow_presets' => $this->workflowPresets($companyId),
            'signing_presets' => $this->signingPresets($companyId),
            'workflow_form_options' => $workflowCan['create']
                ? [
                    'users' => $this->workflowFormOptions->users($companyId),
                    'roles' => $this->workflowFormOptions->roles($companyId),
                    'target_types' => $this->workflowFormOptions->targetTypes(),
                ]
                : null,
            'signing_form_options' => $signingCan['create']
                ? $this->signingFormOptions->forCompany($companyId)
                : null,
            'readiness' => $this->readiness->evaluate($version, $template),
            'can' => [
                'create_workflow_presets' => $workflowCan['create'],
                'create_signing_presets' => $signingCan['create'],
            ],
        ];
    }

    /**
     * @return list<array{id: int, name: string, status: string, is_active: bool, stages: list<array{sequence: int, action_label: string}>}>
     */
    public function workflowPresets(int $companyId): array
    {
        return DocumentWorkflowPreset::query()
            ->forCompany($companyId)
            ->with('stages')
            ->orderBy('name')
            ->get()
            ->map(function (DocumentWorkflowPreset $preset): array {
                $stages = $preset->stages->sortBy('sequence')->values()->map(fn ($stage): array => [
                    'sequence' => (int) $stage->sequence,
                    'action_label' => $stage->action->label(),
                ])->all();

                return [
                    'id' => $preset->id,
                    'name' => (string) $preset->name,
                    'status' => $preset->status->value,
                    'is_active' => $preset->status === DocumentWorkflowPresetStatus::Active,
                    'stages' => $stages,
                ];
            })
            ->all();
    }

    /**
     * @return list<array{id: int, name: string, status: string, is_active: bool, steps: list<array{sequence: int, recipient_role: string, display_label: string, slot_key: string}>}>
     */
    public function signingPresets(int $companyId): array
    {
        return DocumentSigningPreset::query()
            ->forCompany($companyId)
            ->with('steps')
            ->orderBy('name')
            ->get()
            ->map(function (DocumentSigningPreset $preset): array {
                $managerOccurrence = 0;
                $companyOccurrence = 0;

                $steps = $preset->steps->sortBy('sequence')->values()->map(function ($step) use (&$managerOccurrence, &$companyOccurrence): array {
                    $role = $step->recipient_role;
                    $occurrence = match ($role) {
                        DocumentRecipientRole::Manager => ++$managerOccurrence,
                        DocumentRecipientRole::CompanySignatory => ++$companyOccurrence,
                        default => 1,
                    };

                    return [
                        'sequence' => (int) $step->sequence,
                        'recipient_role' => $role->value,
                        'display_label' => filled($step->step_label)
                            ? (string) $step->step_label
                            : DocumentSignatureSlot::defaultLabel($role, $occurrence),
                        'slot_key' => DocumentSignatureSlot::forRoleOccurrence($role, $occurrence),
                    ];
                })->all();

                return [
                    'id' => $preset->id,
                    'name' => (string) $preset->name,
                    'status' => $preset->status->value,
                    'is_active' => $preset->status === DocumentSigningPresetStatus::Active,
                    'steps' => $steps,
                ];
            })
            ->all();
    }
}
