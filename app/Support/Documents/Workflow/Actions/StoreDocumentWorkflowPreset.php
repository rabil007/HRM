<?php

namespace App\Support\Documents\Workflow\Actions;

use App\Enums\DocumentWorkflowAction;
use App\Enums\DocumentWorkflowCompletionRule;
use App\Enums\DocumentWorkflowPresetStatus;
use App\Enums\DocumentWorkflowTargetType;
use App\Models\DocumentWorkflowPreset;
use App\Models\DocumentWorkflowPresetStage;
use App\Models\DocumentWorkflowPresetTarget;
use App\Models\User;
use App\Support\Documents\Workflow\DocumentWorkflowPresetActivityLogger;
use App\Support\Documents\Workflow\DocumentWorkflowPresetValidator;
use Illuminate\Support\Facades\DB;

final class StoreDocumentWorkflowPreset
{
    public function __construct(
        private readonly DocumentWorkflowPresetValidator $validator = new DocumentWorkflowPresetValidator,
        private readonly DocumentWorkflowPresetActivityLogger $activityLogger = new DocumentWorkflowPresetActivityLogger,
    ) {}

    /**
     * @param  list<array{action: string, completion_rule: string, targets: list<array{target_type: string, target_user_id?: int|null, target_role_id?: int|null}>}>  $stages
     */
    public function handle(
        User $actor,
        int $companyId,
        string $name,
        ?string $description,
        array $stages,
    ): DocumentWorkflowPreset {
        $this->validator->validateStages($companyId, $stages);

        return DB::transaction(function () use ($actor, $companyId, $name, $description, $stages): DocumentWorkflowPreset {
            $preset = DocumentWorkflowPreset::query()->create([
                'company_id' => $companyId,
                'name' => $name,
                'description' => $description,
                'status' => DocumentWorkflowPresetStatus::Active,
                'created_by' => $actor->id,
            ]);

            $this->syncStages($preset, $companyId, $stages);

            $this->activityLogger->log(
                description: 'Document workflow preset created',
                event: 'workflow_preset_created',
                preset: $preset,
                actor: $actor,
            );

            return $preset->load(['stages.targets']);
        });
    }

    /**
     * @param  list<array{action: string, completion_rule: string, targets: list<array{target_type: string, target_user_id?: int|null, target_role_id?: int|null}>}>  $stages
     */
    private function syncStages(DocumentWorkflowPreset $preset, int $companyId, array $stages): void
    {
        foreach ($stages as $index => $stageInput) {
            $stage = DocumentWorkflowPresetStage::query()->create([
                'company_id' => $companyId,
                'document_workflow_preset_id' => $preset->id,
                'sequence' => $index + 1,
                'action' => DocumentWorkflowAction::from($stageInput['action']),
                'completion_rule' => DocumentWorkflowCompletionRule::from($stageInput['completion_rule']),
            ]);

            foreach ($stageInput['targets'] as $targetInput) {
                DocumentWorkflowPresetTarget::query()->create([
                    'company_id' => $companyId,
                    'document_workflow_preset_stage_id' => $stage->id,
                    'target_type' => DocumentWorkflowTargetType::from($targetInput['target_type']),
                    'target_user_id' => $targetInput['target_user_id'] ?? null,
                    'target_role_id' => $targetInput['target_role_id'] ?? null,
                ]);
            }
        }
    }
}
