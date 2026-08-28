<?php

namespace App\Support\Documents\Workflow;

use App\Enums\DocumentWorkflowTargetType;
use App\Models\DocumentWorkflowPresetTarget;

final class DocumentWorkflowPresetTargetAttributes
{
    /**
     * @param  array{target_type: string, target_user_id?: int|null, target_role_id?: int|null}  $targetInput
     * @return array{target_user_id: int|null, target_role_id: int|null}
     */
    public static function forPersistence(string $targetType, array $targetInput): array
    {
        $userId = isset($targetInput['target_user_id']) ? (int) $targetInput['target_user_id'] : null;
        $roleId = isset($targetInput['target_role_id']) ? (int) $targetInput['target_role_id'] : null;

        return match ($targetType) {
            DocumentWorkflowTargetType::SpecificUser->value => [
                'target_user_id' => $userId,
                'target_role_id' => null,
            ],
            DocumentWorkflowTargetType::CompanyRole->value => [
                'target_user_id' => null,
                'target_role_id' => $roleId,
            ],
            default => [
                'target_user_id' => null,
                'target_role_id' => null,
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function routingSnapshotEntry(DocumentWorkflowPresetTarget $target): array
    {
        $entry = [
            'target_type' => $target->target_type->value,
            'label' => DocumentWorkflowPresetPresenter::targetLabel($target),
        ];

        return match ($target->target_type) {
            DocumentWorkflowTargetType::SpecificUser => array_merge($entry, [
                'target_user_id' => $target->target_user_id,
                'target_user_name' => $target->targetUser?->name,
            ]),
            DocumentWorkflowTargetType::CompanyRole => array_merge($entry, [
                'target_role_id' => $target->target_role_id,
                'target_role_name' => $target->targetRole?->name,
            ]),
            default => $entry,
        };
    }
}
