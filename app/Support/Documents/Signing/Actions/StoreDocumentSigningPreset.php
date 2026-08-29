<?php

namespace App\Support\Documents\Signing\Actions;

use App\Enums\DocumentRecipientRole;
use App\Enums\DocumentSigningPresetStatus;
use App\Enums\DocumentSigningTargetType;
use App\Models\DocumentSigningPreset;
use App\Models\DocumentSigningPresetStep;
use App\Models\User;
use App\Support\Documents\Signing\DocumentSignatureSlot;
use App\Support\Documents\Signing\DocumentSigningPresetActivityLogger;
use App\Support\Documents\Signing\DocumentSigningPresetValidator;
use Illuminate\Support\Facades\DB;

final class StoreDocumentSigningPreset
{
    public function __construct(
        private DocumentSigningPresetValidator $validator = new DocumentSigningPresetValidator,
        private DocumentSigningPresetActivityLogger $activityLogger = new DocumentSigningPresetActivityLogger,
    ) {}

    /**
     * @param  list<array{recipient_role: string, target_type?: string|null, target_user_id?: int|null, step_label?: string|null}>  $steps
     */
    public function handle(
        User $actor,
        int $companyId,
        string $name,
        ?string $description,
        array $steps,
    ): DocumentSigningPreset {
        $this->validator->validateSteps($companyId, $steps);

        return DB::transaction(function () use ($actor, $companyId, $name, $description, $steps): DocumentSigningPreset {
            $preset = DocumentSigningPreset::query()->create([
                'company_id' => $companyId,
                'name' => $name,
                'description' => $description,
                'status' => DocumentSigningPresetStatus::Active,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->syncSteps($preset, $companyId, $steps);

            $this->activityLogger->log(
                description: 'Document signing preset created',
                event: 'signing_preset_created',
                preset: $preset,
                actor: $actor,
            );

            return $preset->load(['steps.targetUser']);
        });
    }

    /**
     * @param  list<array{recipient_role: string, target_type?: string|null, target_user_id?: int|null, step_label?: string|null}>  $steps
     */
    public function syncSteps(DocumentSigningPreset $preset, int $companyId, array $steps): void
    {
        DocumentSigningPresetStep::query()
            ->where('document_signing_preset_id', $preset->id)
            ->delete();

        $managerOccurrence = 0;
        $companyOccurrence = 0;

        foreach (array_values($steps) as $index => $stepInput) {
            $role = DocumentRecipientRole::from((string) $stepInput['recipient_role']);
            $targetType = match ($role) {
                DocumentRecipientRole::Subject => DocumentSigningTargetType::SubjectEmployee,
                DocumentRecipientRole::Manager => DocumentSigningTargetType::DepartmentManager,
                DocumentRecipientRole::CompanySignatory => DocumentSigningTargetType::SpecificUser,
                default => DocumentSigningTargetType::SubjectEmployee,
            };

            $occurrence = match ($role) {
                DocumentRecipientRole::Subject => 1,
                DocumentRecipientRole::Manager => ++$managerOccurrence,
                DocumentRecipientRole::CompanySignatory => ++$companyOccurrence,
                default => 1,
            };

            $label = isset($stepInput['step_label']) ? trim((string) $stepInput['step_label']) : '';

            DocumentSigningPresetStep::query()->create([
                'company_id' => $companyId,
                'document_signing_preset_id' => $preset->id,
                'sequence' => $index + 1,
                'recipient_role' => $role,
                'target_type' => $targetType,
                'target_user_id' => $role === DocumentRecipientRole::CompanySignatory
                    ? (int) $stepInput['target_user_id']
                    : null,
                'step_label' => $label !== ''
                    ? $label
                    : DocumentSignatureSlot::defaultLabel($role, $occurrence),
            ]);
        }
    }
}
