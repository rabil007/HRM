<?php

namespace App\Support\Documents\Workflow;

use App\Models\DocumentWorkflowPreset;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

final class DocumentWorkflowPresetActivityLogger
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function log(
        string $description,
        string $event,
        DocumentWorkflowPreset $preset,
        User $actor,
        array $metadata = [],
    ): void {
        activity('document_workflow_presets')
            ->causedBy($actor)
            ->event($event)
            ->withProperties(array_merge([
                'event' => $event,
                'preset_id' => $preset->id,
                'preset_name' => $preset->name,
                'status' => $preset->status->value,
                'actor_id' => $actor->id,
            ], $metadata))
            ->tap(function (Activity $activity) use ($preset): void {
                $activity->company_id = $preset->company_id;
            })
            ->log($description);
    }
}
