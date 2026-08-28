<?php

namespace App\Support\Documents\RecipientRequests;

use App\Enums\DocumentRecipientAction;
use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentRecipientRole;
use App\Enums\DocumentRecipientType;
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
     *     can_request_company_countersign: bool,
     *     sign_blocked_reason: string|null,
     *     acknowledge_blocked_reason: string|null,
     *     company_countersign_blocked_reason: string|null,
     * }
     */
    public function forDocument(EmployeeDocument $document, int $companyId): array
    {
        $document->loadMissing('documentInstance.currentVersion');

        $instance = $document->documentInstance;

        if ($instance === null || (int) $instance->company_id !== $companyId) {
            return $this->blockedAll('This document was not generated through the unified documents pipeline.');
        }

        if ($instance->current_version_id === null) {
            return $this->blockedAll('This document has no current version.');
        }

        if ((int) $instance->employee_id !== (int) $document->employee_id) {
            return $this->blockedAll('Document employee mismatch.');
        }

        try {
            $this->workflowGate->assertCanCreateForVersion($instance, $companyId);
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();
            $reason = is_string($message) ? $message : null;

            return [
                'can_request_sign' => false,
                'can_request_acknowledge' => false,
                'can_request_company_countersign' => false,
                'sign_blocked_reason' => $reason,
                'acknowledge_blocked_reason' => $reason,
                'company_countersign_blocked_reason' => $reason,
            ];
        }

        $version = $instance->currentVersion;

        if ($version === null) {
            return $this->blockedAll('This document has no current version.');
        }

        $signBlocked = $this->activeDuplicateReason(
            $instance,
            $version,
            DocumentRecipientAction::Sign,
            DocumentRecipientType::SubjectEmployee,
            DocumentRecipientRole::Subject,
            $companyId,
        );
        $ackBlocked = $this->activeDuplicateReason(
            $instance,
            $version,
            DocumentRecipientAction::Acknowledge,
            DocumentRecipientType::SubjectEmployee,
            DocumentRecipientRole::Subject,
            $companyId,
        );

        if ($signBlocked === null) {
            try {
                $this->resolvePlacement->forInstanceVersion(
                    $instance,
                    $version,
                    DocumentRecipientRole::Subject,
                );
            } catch (ValidationException $exception) {
                $signBlocked = collect($exception->errors())->flatten()->first() ?: 'Signature placement is not configured.';
            }
        }

        $countersignBlocked = $this->companyCountersignBlockedReason($instance, $version, $companyId);

        return [
            'can_request_sign' => $signBlocked === null,
            'can_request_acknowledge' => $ackBlocked === null,
            'can_request_company_countersign' => $countersignBlocked === null,
            'sign_blocked_reason' => $signBlocked,
            'acknowledge_blocked_reason' => $ackBlocked,
            'company_countersign_blocked_reason' => $countersignBlocked,
        ];
    }

    /**
     * @return array{
     *     can_request_sign: bool,
     *     can_request_acknowledge: bool,
     *     can_request_company_countersign: bool,
     *     sign_blocked_reason: string|null,
     *     acknowledge_blocked_reason: string|null,
     *     company_countersign_blocked_reason: string|null,
     * }
     */
    private function blockedAll(string $reason): array
    {
        return [
            'can_request_sign' => false,
            'can_request_acknowledge' => false,
            'can_request_company_countersign' => false,
            'sign_blocked_reason' => $reason,
            'acknowledge_blocked_reason' => $reason,
            'company_countersign_blocked_reason' => $reason,
        ];
    }

    private function companyCountersignBlockedReason(
        DocumentInstance $instance,
        $version,
        int $companyId,
    ): ?string {
        $completedSubjectSign = DocumentRecipientRequest::query()
            ->forCompany($companyId)
            ->where('document_instance_id', $instance->id)
            ->where('recipient_type', DocumentRecipientType::SubjectEmployee)
            ->where('recipient_role', DocumentRecipientRole::Subject)
            ->where('action', DocumentRecipientAction::Sign)
            ->where('status', DocumentRecipientRequestStatus::Completed)
            ->where('result_document_instance_version_id', $version->id)
            ->exists();

        if (! $completedSubjectSign) {
            return 'Company countersignature requires a completed subject employee signature on the current version.';
        }

        $duplicate = $this->activeDuplicateReason(
            $instance,
            $version,
            DocumentRecipientAction::Sign,
            DocumentRecipientType::CompanyUser,
            DocumentRecipientRole::CompanySignatory,
            $companyId,
        );

        if ($duplicate !== null) {
            return $duplicate;
        }

        try {
            $this->resolvePlacement->forInstanceVersion(
                $instance,
                $version,
                DocumentRecipientRole::CompanySignatory,
            );
        } catch (ValidationException $exception) {
            return collect($exception->errors())->flatten()->first()
                ?: 'Company signatory signature placement is not configured.';
        }

        return null;
    }

    private function activeDuplicateReason(
        DocumentInstance $instance,
        $version,
        DocumentRecipientAction $action,
        DocumentRecipientType $recipientType,
        DocumentRecipientRole $recipientRole,
        int $companyId,
    ): ?string {
        $query = DocumentRecipientRequest::query()
            ->forCompany($companyId)
            ->where('document_instance_id', $instance->id)
            ->where('source_document_instance_version_id', $version->id)
            ->where('action', $action)
            ->where('recipient_type', $recipientType)
            ->where('recipient_role', $recipientRole)
            ->where('status', DocumentRecipientRequestStatus::AwaitingAction)
            ->where('expires_at', '>', now());

        if ($recipientType === DocumentRecipientType::SubjectEmployee) {
            $query->where('employee_id', $instance->employee_id);
        }

        $exists = $query->exists();

        if (! $exists) {
            return null;
        }

        if ($recipientRole === DocumentRecipientRole::CompanySignatory) {
            return 'An active company countersignature request already exists for this version.';
        }

        return 'An active '.strtolower($action->label()).' request already exists for this version.';
    }
}
