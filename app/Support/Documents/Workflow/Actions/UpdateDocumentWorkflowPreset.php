<?php

namespace App\Support\Documents\Workflow\Actions;

use App\Enums\DocumentWorkflowAction;
use App\Enums\DocumentWorkflowCompletionRule;
use App\Enums\DocumentWorkflowTargetType;
use App\Models\DocumentWorkflowPreset;
use App\Models\DocumentWorkflowPresetStage;
use App\Models\DocumentWorkflowPresetTarget;
use App\Models\User;
use App\Support\Documents\Workflow\DocumentWorkflowPresetActivityLogger;
use App\Support\Documents\Workflow\DocumentWorkflowPresetValidator;
use Illuminate\Support\Facades\DB;

final class UpdateDocumentWorkflowPreset
{
    public function __construct(
        private readonly DocumentWorkflowPresetValidator $validator = new DocumentWorkflowPresetValidator,
        private readonly DocumentWorkflowPresetActivityLogger $activityLogger = new DocumentWorkflowPresetActivityLogger,
    ) {}

    /**
     * @param  list<array{action: string, completion_rule: string, targets: list<array{target_type: string, target_user_id?: int|null, target_role_id?: int|null}>}>  $stages
     */
    public function handle(
        DocumentWorkflowPreset $preset,
        User $actor,
        int $companyId,
        string $name,
        ?string $description,
        array $stages,
    ): DocumentWorkflowPreset {
        abort_unless((int) $preset->company_id === $companyId, 404);

        $this->validator->validateStages($companyId, $stages);

        return DB::transaction(function () use ($preset, $actor, $companyId, $name, $description, $stages): DocumentWorkflowPreset {
            $preset->update([
                'name' => $name,
                'description' => $description,
            ]);

            $preset->stages()->each(function (DocumentWorkflowPresetStage $stage): void {
                $stage->targets()->delete();
            });
            $preset->stages()->delete();

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

            $preset->refresh()->load(['stages.targets']);

            $this->activityLogger->log(
                description: 'Document workflow preset updated',
                event: 'workflow_preset_updated',
                preset: $preset,
                actor: $actor,
            );

            return $preset;
        });
    }
}
