<?php

namespace App\Support\Documents\Signing;

use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentRecipientRole;
use App\Enums\DocumentSigningFlowStatus;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentSigningFlow;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestPresenter;

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
            'recipientRequests.deliveries',
        ]);

        $requestPresenter = app(DocumentRecipientRequestPresenter::class);

        $requestsBySequence = $flow->recipientRequests->keyBy(
            fn (DocumentRecipientRequest $request): int => (int) $request->signing_step_sequence,
        );

        $snapshotSteps = collect($flow->routing_definition_snapshot['steps'] ?? [])
            ->sortBy('sequence')
            ->values();
        $totalSteps = $snapshotSteps->count();

        $steps = $snapshotSteps
            ->map(function (array $step) use ($flow, $requestsBySequence, $totalSteps, $requestPresenter): array {
                $sequence = (int) $step['sequence'];
                /** @var DocumentRecipientRequest|null $request */
                $request = $requestsBySequence->get($sequence);
                $role = DocumentRecipientRole::tryFrom((string) ($step['recipient_role'] ?? ''));
                $occurrence = $this->occurrenceFromStep($step, $role);
                $slotKey = (string) ($step['signature_slot_key']
                    ?? ($role !== null ? DocumentSignatureSlot::forRoleOccurrence($role, $occurrence) : ''));
                $stepLabel = trim((string) ($step['step_label'] ?? ''));

                if ($stepLabel === '' && $role !== null) {
                    $stepLabel = DocumentSignatureSlot::defaultLabel($role, $occurrence);
                }

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
                    'total_steps' => $totalSteps,
                    'recipient_role' => $step['recipient_role'] ?? null,
                    'recipient_role_label' => match ($step['recipient_role'] ?? null) {
                        'subject' => 'Subject employee',
                        'manager' => 'Department manager',
                        'company_signatory' => 'Company signatory',
                        default => $step['recipient_role'] ?? null,
                    },
                    'step_label' => $stepLabel,
                    'signature_slot_key' => $slotKey !== '' ? $slotKey : null,
                    'recipient_name' => $request?->recipient_name_snapshot
                        ?? ($step['recipient_name'] ?? null),
                    'status' => $status,
                    'is_current' => (int) $flow->current_step_sequence === $sequence,
                    'request_id' => $request?->id,
                    'source_version' => $request?->sourceVersion?->version,
                    'result_version' => $request?->resultVersion?->version,
                    'email_delivery' => $request !== null
                        ? $requestPresenter->emailDeliverySummary($request)
                        : null,
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

        $canRetry = false;

        if ($flow->status === DocumentSigningFlowStatus::Blocked) {
            $currentStepRequest = $requestsBySequence->get((int) $flow->current_step_sequence);

            $canRetry = $currentStepRequest instanceof DocumentRecipientRequest
                && $currentStepRequest->status === DocumentRecipientRequestStatus::Completed;
        }

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
            'can_retry' => $canRetry,
            'can_cancel' => $flow->status->isOpen(),
        ];
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function occurrenceFromStep(array $step, ?DocumentRecipientRole $role): int
    {
        if ($role === null) {
            return 1;
        }

        if (isset($step['signature_slot_key']) && DocumentSignatureSlot::isValid((string) $step['signature_slot_key'])) {
            return DocumentSignatureSlot::occurrenceFor((string) $step['signature_slot_key']);
        }

        if ($role === DocumentRecipientRole::Manager && isset($step['management_chain_position'])) {
            return max(1, (int) $step['management_chain_position']);
        }

        return 1;
    }
}
