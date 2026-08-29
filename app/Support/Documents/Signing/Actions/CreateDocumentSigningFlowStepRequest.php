<?php

namespace App\Support\Documents\Signing\Actions;

use App\Enums\DocumentRecipientAction;
use App\Enums\DocumentRecipientRequestDeliveryPurpose;
use App\Enums\DocumentRecipientRequestEventType;
use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentRecipientRole;
use App\Enums\DocumentRecipientType;
use App\Models\DocumentInstance;
use App\Models\DocumentInstanceVersion;
use App\Models\DocumentRecipientRequest;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Support\Documents\RecipientRequests\Automation\DocumentRecipientAutomationPolicy;
use App\Support\Documents\RecipientRequests\Delivery\QueueDocumentRecipientRequestEmail;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestEventRecorder;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestToken;
use App\Support\Documents\RecipientRequests\ResolveDocumentSignaturePlacement;
use App\Support\EmployeeDocuments\DocumentAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates the next internal signing-flow request from an immutable routing snapshot.
 *
 * Flow-orchestration only — not a browser endpoint.
 */
final class CreateDocumentSigningFlowStepRequest
{
    public function __construct(
        private ResolveDocumentSignaturePlacement $resolvePlacement,
        private DocumentRecipientRequestEventRecorder $eventRecorder,
    ) {}

    /**
     * @param  array{
     *     sequence: int,
     *     recipient_role: string,
     *     step_label?: string|null,
     *     signature_slot_key: string,
     *     recipient_user_id: int,
     *     recipient_name: string
     * }  $snapshotStep
     * @return array{request: DocumentRecipientRequest}
     */
    public function handle(
        EmployeeDocument $document,
        User $requester,
        int $companyId,
        int $signingFlowId,
        array $snapshotStep,
        DocumentRecipientRequest $priorCompletedRequest,
    ): array {
        DocumentAccess::assertDocumentInCompany($document, $companyId);

        $document->loadMissing(['employee']);
        $employee = $document->employee;

        if (! $employee instanceof Employee || (int) $employee->company_id !== $companyId) {
            abort(404);
        }

        $role = DocumentRecipientRole::tryFrom((string) ($snapshotStep['recipient_role'] ?? ''));

        if ($role === null || ! $role->isInternalSigner()) {
            throw ValidationException::withMessages([
                'flow' => 'Unsupported signing flow step role.',
            ]);
        }

        $recipientUserId = (int) ($snapshotStep['recipient_user_id'] ?? 0);
        $slotKey = (string) ($snapshotStep['signature_slot_key'] ?? '');
        $sequence = (int) ($snapshotStep['sequence'] ?? 0);
        $stepLabel = trim((string) ($snapshotStep['step_label'] ?? ''));
        $recipientName = trim((string) ($snapshotStep['recipient_name'] ?? ''));

        if ($recipientUserId < 1 || $slotKey === '' || $sequence < 2 || $recipientName === '') {
            throw ValidationException::withMessages([
                'flow' => 'Signing flow step snapshot is incomplete.',
            ]);
        }

        $recipientUser = User::query()->find($recipientUserId);

        if (! $recipientUser instanceof User) {
            throw ValidationException::withMessages([
                'flow' => 'Snapshotted signer is no longer available.',
            ]);
        }

        return DB::transaction(function () use (
            $document,
            $employee,
            $requester,
            $companyId,
            $signingFlowId,
            $role,
            $recipientUser,
            $slotKey,
            $sequence,
            $stepLabel,
            $recipientName,
            $priorCompletedRequest,
        ): array {
            $instance = DocumentInstance::query()
                ->where('employee_document_id', $document->id)
                ->where('company_id', $companyId)
                ->where('employee_id', $document->employee_id)
                ->lockForUpdate()
                ->first();

            if (! $instance instanceof DocumentInstance) {
                throw ValidationException::withMessages([
                    'action' => 'Recipient requests require a generated document instance.',
                ]);
            }

            if ($instance->current_version_id === null) {
                throw ValidationException::withMessages([
                    'action' => 'This document has no current version.',
                ]);
            }

            $sourceVersion = DocumentInstanceVersion::query()
                ->whereKey($instance->current_version_id)
                ->where('company_id', $companyId)
                ->where('document_instance_id', $instance->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $priorCompletedRequest->result_document_instance_version_id !== (int) $sourceVersion->id) {
                throw ValidationException::withMessages([
                    'action' => 'The document changed while this signing step was pending.',
                ]);
            }

            $this->resolvePlacement->forInstanceVersionSlot(
                $instance,
                $sourceVersion,
                $role,
                $slotKey,
            );

            $duplicate = DocumentRecipientRequest::query()
                ->forCompany($companyId)
                ->where('document_signing_flow_id', $signingFlowId)
                ->where('signing_step_sequence', $sequence)
                ->lockForUpdate()
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'action' => 'This signing step request already exists.',
                ]);
            }

            $internalToken = DocumentRecipientRequestToken::generate();

            $request = DocumentRecipientRequest::query()->create([
                'company_id' => $companyId,
                'document_instance_id' => $instance->id,
                'source_document_instance_version_id' => $sourceVersion->id,
                'document_workflow_request_id' => $priorCompletedRequest->document_workflow_request_id,
                'document_signing_flow_id' => $signingFlowId,
                'signing_step_sequence' => $sequence,
                'signature_slot_key' => $slotKey,
                'signing_step_label_snapshot' => $stepLabel !== '' ? $stepLabel : null,
                'action' => DocumentRecipientAction::Sign,
                'recipient_type' => DocumentRecipientType::CompanyUser,
                'recipient_role' => $role,
                'employee_id' => $employee->id,
                'recipient_user_id' => $recipientUser->id,
                'recipient_name_snapshot' => $recipientName,
                'status' => DocumentRecipientRequestStatus::AwaitingAction,
                'token_hash' => DocumentRecipientRequestToken::hash($internalToken),
                'expires_at' => ($expiresAt = now()->addDays(DocumentRecipientRequest::EXPIRY_DAYS)),
                ...app(DocumentRecipientAutomationPolicy::class)->createSchedulingAttributes($companyId, $expiresAt),
                'requested_by' => $requester->id,
                'requested_at' => now(),
                'source_checksum_sha256' => (string) $sourceVersion->checksum,
            ]);

            $this->eventRecorder->record(
                $request,
                DocumentRecipientRequestEventType::RequestCreated,
                $requester,
                metadata: [
                    'action' => DocumentRecipientAction::Sign->value,
                    'recipient_type' => DocumentRecipientType::CompanyUser->value,
                    'recipient_role' => $role->value,
                    'document_instance_id' => $instance->id,
                    'source_document_instance_version_id' => $sourceVersion->id,
                    'recipient_user_id' => $recipientUser->id,
                    'document_signing_flow_id' => $signingFlowId,
                    'signing_step_sequence' => $sequence,
                    'signature_slot_key' => $slotKey,
                    'signing_step_label' => $stepLabel !== '' ? $stepLabel : null,
                ],
            );

            activity()
                ->causedBy($requester)
                ->performedOn($request)
                ->tap(fn ($activity) => $activity->company_id = $companyId)
                ->withProperties([
                    'action' => 'signing_flow_step_request_created',
                    'document_recipient_request_id' => $request->id,
                    'document_instance_id' => $instance->id,
                    'document_signing_flow_id' => $signingFlowId,
                    'signing_step_sequence' => $sequence,
                    'signature_slot_key' => $slotKey,
                    'signing_step_label' => $stepLabel !== '' ? $stepLabel : null,
                    'recipient_user_id' => $recipientUser->id,
                    'recipient_role' => $role->value,
                    'status' => $request->status->value,
                ])
                ->log('Signing flow step request created');

            try {
                app(QueueDocumentRecipientRequestEmail::class)
                    ->forRequest(
                        $request->fresh(),
                        DocumentRecipientRequestDeliveryPurpose::Initial,
                        $requester,
                    );
            } catch (\Throwable $exception) {
                report($exception);
            }

            return [
                'request' => $request->fresh(),
            ];
        });
    }
}
