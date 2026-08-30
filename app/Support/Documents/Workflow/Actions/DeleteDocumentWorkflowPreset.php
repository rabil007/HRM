<?php

namespace App\Support\Documents\Workflow\Actions;

use App\Models\DocumentGenerationTemplateVersion;
use App\Models\DocumentWorkflowPreset;
use App\Models\DocumentWorkflowPresetStage;
use App\Models\User;
use App\Support\Documents\Workflow\DocumentWorkflowPresetActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeleteDocumentWorkflowPreset
{
    public function __construct(
        private readonly DocumentWorkflowPresetActivityLogger $activityLogger = new DocumentWorkflowPresetActivityLogger,
    ) {}

    public function handle(DocumentWorkflowPreset $preset, User $actor, int $companyId): void
    {
        abort_unless((int) $preset->company_id === $companyId, 404);

        DB::transaction(function () use ($preset, $actor, $companyId): void {
            $lockedPreset = DocumentWorkflowPreset::query()
                ->forCompany($companyId)
                ->whereKey($preset->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPreset->workflowRequests()->exists()) {
                throw ValidationException::withMessages([
                    'preset' => ['This preset has already been used and cannot be deleted. Deactivate it instead.'],
                ]);
            }

            if (DocumentGenerationTemplateVersion::query()
                ->where('company_id', $companyId)
                ->where('document_workflow_preset_id', $lockedPreset->id)
                ->exists()
            ) {
                throw ValidationException::withMessages([
                    'preset' => ['Preset is used by a document template version. Deactivate it instead.'],
                ]);
            }

            $lockedPreset->stages()->each(function (DocumentWorkflowPresetStage $stage): void {
                $stage->targets()->delete();
            });
            $lockedPreset->stages()->delete();

            $this->activityLogger->log(
                description: 'Document workflow preset deleted',
                event: 'workflow_preset_deleted',
                preset: $lockedPreset,
                actor: $actor,
            );

            $lockedPreset->delete();
        });
    }
}
