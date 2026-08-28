<?php

namespace App\Support\Documents\Workflow;

use App\Models\User;

final class DocumentWorkflowPresetPagePermissions
{
    /**
     * @return array{
     *     view: bool,
     *     create: bool,
     *     update: bool,
     *     delete: bool,
     * }
     */
    public static function for(?User $user): array
    {
        return [
            'view' => $user?->can('documents.workflow-presets.view') ?? false,
            'create' => $user?->can('documents.workflow-presets.create') ?? false,
            'update' => $user?->can('documents.workflow-presets.update') ?? false,
            'delete' => $user?->can('documents.workflow-presets.delete') ?? false,
        ];
    }
}
