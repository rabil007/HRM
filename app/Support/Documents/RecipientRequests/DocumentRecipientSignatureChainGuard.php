<?php

namespace App\Support\Documents\RecipientRequests;

use App\Enums\DocumentRecipientAction;
use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentRecipientRole;
use App\Enums\DocumentRecipientType;
use App\Models\DocumentInstance;
use App\Models\DocumentInstanceVersion;
use App\Models\DocumentRecipientRequest;

/**
 * Authoritative predecessor checks for the Phase 6B signing chain.
 *
 * Uses completed recipient-request provenance (not DocumentInstanceVersion.stage).
 */
final class DocumentRecipientSignatureChainGuard
{
    /**
     * Whether the given version is the exact result of a completed subject-employee signature.
     */
    public function isCompletedSubjectSignResult(
        DocumentInstance $instance,
        DocumentInstanceVersion $version,
        int $companyId,
    ): bool {
        return $this->findCompletedSignResult(
            $instance,
            $version,
            $companyId,
            DocumentRecipientType::SubjectEmployee,
            DocumentRecipientRole::Subject,
        ) !== null;
    }

    /**
     * Whether the given version is the exact result of a completed manager signature.
     */
    public function isCompletedManagerSignResult(
        DocumentInstance $instance,
        DocumentInstanceVersion $version,
        int $companyId,
    ): bool {
        return $this->findCompletedSignResult(
            $instance,
            $version,
            $companyId,
            DocumentRecipientType::CompanyUser,
            DocumentRecipientRole::Manager,
        ) !== null;
    }

    /**
     * Whether the given version is the exact result of a completed company-signatory signature.
     */
    public function isCompletedCompanySignatoryResult(
        DocumentInstance $instance,
        DocumentInstanceVersion $version,
        int $companyId,
    ): bool {
        return $this->findCompletedSignResult(
            $instance,
            $version,
            $companyId,
            DocumentRecipientType::CompanyUser,
            DocumentRecipientRole::CompanySignatory,
        ) !== null;
    }

    /**
     * Manager may only countersign a completed subject-employee signature result.
     */
    public function canRequestManagerCountersignOn(
        DocumentInstance $instance,
        DocumentInstanceVersion $version,
        int $companyId,
    ): bool {
        return $this->isCompletedSubjectSignResult($instance, $version, $companyId);
    }

    /**
     * Company signatory may countersign after subject OR after manager
     * (whose chain originates from a completed subject signature).
     */
    public function canRequestCompanyCountersignOn(
        DocumentInstance $instance,
        DocumentInstanceVersion $version,
        int $companyId,
    ): bool {
        if ($this->isCompletedSubjectSignResult($instance, $version, $companyId)) {
            return true;
        }

        $managerRequest = $this->findCompletedSignResult(
            $instance,
            $version,
            $companyId,
            DocumentRecipientType::CompanyUser,
            DocumentRecipientRole::Manager,
        );

        if ($managerRequest === null) {
            return false;
        }

        $sourceVersion = DocumentInstanceVersion::query()
            ->whereKey($managerRequest->source_document_instance_version_id)
            ->where('company_id', $companyId)
            ->where('document_instance_id', $instance->id)
            ->first();

        if ($sourceVersion === null) {
            return false;
        }

        return $this->isCompletedSubjectSignResult($instance, $sourceVersion, $companyId);
    }

    /**
     * Provenance request whose result is the given version, for workflow inheritance.
     */
    public function completedSignResultRequest(
        DocumentInstance $instance,
        DocumentInstanceVersion $version,
        int $companyId,
        DocumentRecipientType $recipientType,
        DocumentRecipientRole $recipientRole,
    ): ?DocumentRecipientRequest {
        return $this->findCompletedSignResult(
            $instance,
            $version,
            $companyId,
            $recipientType,
            $recipientRole,
        );
    }

    /**
     * Best completed sign request that produced the current version (subject or manager),
     * preferring the most recent completion for provenance inheritance.
     */
    public function completedPredecessorForCompanyCountersign(
        DocumentInstance $instance,
        DocumentInstanceVersion $version,
        int $companyId,
    ): ?DocumentRecipientRequest {
        $manager = $this->findCompletedSignResult(
            $instance,
            $version,
            $companyId,
            DocumentRecipientType::CompanyUser,
            DocumentRecipientRole::Manager,
        );

        if ($manager !== null) {
            $sourceVersion = DocumentInstanceVersion::query()
                ->whereKey($manager->source_document_instance_version_id)
                ->where('company_id', $companyId)
                ->where('document_instance_id', $instance->id)
                ->first();

            if ($sourceVersion !== null
                && $this->isCompletedSubjectSignResult($instance, $sourceVersion, $companyId)
            ) {
                return $manager;
            }
        }

        return $this->findCompletedSignResult(
            $instance,
            $version,
            $companyId,
            DocumentRecipientType::SubjectEmployee,
            DocumentRecipientRole::Subject,
        );
    }

    private function findCompletedSignResult(
        DocumentInstance $instance,
        DocumentInstanceVersion $version,
        int $companyId,
        DocumentRecipientType $recipientType,
        DocumentRecipientRole $recipientRole,
    ): ?DocumentRecipientRequest {
        return DocumentRecipientRequest::query()
            ->forCompany($companyId)
            ->where('document_instance_id', $instance->id)
            ->where('recipient_type', $recipientType)
            ->where('recipient_role', $recipientRole)
            ->where('action', DocumentRecipientAction::Sign)
            ->where('status', DocumentRecipientRequestStatus::Completed)
            ->where('result_document_instance_version_id', $version->id)
            ->latest('completed_at')
            ->first();
    }
}
