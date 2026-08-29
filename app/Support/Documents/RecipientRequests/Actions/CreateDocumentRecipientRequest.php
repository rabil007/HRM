<?php

namespace App\Support\Documents\RecipientRequests\Actions;

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
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestWorkflowGate;
use App\Support\Documents\RecipientRequests\ResolveDocumentSignaturePlacement;
use App\Support\Documents\Signing\DocumentSigningFlowOpenGuard;
use App\Support\EmployeeDocuments\DocumentAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateDocumentRecipientRequest
{
    public function __construct(
        private DocumentRecipientRequestWorkflowGate $workflowGate,
        private ResolveDocumentSignaturePlacement $resolvePlacement,
        private DocumentRecipientRequestEventRecorder $eventRecorder,
    ) {}

    /**
     * @return array{request: DocumentRecipientRequest, raw_token: string}
     */
    public function handle(
        EmployeeDocument $document,
        DocumentRecipientAction $action,
        User $requester,
        int $companyId,
        ?int $signingFlowId = null,
        ?int $signingStepSequence = null,
        bool $skipOpenFlowGuard = false,
        ?string $signatureSlotKey = null,
        ?string $signingStepLabelSnapshot = null,
    ): array {
        DocumentAccess::assertDocumentInCompany($document, $companyId);

        $document->loadMissing(['employee']);

        $employee = $document->employee;

        if (! $employee instanceof Employee || (int) $employee->company_id !== $companyId) {
            abort(404);
        }

        $rawToken = DocumentRecipientRequestToken::generate();

        return DB::transaction(function () use (
            $document,
            $employee,
            $action,
            $requester,
            $companyId,
            $rawToken,
            $signingFlowId,
            $signingStepSequence,
            $skipOpenFlowGuard,
            $signatureSlotKey,
            $signingStepLabelSnapshot,
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

            if ((int) $instance->employee_id !== (int) $document->employee_id) {
                abort(404);
            }

            if (! $skipOpenFlowGuard) {
                app(DocumentSigningFlowOpenGuard::class)->assertNoOpenFlow($instance, $companyId);
            }

            $sourceVersion = DocumentInstanceVersion::query()
                ->whereKey($instance->current_version_id)
                ->where('company_id', $companyId)
                ->firstOrFail();

            $this->workflowGate->assertCanCreateForVersion($instance, $companyId);

            if ($action === DocumentRecipientAction::Sign) {
                $this->resolvePlacement->forInstanceVersion($instance, $sourceVersion);
            }

            $workflowRequestId = $this->workflowGate->latestApprovedWorkflowId(
                $instance,
                (int) $sourceVersion->id,
                $companyId,
            );

            $duplicate = DocumentRecipientRequest::query()
                ->forCompany($companyId)
                ->where('document_instance_id', $instance->id)
                ->where('source_document_instance_version_id', $sourceVersion->id)
                ->where('employee_id', $employee->id)
                ->where('action', $action)
                ->where('status', DocumentRecipientRequestStatus::AwaitingAction)
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'action' => 'An active request of this type already exists for this document version.',
                ]);
            }

            $request = DocumentRecipientRequest::query()->create([
                'company_id' => $companyId,
                'document_instance_id' => $instance->id,
                'source_document_instance_version_id' => $sourceVersion->id,
                'document_workflow_request_id' => $workflowRequestId,
                'document_signing_flow_id' => $signingFlowId,
                'signing_step_sequence' => $signingStepSequence,
                'signature_slot_key' => $signatureSlotKey,
                'signing_step_label_snapshot' => $signingStepLabelSnapshot,
                'action' => $action,
                'recipient_type' => DocumentRecipientType::SubjectEmployee,
                'recipient_role' => DocumentRecipientRole::Subject,
                'employee_id' => $employee->id,
                'recipient_user_id' => $employee->user_id,
                'recipient_name_snapshot' => (string) $employee->name,
                'status' => DocumentRecipientRequestStatus::AwaitingAction,
                'token_hash' => DocumentRecipientRequestToken::hash($rawToken),
                'expires_at' => now()->addDays(DocumentRecipientRequest::EXPIRY_DAYS),
                'reminder_policy_snapshot' => app(DocumentRecipientAutomationPolicy::class)
                    ->snapshotForCompany($companyId),
                'requested_by' => $requester->id,
                'requested_at' => now(),
                'source_checksum_sha256' => (string) $sourceVersion->checksum,
            ]);

            $this->eventRecorder->record(
                $request,
                DocumentRecipientRequestEventType::RequestCreated,
                $requester,
                metadata: [
                    'action' => $action->value,
                    'document_instance_id' => $instance->id,
                    'source_document_instance_version_id' => $sourceVersion->id,
                    'document_signing_flow_id' => $signingFlowId,
                    'signing_step_sequence' => $signingStepSequence,
                    'signature_slot_key' => $signatureSlotKey,
                    'signing_step_label' => $signingStepLabelSnapshot,
                ],
            );

            activity()
                ->causedBy($requester)
                ->performedOn($request)
                ->tap(fn ($activity) => $activity->company_id = $companyId)
                ->withProperties([
                    'action' => 'recipient_request_created',
                    'document_recipient_request_id' => $request->id,
                    'document_instance_id' => $instance->id,
                    'recipient_action' => $action->value,
                    'document_signing_flow_id' => $signingFlowId,
                    'signing_step_sequence' => $signingStepSequence,
                    'signature_slot_key' => $signatureSlotKey,
                    'signing_step_label' => $signingStepLabelSnapshot,
                    'status' => $request->status->value,
                ])
                ->log('Recipient request created');

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
                'raw_token' => $rawToken,
            ];
        });
    }
}
