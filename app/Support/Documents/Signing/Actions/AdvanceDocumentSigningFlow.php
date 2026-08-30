<?php

namespace App\Support\Documents\Signing\Actions;

use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentRecipientRole;
use App\Enums\DocumentSigningFlowStatus;
use App\Models\DocumentInstance;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentSigningFlow;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Support\Documents\Lifecycle\Actions\SyncDocumentLifecycleFromSigningFlow;
use App\Support\Documents\Signing\DocumentSignatureSlot;
use App\Support\Documents\Signing\DocumentSigningFlowActivityLogger;
use App\Support\Documents\Signing\DocumentSigningInternalSignerEligibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdvanceDocumentSigningFlow
{
    public function __construct(
        private CreateDocumentSigningFlowStepRequest $createFlowStepRequest,
        private DocumentSigningInternalSignerEligibility $signerEligibility,
        private DocumentSigningFlowActivityLogger $activityLogger,
    ) {}

    public function handle(
        DocumentSigningFlow $flow,
        DocumentRecipientRequest $completedRequest,
        ?User $actor = null,
    ): DocumentSigningFlow {
        return DB::transaction(function () use ($flow, $completedRequest, $actor): DocumentSigningFlow {
            /** @var DocumentSigningFlow $lockedFlow */
            $lockedFlow = DocumentSigningFlow::query()
                ->whereKey($flow->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedFlow->status !== DocumentSigningFlowStatus::Active
                && $lockedFlow->status !== DocumentSigningFlowStatus::Blocked) {
                return $lockedFlow;
            }

            $instance = DocumentInstance::query()
                ->whereKey($lockedFlow->document_instance_id)
                ->where('company_id', $lockedFlow->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            $completed = DocumentRecipientRequest::query()
                ->whereKey($completedRequest->id)
                ->where('company_id', $lockedFlow->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $completed->document_signing_flow_id !== (int) $lockedFlow->id) {
                return $lockedFlow;
            }

            if ((int) $completed->signing_step_sequence !== (int) $lockedFlow->current_step_sequence) {
                return $lockedFlow;
            }

            if ($completed->status !== DocumentRecipientRequestStatus::Completed) {
                return $lockedFlow;
            }

            if ((int) $completed->result_document_instance_version_id !== (int) $instance->current_version_id) {
                return app(BlockDocumentSigningFlow::class)->handle(
                    $lockedFlow,
                    (int) $lockedFlow->company_id,
                    'The document changed while this signing step was pending.',
                    $actor,
                );
            }

            $steps = collect($lockedFlow->routing_definition_snapshot['steps'] ?? [])
                ->sortBy('sequence')
                ->values();

            $nextStep = $steps->first(
                fn (array $step): bool => (int) $step['sequence'] === ((int) $lockedFlow->current_step_sequence + 1),
            );

            if ($nextStep === null) {
                $lockedFlow->update([
                    'status' => DocumentSigningFlowStatus::Completed,
                    'completed_at' => now(),
                    'blocked_at' => null,
                    'blocked_reason' => null,
                ]);

                $this->activityLogger->log(
                    description: 'Document signing flow completed',
                    event: 'signing_flow_completed',
                    flow: $lockedFlow->fresh(),
                    actor: $actor,
                );

                $flowId = (int) $lockedFlow->id;
                $companyId = (int) $lockedFlow->company_id;
                DB::afterCommit(function () use ($flowId, $companyId): void {
                    try {
                        app(SyncDocumentLifecycleFromSigningFlow::class)->handle($flowId, $companyId);
                    } catch (\Throwable $exception) {
                        report($exception);
                    }
                });

                return $lockedFlow->fresh();
            }

            $nextSequence = (int) $nextStep['sequence'];

            try {
                $normalizedNext = $this->normalizeSnapshotStep($nextStep, $nextSequence);
            } catch (ValidationException $exception) {
                $message = collect($exception->errors())->flatten()->first();

                return app(BlockDocumentSigningFlow::class)->handle(
                    $lockedFlow,
                    (int) $lockedFlow->company_id,
                    is_string($message) && $message !== ''
                        ? $message
                        : 'This signing flow contains an unsupported next step.',
                    $actor,
                );
            }

            $existingNext = DocumentRecipientRequest::query()
                ->forCompany((int) $lockedFlow->company_id)
                ->where('document_signing_flow_id', $lockedFlow->id)
                ->where('signing_step_sequence', $nextSequence)
                ->whereIn('status', [
                    DocumentRecipientRequestStatus::AwaitingAction,
                    DocumentRecipientRequestStatus::Completed,
                ])
                ->lockForUpdate()
                ->first();

            if ($existingNext !== null) {
                $lockedFlow->update([
                    'status' => DocumentSigningFlowStatus::Active,
                    'current_step_sequence' => $nextSequence,
                    'blocked_at' => null,
                    'blocked_reason' => null,
                ]);

                return $lockedFlow->fresh();
            }

            $document = EmployeeDocument::query()
                ->whereKey($instance->employee_document_id)
                ->where('company_id', $lockedFlow->company_id)
                ->firstOrFail();

            $requester = User::query()->find($lockedFlow->started_by) ?? $actor;

            if (! $requester instanceof User) {
                return app(BlockDocumentSigningFlow::class)->handle(
                    $lockedFlow,
                    (int) $lockedFlow->company_id,
                    'Unable to continue this signing flow because the original requester is unavailable.',
                    $actor,
                );
            }

            $recipientUserId = (int) ($normalizedNext['recipient_user_id'] ?? 0);
            $recipientUser = User::query()->find($recipientUserId);

            if (! $recipientUser instanceof User || ! $this->signerEligibility->isActionable($recipientUser, (int) $lockedFlow->company_id)) {
                $role = DocumentRecipientRole::tryFrom((string) $normalizedNext['recipient_role']);
                $reason = $role === DocumentRecipientRole::Manager
                    ? 'Assigned department manager is no longer eligible to sign.'
                    : 'Assigned company signatory is no longer eligible to sign.';

                return app(BlockDocumentSigningFlow::class)->handle(
                    $lockedFlow,
                    (int) $lockedFlow->company_id,
                    $reason,
                    $actor,
                );
            }

            try {
                $this->createFlowStepRequest->handle(
                    $document,
                    $requester,
                    (int) $lockedFlow->company_id,
                    (int) $lockedFlow->id,
                    $normalizedNext,
                    $completed,
                );
            } catch (ValidationException $exception) {
                $message = collect($exception->errors())->flatten()->first();

                return app(BlockDocumentSigningFlow::class)->handle(
                    $lockedFlow,
                    (int) $lockedFlow->company_id,
                    is_string($message) && $message !== ''
                        ? $message
                        : 'The next signing step could not be created.',
                    $actor,
                );
            }

            $lockedFlow->update([
                'status' => DocumentSigningFlowStatus::Active,
                'current_step_sequence' => $nextSequence,
                'blocked_at' => null,
                'blocked_reason' => null,
            ]);

            $this->activityLogger->log(
                description: 'Document signing flow advanced',
                event: 'signing_flow_advanced',
                flow: $lockedFlow->fresh(),
                actor: $actor,
                metadata: [
                    'step_sequence' => $nextSequence,
                    'recipient_role' => $normalizedNext['recipient_role'],
                    'recipient_user_id' => $recipientUserId,
                    'signature_slot_key' => $normalizedNext['signature_slot_key'],
                    'signing_step_label' => $normalizedNext['step_label'] ?? null,
                ],
            );

            return $lockedFlow->fresh();
        });
    }

    /**
     * Normalize schema v1 and v2 snapshot steps for activation.
     *
     * @param  array<string, mixed>  $step
     * @return array{
     *     sequence: int,
     *     recipient_role: string,
     *     step_label: string,
     *     signature_slot_key: string,
     *     recipient_user_id: int,
     *     recipient_name: string
     * }
     */
    private function normalizeSnapshotStep(array $step, int $sequence): array
    {
        $role = DocumentRecipientRole::tryFrom((string) ($step['recipient_role'] ?? ''));

        if ($role === null || ! $role->isInternalSigner()) {
            throw ValidationException::withMessages([
                'flow' => 'This signing flow contains an unsupported next step.',
            ]);
        }

        $occurrence = match ($role) {
            DocumentRecipientRole::Manager => (int) ($step['management_chain_position']
                ?? DocumentSignatureSlot::occurrenceFor(
                    (string) ($step['signature_slot_key'] ?? DocumentSignatureSlot::defaultForRole($role)),
                )),
            DocumentRecipientRole::CompanySignatory => isset($step['signature_slot_key'])
                ? DocumentSignatureSlot::occurrenceFor((string) $step['signature_slot_key'])
                : 1,
            default => 1,
        };

        $slotKey = (string) ($step['signature_slot_key']
            ?? DocumentSignatureSlot::forRoleOccurrence($role, max(1, $occurrence)));

        $label = trim((string) ($step['step_label'] ?? ''));

        if ($label === '') {
            $label = DocumentSignatureSlot::defaultLabel($role, DocumentSignatureSlot::occurrenceFor($slotKey));
        }

        return [
            'sequence' => $sequence,
            'recipient_role' => $role->value,
            'step_label' => $label,
            'signature_slot_key' => $slotKey,
            'recipient_user_id' => (int) ($step['recipient_user_id'] ?? 0),
            'recipient_name' => (string) ($step['recipient_name'] ?? ''),
        ];
    }

    /**
     * @deprecated Prefer BlockDocumentSigningFlow; kept as a thin wrapper for call sites.
     */
    public function markBlocked(
        DocumentSigningFlow $flow,
        string $reason,
        ?User $actor = null,
    ): DocumentSigningFlow {
        return app(BlockDocumentSigningFlow::class)->handle(
            $flow,
            (int) $flow->company_id,
            $reason,
            $actor,
        );
    }
}
