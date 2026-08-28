<?php

namespace App\Support\Documents\Workflow\Actions;

use App\Enums\DocumentWorkflowPresetStatus;
use App\Models\DocumentWorkflowPreset;
use App\Models\User;
use App\Support\Documents\Workflow\DocumentWorkflowPresetActivityLogger;

final class ActivateDocumentWorkflowPreset
{
    public function __construct(
        private readonly DocumentWorkflowPresetActivityLogger $activityLogger = new DocumentWorkflowPresetActivityLogger,
    ) {}

    public function handle(DocumentWorkflowPreset $preset, User $actor, int $companyId): DocumentWorkflowPreset
    {
        abort_unless((int) $preset->company_id === $companyId, 404);

        $preset->update(['status' => DocumentWorkflowPresetStatus::Active]);

        $this->activityLogger->log(
            description: 'Document workflow preset activated',
            event: 'workflow_preset_activated',
            preset: $preset,
            actor: $actor,
        );

        return $preset;
    }
}
