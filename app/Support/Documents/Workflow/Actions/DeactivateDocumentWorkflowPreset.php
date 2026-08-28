<?php

namespace App\Support\Documents\Workflow\Actions;

use App\Enums\DocumentWorkflowPresetStatus;
use App\Models\DocumentWorkflowPreset;
use App\Models\User;
use App\Support\Documents\Workflow\DocumentWorkflowPresetActivityLogger;
use Illuminate\Support\Facades\DB;

final class DeactivateDocumentWorkflowPreset
{
    public function __construct(
        private readonly DocumentWorkflowPresetActivityLogger $activityLogger = new DocumentWorkflowPresetActivityLogger,
    ) {}

    public function handle(DocumentWorkflowPreset $preset, User $actor, int $companyId): DocumentWorkflowPreset
    {
        abort_unless((int) $preset->company_id === $companyId, 404);

        return DB::transaction(function () use ($preset, $actor, $companyId): DocumentWorkflowPreset {
            $lockedPreset = DocumentWorkflowPreset::query()
                ->forCompany($companyId)
                ->whereKey($preset->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedPreset->update(['status' => DocumentWorkflowPresetStatus::Inactive]);

            $this->activityLogger->log(
                description: 'Document workflow preset deactivated',
                event: 'workflow_preset_deactivated',
                preset: $lockedPreset,
                actor: $actor,
            );

            return $lockedPreset;
        });
    }
}
