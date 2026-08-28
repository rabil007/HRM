<?php

namespace App\Support\Documents\Workflow;

use App\Models\DocumentWorkflowRequest;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

final class DocumentWorkflowActivityLogger
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function log(
        string $description,
        string $event,
        DocumentWorkflowRequest $request,
        User $actor,
        array $metadata = [],
    ): void {
        $subject = $request->documentInstance?->employeeDocument;

        $activity = activity($subject !== null ? null : 'document_workflows');

        if ($subject !== null) {
            $activity->performedOn($subject);
        }

        $activity
            ->causedBy($actor)
            ->event($event)
            ->withProperties(array_merge([
                'event' => $event,
                'request_id' => $request->id,
                'document_instance_id' => $request->document_instance_id,
                'document_instance_version_id' => $request->document_instance_version_id,
                'status' => $request->status->value,
                'actor_id' => $actor->id,
            ], $metadata))
            ->tap(function (Activity $activity) use ($request): void {
                $activity->company_id = $request->company_id;
            })
            ->log($description);
    }
}
