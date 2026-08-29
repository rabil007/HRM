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
use App\Support\Documents\RecipientRequests\Actions\CreateDocumentCompanyCountersignRequest;
use App\Support\Documents\RecipientRequests\Actions\CreateDocumentManagerCountersignRequest;
use App\Support\Documents\Signing\DocumentSigningFlowActivityLogger;
use App\Support\Documents\Signing\DocumentSigningInternalSignerEligibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdvanceDocumentSigningFlow
{
    public function __construct(
        private CreateDocumentManagerCountersignRequest $createManagerRequest,
        private CreateDocumentCompanyCountersignRequest $createCompanyRequest,
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

            // Retry path may start from Blocked; treat as Active for advancement attempt.
            if ($lockedFlow->status === DocumentSigningFlowStatus::Blocked) {
                // Keep blocked until next request succeeds.
            } elseif ($lockedFlow->status !== DocumentSigningFlowStatus::Active) {
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
                // Already advanced past this step.
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

                return $lockedFlow->fresh();
            }

            $nextSequence = (int) $nextStep['sequence'];

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

            $role = DocumentRecipientRole::tryFrom((string) ($nextStep['recipient_role'] ?? ''));
            $recipientUserId = isset($nextStep['recipient_user_id']) ? (int) $nextStep['recipient_user_id'] : null;

            try {
                if ($role === DocumentRecipientRole::Manager) {
                    if ($recipientUserId === null) {
                        return app(BlockDocumentSigningFlow::class)->handle(
                            $lockedFlow,
                            (int) $lockedFlow->company_id,
                            'Assigned department manager is no longer eligible to sign.',
                            $actor,
                        );
                    }

                    $recipientUser = User::query()->find($recipientUserId);

                    if (! $recipientUser instanceof User || ! $this->signerEligibility->isActionable($recipientUser, (int) $lockedFlow->company_id)) {
                        return app(BlockDocumentSigningFlow::class)->handle(
                            $lockedFlow,
                            (int) $lockedFlow->company_id,
                            'Assigned department manager is no longer eligible to sign.',
                            $actor,
                        );
                    }

                    $this->createManagerRequest->handle(
                        $document,
                        $requester,
                        (int) $lockedFlow->company_id,
                        assignedRecipient: $recipientUser,
                        signingFlowId: (int) $lockedFlow->id,
                        signingStepSequence: $nextSequence,
                        skipOpenFlowGuard: true,
                    );
                } elseif ($role === DocumentRecipientRole::CompanySignatory) {
                    if ($recipientUserId === null) {
                        return app(BlockDocumentSigningFlow::class)->handle(
                            $lockedFlow,
                            (int) $lockedFlow->company_id,
                            'Assigned company signatory is no longer eligible to sign.',
                            $actor,
                        );
                    }

                    $recipientUser = User::query()->find($recipientUserId);

                    if (! $recipientUser instanceof User || ! $this->signerEligibility->isActionable($recipientUser, (int) $lockedFlow->company_id)) {
                        return app(BlockDocumentSigningFlow::class)->handle(
                            $lockedFlow,
                            (int) $lockedFlow->company_id,
                            'Assigned company signatory is no longer eligible to sign.',
                            $actor,
                        );
                    }

                    $this->createCompanyRequest->handle(
                        $document,
                        $recipientUser,
                        $requester,
                        (int) $lockedFlow->company_id,
                        signingFlowId: (int) $lockedFlow->id,
                        signingStepSequence: $nextSequence,
                        skipOpenFlowGuard: true,
                    );
                } else {
                    return app(BlockDocumentSigningFlow::class)->handle(
                        $lockedFlow,
                        (int) $lockedFlow->company_id,
                        'This signing flow contains an unsupported next step.',
                        $actor,
                    );
                }
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
                    'recipient_role' => $role?->value,
                    'recipient_user_id' => $recipientUserId,
                ],
            );

            return $lockedFlow->fresh();
        });
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
