<?php

namespace App\Support\Documents\Workflow;

use App\Enums\DocumentWorkflowPresetStatus;
use App\Models\DocumentWorkflowPreset;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-type ResolvedPresetStage array{
 *     action: string,
 *     completion_rule: string,
 *     assignee_user_ids: list<int>
 * }
 * @phpstan-type ResolvedPresetResult array{
 *     stages: list<ResolvedPresetStage>,
 *     preset_id: int,
 *     preset_name: string,
 *     routing_snapshot: list<array<string, mixed>>
 * }
 */
final class ResolveDocumentWorkflowPreset
{
    public function __construct(
        private readonly DocumentWorkflowTargetResolver $targetResolver = new DocumentWorkflowTargetResolver,
    ) {}

    /**
     * @return ResolvedPresetResult
     */
    public function handle(
        DocumentWorkflowPreset $preset,
        Employee $subjectEmployee,
        User $requester,
        int $companyId,
    ): array {
        abort_unless((int) $preset->company_id === $companyId, 404);
        abort_unless($preset->status === DocumentWorkflowPresetStatus::Active, 422);

        if ((int) $subjectEmployee->company_id !== $companyId) {
            throw ValidationException::withMessages([
                'workflow_preset_id' => ['The document subject employee does not belong to this company.'],
            ]);
        }

        $preset->loadMissing([
            'stages.targets.targetUser:id,name',
            'stages.targets.targetRole:id,name,company_id',
        ]);

        if ($preset->stages->isEmpty()) {
            throw ValidationException::withMessages([
                'workflow_preset_id' => ['The selected workflow preset has no stages configured.'],
            ]);
        }

        $stages = [];
        $routingSnapshot = [];

        foreach ($preset->stages->sortBy('sequence')->values() as $stage) {
            $assigneeUserIds = $this->targetResolver->resolveUserIdsForStage(
                targets: $stage->targets->all(),
                subjectEmployee: $subjectEmployee,
                companyId: $companyId,
                stageAction: $stage->action->value,
                requesterUserId: (int) $requester->id,
            );

            if ($assigneeUserIds === []) {
                throw ValidationException::withMessages([
                    'workflow_preset_id' => [sprintf('Stage %d could not be resolved to any eligible assignees.', $stage->sequence)],
                ]);
            }

            $stages[] = [
                'action' => $stage->action->value,
                'completion_rule' => $stage->completion_rule->value,
                'assignee_user_ids' => $assigneeUserIds,
            ];

            $routingSnapshot[] = [
                'sequence' => $stage->sequence,
                'action' => $stage->action->value,
                'completion_rule' => $stage->completion_rule->value,
                'targets' => $stage->targets
                    ->map(fn ($target): array => DocumentWorkflowPresetTargetAttributes::routingSnapshotEntry($target))
                    ->values()
                    ->all(),
                'resolved_assignee_user_ids' => $assigneeUserIds,
            ];
        }

        return [
            'stages' => $stages,
            'preset_id' => (int) $preset->id,
            'preset_name' => (string) $preset->name,
            'routing_snapshot' => $routingSnapshot,
        ];
    }
}
