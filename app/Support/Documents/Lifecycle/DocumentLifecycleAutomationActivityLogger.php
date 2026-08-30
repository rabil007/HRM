<?php

namespace App\Support\Documents\Lifecycle;

use App\Models\DocumentLifecycleAutomation;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

final class DocumentLifecycleAutomationActivityLogger
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function log(
        string $description,
        string $event,
        DocumentLifecycleAutomation $lifecycle,
        ?User $actor = null,
        array $metadata = [],
    ): void {
        $companyId = (int) $lifecycle->company_id;

        $logger = activity('document_lifecycle')
            ->performedOn($lifecycle)
            ->event($event)
            ->tap(function (Activity $activity) use ($companyId): void {
                $activity->company_id = $companyId;
            })
            ->withProperties([
                'action' => $event,
                'document_lifecycle_automation_id' => $lifecycle->id,
                'document_instance_id' => $lifecycle->document_instance_id,
                'source_document_instance_version_id' => $lifecycle->source_document_instance_version_id,
                'status' => $lifecycle->status->value,
                'stage' => $lifecycle->stage?->value,
                'blocked_code' => $lifecycle->blocked_code,
                'document_workflow_request_id' => $lifecycle->document_workflow_request_id,
                'document_signing_flow_id' => $lifecycle->document_signing_flow_id,
                ...$metadata,
            ]);

        if ($actor !== null) {
            $logger->causedBy($actor);
        }

        $logger->log($description);
    }
}
