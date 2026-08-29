<?php

namespace App\Support\Documents\RecipientRequests\Actions;

use App\Enums\DocumentRecipientAction;
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
use App\Support\Documents\RecipientRequests\DocumentRecipientManagerResolver;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestEventRecorder;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestToken;
use App\Support\Documents\RecipientRequests\DocumentRecipientSignatureChainGuard;
use App\Support\Documents\RecipientRequests\ResolveDocumentSignaturePlacement;
use App\Support\Documents\Signing\DocumentSigningFlowOpenGuard;
use App\Support\EmployeeDocuments\DocumentAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateDocumentManagerCountersignRequest
{
    public function __construct(
        private ResolveDocumentSignaturePlacement $resolvePlacement,
        private DocumentRecipientRequestEventRecorder $eventRecorder,
        private DocumentRecipientManagerResolver $managerResolver,
        private DocumentRecipientSignatureChainGuard $chainGuard,
    ) {}

    /**
     * @return array{request: DocumentRecipientRequest, respond_url: string, manager_name: string}
     */
    public function handle(
        EmployeeDocument $document,
        User $requester,
        int $companyId,
        ?User $assignedRecipient = null,
        ?int $signingFlowId = null,
        ?int $signingStepSequence = null,
        bool $skipOpenFlowGuard = false,
    ): array {
        DocumentAccess::assertDocumentInCompany($document, $companyId);

        $document->loadMissing(['employee']);

        $employee = $document->employee;

        if (! $employee instanceof Employee || (int) $employee->company_id !== $companyId) {
            abort(404);
        }

        $recipientUser = $assignedRecipient ?? $this->managerResolver->resolveForEmployee($employee, $companyId)['user'];

        return DB::transaction(function () use (
            $document,
            $employee,
            $recipientUser,
            $requester,
            $companyId,
            $signingFlowId,
            $signingStepSequence,
            $skipOpenFlowGuard,
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

            $completedSubjectSign = $this->chainGuard->completedSignResultRequest(
                $instance,
                $sourceVersion,
                $companyId,
                DocumentRecipientType::SubjectEmployee,
                DocumentRecipientRole::Subject,
            );

            if ($completedSubjectSign === null) {
                throw ValidationException::withMessages([
                    'action' => 'Manager countersignature requires a completed subject employee signature on the current version.',
                ]);
            }

            if ($this->chainGuard->isCompletedCompanySignatoryResult($instance, $sourceVersion, $companyId)) {
                throw ValidationException::withMessages([
                    'action' => 'Manager countersignature cannot be requested after a company countersignature on the current version.',
                ]);
            }

            try {
                $this->resolvePlacement->forInstanceVersion(
                    $instance,
                    $sourceVersion,
                    DocumentRecipientRole::Manager,
                );
            } catch (ValidationException $exception) {
                throw $exception;
            }

            $duplicate = DocumentRecipientRequest::query()
                ->forCompany($companyId)
                ->where('document_instance_id', $instance->id)
                ->where('source_document_instance_version_id', $sourceVersion->id)
                ->where('recipient_type', DocumentRecipientType::CompanyUser)
                ->where('recipient_role', DocumentRecipientRole::Manager)
                ->where('status', DocumentRecipientRequestStatus::AwaitingAction)
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'action' => 'An active manager countersignature request already exists for this document version.',
                ]);
            }

            $internalToken = DocumentRecipientRequestToken::generate();

            $request = DocumentRecipientRequest::query()->create([
                'company_id' => $companyId,
                'document_instance_id' => $instance->id,
                'source_document_instance_version_id' => $sourceVersion->id,
                'document_workflow_request_id' => $completedSubjectSign->document_workflow_request_id,
                'document_signing_flow_id' => $signingFlowId,
                'signing_step_sequence' => $signingStepSequence,
                'action' => DocumentRecipientAction::Sign,
                'recipient_type' => DocumentRecipientType::CompanyUser,
                'recipient_role' => DocumentRecipientRole::Manager,
                'employee_id' => $employee->id,
                'recipient_user_id' => $recipientUser->id,
                'recipient_name_snapshot' => (string) $recipientUser->name,
                'status' => DocumentRecipientRequestStatus::AwaitingAction,
                'token_hash' => DocumentRecipientRequestToken::hash($internalToken),
                'expires_at' => now()->addDays(DocumentRecipientRequest::EXPIRY_DAYS),
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
                    'recipient_role' => DocumentRecipientRole::Manager->value,
                    'document_instance_id' => $instance->id,
                    'source_document_instance_version_id' => $sourceVersion->id,
                    'recipient_user_id' => $recipientUser->id,
                ],
            );

            activity()
                ->causedBy($requester)
                ->performedOn($request)
                ->tap(fn ($activity) => $activity->company_id = $companyId)
                ->withProperties([
                    'action' => 'manager_countersign_request_created',
                    'document_recipient_request_id' => $request->id,
                    'document_instance_id' => $instance->id,
                    'recipient_user_id' => $recipientUser->id,
                    'recipient_role' => DocumentRecipientRole::Manager->value,
                    'status' => $request->status->value,
                ])
                ->log('Manager countersignature request created');

            return [
                'request' => $request->fresh(),
                'manager_name' => (string) $recipientUser->name,
                'respond_url' => route('organization.documents.recipient-requests.respond', [
                    'recipientRequest' => $request->id,
                ]),
            ];
        });
    }
}
