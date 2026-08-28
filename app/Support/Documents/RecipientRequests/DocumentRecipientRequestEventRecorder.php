<?php

namespace App\Support\Documents\RecipientRequests;

use App\Enums\DocumentRecipientRequestEventType;
use App\Models\DocumentRecipientRequest;
use App\Models\User;

final class DocumentRecipientRequestEventRecorder
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function record(
        DocumentRecipientRequest $request,
        DocumentRecipientRequestEventType $event,
        ?User $actor = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?array $metadata = null,
    ): void {
        $request->events()->create([
            'company_id' => $request->company_id,
            'event' => $event,
            'actor_user_id' => $actor?->id,
            'occurred_at' => now(),
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'metadata' => $metadata,
        ]);
    }
}
