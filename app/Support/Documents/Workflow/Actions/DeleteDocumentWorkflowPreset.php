<?php

namespace App\Support\Documents\Workflow\Actions;

use App\Models\DocumentWorkflowPreset;
use App\Models\DocumentWorkflowPresetStage;
use App\Models\User;
use App\Support\Documents\Workflow\DocumentWorkflowPresetActivityLogger;
use Illuminate\Validation\ValidationException;

final class DeleteDocumentWorkflowPreset
{
    public function __construct(
        private readonly DocumentWorkflowPresetActivityLogger $activityLogger = new DocumentWorkflowPresetActivityLogger,
    ) {}

    public function handle(DocumentWorkflowPreset $preset, User $actor, int $companyId): void
    {
        abort_unless((int) $preset->company_id === $companyId, 404);

        if ($preset->workflowRequests()->exists()) {
            throw ValidationException::withMessages([
                'preset' => ['This preset has already been used and cannot be deleted. Deactivate it instead.'],
            ]);
        }

        $preset->stages()->each(function (DocumentWorkflowPresetStage $stage): void {
            $stage->targets()->delete();
        });
        $preset->stages()->delete();

        $this->activityLogger->log(
            description: 'Document workflow preset deleted',
            event: 'workflow_preset_deleted',
            preset: $preset,
            actor: $actor,
        );

        $preset->delete();
    }
}
