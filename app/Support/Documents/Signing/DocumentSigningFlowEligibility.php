<?php

namespace App\Support\Documents\Signing;

use App\Models\EmployeeDocument;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestEligibility;
use App\Support\Documents\RecipientRequests\DocumentRecipientSignatureChainGuard;

final class DocumentSigningFlowEligibility
{
    public function __construct(
        private DocumentSigningFlowOpenGuard $openGuard,
        private DocumentRecipientRequestEligibility $recipientEligibility,
        private DocumentRecipientSignatureChainGuard $chainGuard,
        private DocumentSigningPresetQuery $presetQuery,
        private DocumentSigningFlowPresenter $flowPresenter,
    ) {}

    /**
     * @return array{
     *     can_start: bool,
     *     blocked_reason: string|null,
     *     presets: list<array<string, mixed>>,
     *     active_flow: array<string, mixed>|null
     * }
     */
    public function forDocument(EmployeeDocument $document, int $companyId, bool $canCreateRecipientRequests): array
    {
        $document->loadMissing(['documentInstance.currentVersion', 'employee']);
        $instance = $document->documentInstance;

        $openFlow = $instance !== null
            ? $this->openGuard->openFlowForInstance($instance, $companyId)
            : null;

        if ($openFlow !== null) {
            return [
                'can_start' => false,
                'blocked_reason' => DocumentSigningFlowOpenGuard::OPEN_FLOW_MESSAGE,
                'presets' => [],
                'active_flow' => $this->flowPresenter->forDocumentShow($openFlow),
            ];
        }

        if (! $canCreateRecipientRequests) {
            return [
                'can_start' => false,
                'blocked_reason' => null,
                'presets' => [],
                'active_flow' => null,
            ];
        }

        $recipient = $this->recipientEligibility->forDocument($document, $companyId);
        $canStart = $recipient['can_request_sign'];
        $blockedReason = $recipient['sign_blocked_reason'];

        if ($instance !== null && $instance->currentVersion !== null) {
            $version = $instance->currentVersion;

            if (
                $this->chainGuard->isCompletedSubjectSignResult($instance, $version, $companyId)
                || $this->chainGuard->isCompletedManagerSignResult($instance, $version, $companyId)
                || $this->chainGuard->isCompletedCompanySignatoryResult($instance, $version, $companyId)
            ) {
                $canStart = false;
                $blockedReason = 'A signing flow can only be started on a version that has not already been signed.';
            }
        }

        $presets = $canStart
            ? $this->presetQuery->activeForCompany($companyId)
            : [];

        if ($canStart && $presets === []) {
            $canStart = false;
            $blockedReason = 'No active signing presets are available.';
        }

        return [
            'can_start' => $canStart,
            'blocked_reason' => $canStart ? null : $blockedReason,
            'presets' => $presets,
            'active_flow' => null,
        ];
    }
}
