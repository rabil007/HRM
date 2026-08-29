<?php

namespace App\Support\Documents\RecipientRequests;

use App\Enums\DocumentRecipientAction;
use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentRecipientRole;
use App\Enums\DocumentRecipientType;
use App\Models\DocumentInstance;
use App\Models\DocumentRecipientRequest;
use App\Models\EmployeeDocument;
use App\Support\Documents\Signing\DocumentSigningFlowOpenGuard;
use Illuminate\Validation\ValidationException;

final class DocumentRecipientRequestEligibility
{
    public function __construct(
        private DocumentRecipientRequestWorkflowGate $workflowGate,
        private ResolveDocumentSignaturePlacement $resolvePlacement,
        private DocumentRecipientSignatureChainGuard $chainGuard,
        private DocumentRecipientManagerResolver $managerResolver,
        private DocumentSigningFlowOpenGuard $openFlowGuard = new DocumentSigningFlowOpenGuard,
    ) {}

    /**
     * @return array{
     *     can_request_sign: bool,
     *     can_request_acknowledge: bool,
     *     can_request_manager_countersign: bool,
     *     can_request_company_countersign: bool,
     *     sign_blocked_reason: string|null,
     *     acknowledge_blocked_reason: string|null,
     *     manager_countersign_blocked_reason: string|null,
     *     company_countersign_blocked_reason: string|null,
     *     resolved_manager: array{id: int, name: string, email: string|null}|null,
     * }
     */
    public function forDocument(EmployeeDocument $document, int $companyId): array
    {
        $document->loadMissing(['documentInstance.currentVersion', 'employee']);

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

        if ($this->openFlowGuard->hasOpenFlow($instance, $companyId)) {
            return $this->blockedAll(DocumentSigningFlowOpenGuard::OPEN_FLOW_MESSAGE);
        }

        try {
            $this->workflowGate->assertCanCreateForVersion($instance, $companyId);
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();
            $reason = is_string($message) ? $message : null;

            return [
                'can_request_sign' => false,
                'can_request_acknowledge' => false,
                'can_request_manager_countersign' => false,
                'can_request_company_countersign' => false,
                'sign_blocked_reason' => $reason,
                'acknowledge_blocked_reason' => $reason,
                'manager_countersign_blocked_reason' => $reason,
                'company_countersign_blocked_reason' => $reason,
                'resolved_manager' => null,
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

        [$managerBlocked, $resolvedManager] = $this->managerCountersignBlockedReason($document, $instance, $version, $companyId);
        $countersignBlocked = $this->companyCountersignBlockedReason($instance, $version, $companyId);

        return [
            'can_request_sign' => $signBlocked === null,
            'can_request_acknowledge' => $ackBlocked === null,
            'can_request_manager_countersign' => $managerBlocked === null,
            'can_request_company_countersign' => $countersignBlocked === null,
            'sign_blocked_reason' => $signBlocked,
            'acknowledge_blocked_reason' => $ackBlocked,
            'manager_countersign_blocked_reason' => $managerBlocked,
            'company_countersign_blocked_reason' => $countersignBlocked,
            'resolved_manager' => $resolvedManager,
        ];
    }

    /**
     * @return array{
     *     can_request_sign: bool,
     *     can_request_acknowledge: bool,
     *     can_request_manager_countersign: bool,
     *     can_request_company_countersign: bool,
     *     sign_blocked_reason: string|null,
     *     acknowledge_blocked_reason: string|null,
     *     manager_countersign_blocked_reason: string|null,
     *     company_countersign_blocked_reason: string|null,
     *     resolved_manager: null,
     * }
     */
    private function blockedAll(string $reason): array
    {
        return [
            'can_request_sign' => false,
            'can_request_acknowledge' => false,
            'can_request_manager_countersign' => false,
            'can_request_company_countersign' => false,
            'sign_blocked_reason' => $reason,
            'acknowledge_blocked_reason' => $reason,
            'manager_countersign_blocked_reason' => $reason,
            'company_countersign_blocked_reason' => $reason,
            'resolved_manager' => null,
        ];
    }

    /**
     * @return array{0: string|null, 1: array{id: int, name: string, email: string|null}|null}
     */
    private function managerCountersignBlockedReason(
        EmployeeDocument $document,
        DocumentInstance $instance,
        $version,
        int $companyId,
    ): array {
        if (! $this->chainGuard->canRequestManagerCountersignOn($instance, $version, $companyId)) {
            if ($this->chainGuard->isCompletedCompanySignatoryResult($instance, $version, $companyId)) {
                return ['Manager countersignature cannot be requested after a company countersignature on the current version.', null];
            }

            return ['Manager countersignature requires a completed subject employee signature on the current version.', null];
        }

        $duplicate = $this->activeDuplicateReason(
            $instance,
            $version,
            DocumentRecipientAction::Sign,
            DocumentRecipientType::CompanyUser,
            DocumentRecipientRole::Manager,
            $companyId,
        );

        if ($duplicate !== null) {
            return [$duplicate, null];
        }

        try {
            $this->resolvePlacement->forInstanceVersion(
                $instance,
                $version,
                DocumentRecipientRole::Manager,
            );
        } catch (ValidationException $exception) {
            return [
                collect($exception->errors())->flatten()->first()
                    ?: 'Manager signature placement is not configured.',
                null,
            ];
        }

        $employee = $document->employee;

        if ($employee === null) {
            return ['No eligible department manager is available to sign this document.', null];
        }

        $resolved = $this->managerResolver->tryResolveForEmployee($employee, $companyId);

        if ($resolved === null) {
            return ['No eligible department manager is available to sign this document.', null];
        }

        return [
            null,
            [
                'id' => (int) $resolved['user']->id,
                'name' => (string) $resolved['user']->name,
                'email' => $resolved['user']->email,
            ],
        ];
    }

    private function companyCountersignBlockedReason(
        DocumentInstance $instance,
        $version,
        int $companyId,
    ): ?string {
        if (! $this->chainGuard->canRequestCompanyCountersignOn($instance, $version, $companyId)) {
            return 'Company countersignature requires a completed subject employee or department manager signature on the current version.';
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

        return match ($recipientRole) {
            DocumentRecipientRole::CompanySignatory => 'An active company countersignature request already exists for this version.',
            DocumentRecipientRole::Manager => 'An active manager countersignature request already exists for this version.',
            default => 'An active '.strtolower($action->label()).' request already exists for this version.',
        };
    }
}
