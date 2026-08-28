<?php

namespace App\Support\Documents\Workflow;

use App\Enums\DocumentWorkflowTargetType;
use App\Models\DocumentWorkflowPreset;
use App\Models\DocumentWorkflowPresetTarget;

final class DocumentWorkflowPresetPresenter
{
    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     description: string|null,
     *     status: string,
     *     status_label: string,
     *     stage_count: int,
     *     routing_summary: string,
     *     updated_at: string|null,
     *     stages: list<array<string, mixed>>,
     * }
     */
    public function detail(DocumentWorkflowPreset $preset): array
    {
        $preset->loadMissing([
            'stages.targets.targetUser:id,name',
            'stages.targets.targetRole:id,name',
        ]);

        $stages = $preset->stages->sortBy('sequence')->values()->map(fn ($stage): array => [
            'sequence' => $stage->sequence,
            'action' => $stage->action->value,
            'action_label' => $stage->action->label(),
            'completion_rule' => $stage->completion_rule->value,
            'completion_rule_label' => strtoupper($stage->completion_rule->value),
            'targets' => $stage->targets->map(fn ($target): array => [
                'target_type' => $target->target_type->value,
                'target_type_label' => $target->target_type->label(),
                'target_user_id' => $target->target_user_id,
                'target_role_id' => $target->target_role_id,
                'label' => self::targetLabel($target),
            ])->values()->all(),
        ])->all();

        return [
            'id' => $preset->id,
            'name' => (string) $preset->name,
            'description' => $preset->description,
            'status' => $preset->status->value,
            'status_label' => $preset->status->label(),
            'stage_count' => count($stages),
            'routing_summary' => self::routingSummary($stages),
            'updated_at' => $preset->updated_at?->toIso8601String(),
            'stages' => $stages,
        ];
    }

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     description: string|null,
     *     status: string,
     *     status_label: string,
     *     stage_count: int,
     *     routing_summary: string,
     *     updated_at: string|null,
     * }
     */
    public function listItem(DocumentWorkflowPreset $preset): array
    {
        $preset->loadCount('stages');
        $preset->loadMissing([
            'stages.targets.targetUser:id,name',
            'stages.targets.targetRole:id,name',
        ]);

        $stages = $preset->stages->sortBy('sequence')->values()->map(fn ($stage): array => [
            'sequence' => $stage->sequence,
            'action' => $stage->action->value,
            'action_label' => $stage->action->label(),
            'completion_rule' => $stage->completion_rule->value,
            'completion_rule_label' => strtoupper($stage->completion_rule->value),
            'targets' => $stage->targets->map(fn ($target): array => [
                'target_type' => $target->target_type->value,
                'target_type_label' => $target->target_type->label(),
                'target_user_id' => $target->target_user_id,
                'target_role_id' => $target->target_role_id,
                'label' => self::targetLabel($target),
            ])->values()->all(),
        ])->all();

        return [
            'id' => $preset->id,
            'name' => (string) $preset->name,
            'description' => $preset->description,
            'status' => $preset->status->value,
            'status_label' => $preset->status->label(),
            'stage_count' => (int) $preset->stages_count,
            'routing_summary' => self::routingSummary($stages),
            'updated_at' => $preset->updated_at?->toIso8601String(),
            'stages' => $stages,
        ];
    }

    public static function targetLabel(DocumentWorkflowPresetTarget $target): string
    {
        return match ($target->target_type) {
            DocumentWorkflowTargetType::SpecificUser => (string) ($target->targetUser?->name ?? 'Specific user'),
            DocumentWorkflowTargetType::CompanyRole => (string) ($target->targetRole?->name ?? 'Company role').' role',
            DocumentWorkflowTargetType::DepartmentManager => 'Department manager',
            DocumentWorkflowTargetType::ParentManager => 'Parent manager',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $stages
     */
    public static function routingSummary(array $stages): string
    {
        $parts = [];

        foreach ($stages as $stage) {
            $targetLabels = collect($stage['targets'] ?? [])
                ->map(fn ($target): string => is_array($target) ? (string) ($target['label'] ?? '') : '')
                ->filter()
                ->values()
                ->all();

            $parts[] = sprintf(
                'Stage %d · %s · %s · %s',
                $stage['sequence'] ?? '?',
                $stage['action_label'] ?? ucfirst((string) ($stage['action'] ?? '')),
                $stage['completion_rule_label'] ?? strtoupper((string) ($stage['completion_rule'] ?? '')),
                implode(', ', $targetLabels),
            );
        }

        return implode(' → ', $parts);
    }
}
