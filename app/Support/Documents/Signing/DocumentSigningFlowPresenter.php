<?php

namespace App\Support\Documents\Signing;

use App\Enums\DocumentRecipientRequestStatus;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentSigningFlow;

final class DocumentSigningFlowPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function forDocumentShow(DocumentSigningFlow $flow): array
    {
        $flow->loadMissing([
            'startedByUser:id,name',
            'recipientRequests.sourceVersion:id,version',
            'recipientRequests.resultVersion:id,version',
        ]);

        $requestsBySequence = $flow->recipientRequests->keyBy(
            fn (DocumentRecipientRequest $request): int => (int) $request->signing_step_sequence,
        );

        $steps = collect($flow->routing_definition_snapshot['steps'] ?? [])
            ->sortBy('sequence')
            ->values()
            ->map(function (array $step) use ($flow, $requestsBySequence): array {
                $sequence = (int) $step['sequence'];
                /** @var DocumentRecipientRequest|null $request */
                $request = $requestsBySequence->get($sequence);

                $status = 'pending';
                if ($request !== null) {
                    $status = match ($request->status) {
                        DocumentRecipientRequestStatus::AwaitingAction => 'awaiting',
                        DocumentRecipientRequestStatus::Completed => 'completed',
                        DocumentRecipientRequestStatus::Cancelled => 'cancelled',
                        DocumentRecipientRequestStatus::Superseded => 'superseded',
                        DocumentRecipientRequestStatus::Expired => 'expired',
                        default => $request->status->value,
                    };
                } elseif ((int) $flow->current_step_sequence > $sequence) {
                    $status = 'skipped';
                }

                return [
                    'sequence' => $sequence,
                    'recipient_role' => $step['recipient_role'] ?? null,
                    'recipient_name' => $step['recipient_name'] ?? null,
                    'status' => $status,
                    'is_current' => (int) $flow->current_step_sequence === $sequence,
                    'request_id' => $request?->id,
                    'source_version' => $request?->sourceVersion?->version,
                    'result_version' => $request?->resultVersion?->version,
                    'respond_url' => $request !== null
                        && $request->status === DocumentRecipientRequestStatus::AwaitingAction
                        && $request->isInternalSigner()
                            ? route('organization.documents.recipient-requests.respond', [
                                'recipientRequest' => $request->id,
                            ])
                            : null,
                ];
            })
            ->all();

        return [
            'id' => $flow->id,
            'preset_name' => $flow->preset_name_snapshot,
            'status' => $flow->status->value,
            'status_label' => $flow->status->label(),
            'current_step_sequence' => $flow->current_step_sequence,
            'started_by' => $flow->startedByUser !== null ? [
                'id' => $flow->startedByUser->id,
                'name' => $flow->startedByUser->name,
            ] : null,
            'started_at' => $flow->started_at?->toIso8601String(),
            'blocked_at' => $flow->blocked_at?->toIso8601String(),
            'blocked_reason' => $flow->blocked_reason,
            'completed_at' => $flow->completed_at?->toIso8601String(),
            'cancelled_at' => $flow->cancelled_at?->toIso8601String(),
            'steps' => $steps,
            'can_retry' => $flow->status->value === 'blocked',
            'can_cancel' => $flow->status->isOpen(),
        ];
    }
}
