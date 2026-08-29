<?php

namespace App\Support\Documents\Signing;

use App\Models\DocumentSigningFlow;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

final class DocumentSigningFlowActivityLogger
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function log(
        string $description,
        string $event,
        DocumentSigningFlow $flow,
        ?User $actor = null,
        array $metadata = [],
    ): void {
        $logger = activity('document_signing_flows')
            ->event($event)
            ->withProperties(array_merge([
                'event' => $event,
                'signing_flow_id' => $flow->id,
                'document_instance_id' => $flow->document_instance_id,
                'preset_id' => $flow->document_signing_preset_id,
                'status' => $flow->status->value,
                'current_step_sequence' => $flow->current_step_sequence,
            ], $metadata))
            ->tap(function (Activity $activity) use ($flow): void {
                $activity->company_id = $flow->company_id;
            });

        if ($actor !== null) {
            $logger->causedBy($actor);
        }

        $logger->log($description);
    }
}
