<?php

namespace App\Support\Documents\RecipientRequests;

use App\Enums\DocumentRecipientAction;
use App\Enums\DocumentRecipientRequestStatus;
use App\Models\DocumentInstance;
use App\Models\DocumentRecipientRequest;
use App\Models\EmployeeDocument;
use Illuminate\Validation\ValidationException;

final class DocumentRecipientRequestEligibility
{
    public function __construct(
        private DocumentRecipientRequestWorkflowGate $workflowGate,
        private ResolveDocumentSignaturePlacement $resolvePlacement,
    ) {}

    /**
     * @return array{
     *     can_request_sign: bool,
     *     can_request_acknowledge: bool,
     *     sign_blocked_reason: string|null,
     *     acknowledge_blocked_reason: string|null,
     * }
     */
    public function forDocument(EmployeeDocument $document, int $companyId): array
    {
        $document->loadMissing('documentInstance.currentVersion');

        $instance = $document->documentInstance;

        if ($instance === null || (int) $instance->company_id !== $companyId) {
            return $this->blocked('This document was not generated through the unified documents pipeline.');
        }

        if ($instance->current_version_id === null) {
            return $this->blocked('This document has no current version.');
        }

        if ((int) $instance->employee_id !== (int) $document->employee_id) {
            return $this->blocked('Document employee mismatch.');
        }

        try {
            $this->workflowGate->assertCanCreateForVersion($instance, $companyId);
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();

            return [
                'can_request_sign' => false,
                'can_request_acknowledge' => false,
                'sign_blocked_reason' => is_string($message) ? $message : null,
                'acknowledge_blocked_reason' => is_string($message) ? $message : null,
            ];
        }

        $version = $instance->currentVersion;

        if ($version === null) {
            return $this->blocked('This document has no current version.');
        }

        $signBlocked = $this->activeDuplicateReason($instance, $version, DocumentRecipientAction::Sign, $companyId);
        $ackBlocked = $this->activeDuplicateReason($instance, $version, DocumentRecipientAction::Acknowledge, $companyId);

        if ($signBlocked === null) {
            try {
                $this->resolvePlacement->forInstanceVersion($instance, $version);
            } catch (ValidationException $exception) {
                $signBlocked = collect($exception->errors())->flatten()->first() ?: 'Signature placement is not configured.';
            }
        }

        return [
            'can_request_sign' => $signBlocked === null,
            'can_request_acknowledge' => $ackBlocked === null,
            'sign_blocked_reason' => $signBlocked,
            'acknowledge_blocked_reason' => $ackBlocked,
        ];
    }

    /**
     * @return array{can_request_sign: bool, can_request_acknowledge: bool, sign_blocked_reason: string|null, acknowledge_blocked_reason: string|null}
     */
    private function blocked(string $reason): array
    {
        return [
            'can_request_sign' => false,
            'can_request_acknowledge' => false,
            'sign_blocked_reason' => $reason,
            'acknowledge_blocked_reason' => $reason,
        ];
    }

    private function activeDuplicateReason(
        DocumentInstance $instance,
        $version,
        DocumentRecipientAction $action,
        int $companyId,
    ): ?string {
        $exists = DocumentRecipientRequest::query()
            ->forCompany($companyId)
            ->where('document_instance_id', $instance->id)
            ->where('source_document_instance_version_id', $version->id)
            ->where('employee_id', $instance->employee_id)
            ->where('action', $action)
            ->where('status', DocumentRecipientRequestStatus::AwaitingAction)
            ->where('expires_at', '>', now())
            ->exists();

        return $exists
            ? 'An active '.strtolower($action->label()).' request already exists for this version.'
            : null;
    }
}
