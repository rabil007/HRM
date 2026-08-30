<?php

namespace App\Support\Documents\Signing\Actions;

use App\Enums\DocumentRecipientAction;
use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentRecipientRole;
use App\Enums\DocumentSigningFlowStatus;
use App\Enums\DocumentSigningPresetStatus;
use App\Enums\DocumentSigningTargetType;
use App\Models\DocumentInstance;
use App\Models\DocumentInstanceVersion;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentSigningFlow;
use App\Models\DocumentSigningPreset;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Support\Documents\Lifecycle\DocumentLifecycleAutomationGuard;
use App\Support\Documents\RecipientRequests\Actions\CreateDocumentRecipientRequest;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestWorkflowGate;
use App\Support\Documents\RecipientRequests\DocumentRecipientSignatureChainGuard;
use App\Support\Documents\RecipientRequests\ResolveDocumentSignaturePlacement;
use App\Support\Documents\Signing\DocumentSignatureSlot;
use App\Support\Documents\Signing\DocumentSigningFlowActivityLogger;
use App\Support\Documents\Signing\DocumentSigningFlowOpenGuard;
use App\Support\Documents\Signing\DocumentSigningInternalSignerEligibility;
use App\Support\Documents\Signing\DocumentSigningManagementChainResolver;
use App\Support\EmployeeDocuments\DocumentAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StartDocumentSigningFlow
{
    public function __construct(
        private DocumentSigningFlowOpenGuard $openGuard,
        private DocumentRecipientRequestWorkflowGate $workflowGate,
        private DocumentRecipientSignatureChainGuard $chainGuard,
        private ResolveDocumentSignaturePlacement $resolvePlacement,
        private DocumentSigningManagementChainResolver $managementChainResolver,
        private DocumentSigningInternalSignerEligibility $signerEligibility,
        private CreateDocumentRecipientRequest $createSubjectRequest,
        private DocumentSigningFlowActivityLogger $activityLogger,
    ) {}

    /**
     * @return array{flow: DocumentSigningFlow, request: DocumentRecipientRequest, raw_token: string}
     */
    public function handle(
        EmployeeDocument $document,
        User $actor,
        int $companyId,
        int $presetId,
        bool $skipLifecycleGuard = false,
    ): array {
        DocumentAccess::assertDocumentInCompany($document, $companyId);

        $document->loadMissing(['employee']);
        $employee = $document->employee;

        if (! $employee instanceof Employee || (int) $employee->company_id !== $companyId) {
            abort(404);
        }

        return DB::transaction(function () use ($document, $employee, $actor, $companyId, $presetId, $skipLifecycleGuard): array {
            $instance = DocumentInstance::query()
                ->where('employee_document_id', $document->id)
                ->where('company_id', $companyId)
                ->where('employee_id', $document->employee_id)
                ->lockForUpdate()
                ->first();

            if (! $instance instanceof DocumentInstance) {
                throw ValidationException::withMessages([
                    'action' => 'Signing flows require a generated document instance.',
                ]);
            }

            if (! $skipLifecycleGuard) {
                app(DocumentLifecycleAutomationGuard::class)->assertManualSigningAllowed($instance, $companyId);
            }

            $this->openGuard->assertNoOpenFlow($instance, $companyId);

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

            $this->workflowGate->assertCanCreateForVersion($instance, $companyId);

            if (
                $this->chainGuard->isCompletedSubjectSignResult($instance, $sourceVersion, $companyId)
                || $this->chainGuard->isCompletedManagerSignResult($instance, $sourceVersion, $companyId)
                || $this->chainGuard->isCompletedCompanySignatoryResult($instance, $sourceVersion, $companyId)
            ) {
                throw ValidationException::withMessages([
                    'action' => 'A signing flow can only be started on a version that has not already been signed.',
                ]);
            }

            $conflicting = DocumentRecipientRequest::query()
                ->forCompany($companyId)
                ->where('document_instance_id', $instance->id)
                ->where('source_document_instance_version_id', $sourceVersion->id)
                ->where('action', DocumentRecipientAction::Sign)
                ->where('status', DocumentRecipientRequestStatus::AwaitingAction)
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->exists();

            if ($conflicting) {
                throw ValidationException::withMessages([
                    'action' => 'An active signing request already exists for this document version.',
                ]);
            }

            /** @var DocumentSigningPreset $preset */
            $preset = DocumentSigningPreset::query()
                ->whereKey($presetId)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($preset->status !== DocumentSigningPresetStatus::Active) {
                throw ValidationException::withMessages([
                    'document_signing_preset_id' => 'Only active signing presets can be used.',
                ]);
            }

            $preset->load(['steps.targetUser']);

            if ($preset->steps->isEmpty()) {
                throw ValidationException::withMessages([
                    'document_signing_preset_id' => 'This signing preset has no steps configured.',
                ]);
            }

            $routingSnapshot = $this->buildRoutingSnapshot($preset, $employee, $companyId);

            foreach ($routingSnapshot['steps'] as $step) {
                $role = DocumentRecipientRole::from($step['recipient_role']);
                $slotKey = (string) $step['signature_slot_key'];
                $this->resolvePlacement->forInstanceVersionSlot($instance, $sourceVersion, $role, $slotKey);
            }

            $flow = DocumentSigningFlow::query()->create([
                'company_id' => $companyId,
                'document_instance_id' => $instance->id,
                'document_signing_preset_id' => $preset->id,
                'starting_document_instance_version_id' => $sourceVersion->id,
                'preset_name_snapshot' => $preset->name,
                'routing_definition_snapshot' => $routingSnapshot,
                'status' => DocumentSigningFlowStatus::Active,
                'current_step_sequence' => 1,
                'started_by' => $actor->id,
                'started_at' => now(),
            ]);

            $subjectStep = $routingSnapshot['steps'][0];

            $subjectResult = $this->createSubjectRequest->handle(
                $document,
                DocumentRecipientAction::Sign,
                $actor,
                $companyId,
                signingFlowId: (int) $flow->id,
                signingStepSequence: 1,
                skipOpenFlowGuard: true,
                signatureSlotKey: (string) $subjectStep['signature_slot_key'],
                signingStepLabelSnapshot: (string) ($subjectStep['step_label'] ?? DocumentSignatureSlot::defaultLabel(DocumentRecipientRole::Subject)),
            );

            $this->activityLogger->log(
                description: 'Document signing flow started',
                event: 'signing_flow_started',
                flow: $flow,
                actor: $actor,
                metadata: [
                    'starting_document_instance_version_id' => $sourceVersion->id,
                    'document_recipient_request_id' => $subjectResult['request']->id,
                    'step_sequence' => 1,
                    'recipient_role' => DocumentRecipientRole::Subject->value,
                    'signature_slot_key' => $subjectStep['signature_slot_key'],
                    'signing_step_label' => $subjectStep['step_label'] ?? null,
                ],
            );

            return [
                'flow' => $flow->fresh(),
                'request' => $subjectResult['request'],
                'raw_token' => $subjectResult['raw_token'],
            ];
        });
    }

    /**
     * @return array{schema_version: int, steps: list<array<string, mixed>>}
     */
    private function buildRoutingSnapshot(
        DocumentSigningPreset $preset,
        Employee $employee,
        int $companyId,
    ): array {
        $managerCount = $preset->steps
            ->filter(fn ($step): bool => $step->recipient_role === DocumentRecipientRole::Manager)
            ->count();

        $managers = $managerCount > 0
            ? $this->managementChainResolver->resolveActionableUniqueManagers($employee, $companyId)
            : [];

        if ($managerCount > count($managers)) {
            throw ValidationException::withMessages([
                'document_signing_preset_id' => sprintf(
                    'This signing preset requires %d eligible management signer%s, but only %d %s available in the employee\'s management hierarchy.',
                    $managerCount,
                    $managerCount === 1 ? '' : 's',
                    count($managers),
                    count($managers) === 1 ? 'is' : 'are',
                ),
            ]);
        }

        $steps = [];
        $seenRecipientUserIds = [];
        $managerOccurrence = 0;
        $companyOccurrence = 0;

        foreach ($preset->steps as $step) {
            if ($step->recipient_role === DocumentRecipientRole::Subject) {
                $label = filled($step->step_label)
                    ? (string) $step->step_label
                    : DocumentSignatureSlot::defaultLabel(DocumentRecipientRole::Subject);

                $steps[] = [
                    'sequence' => (int) $step->sequence,
                    'recipient_role' => DocumentRecipientRole::Subject->value,
                    'target_type' => DocumentSigningTargetType::SubjectEmployee->value,
                    'step_label' => $label,
                    'signature_slot_key' => DocumentSignatureSlot::SUBJECT,
                    'employee_id' => $employee->id,
                    'recipient_user_id' => null,
                    'recipient_name' => (string) $employee->name,
                ];

                continue;
            }

            if ($step->recipient_role === DocumentRecipientRole::Manager) {
                $managerOccurrence++;
                $resolved = $managers[$managerOccurrence - 1];
                $userId = (int) $resolved['user']->id;

                if (isset($seenRecipientUserIds[$userId])) {
                    throw ValidationException::withMessages([
                        'document_signing_preset_id' => 'This signing preset would assign the same internal signer to multiple stages.',
                    ]);
                }

                $seenRecipientUserIds[$userId] = true;
                $label = filled($step->step_label)
                    ? (string) $step->step_label
                    : DocumentSignatureSlot::defaultLabel(DocumentRecipientRole::Manager, $managerOccurrence);

                $steps[] = [
                    'sequence' => (int) $step->sequence,
                    'recipient_role' => DocumentRecipientRole::Manager->value,
                    'target_type' => DocumentSigningTargetType::DepartmentManager->value,
                    'step_label' => $label,
                    'signature_slot_key' => DocumentSignatureSlot::forRoleOccurrence(
                        DocumentRecipientRole::Manager,
                        $managerOccurrence,
                    ),
                    'management_chain_position' => $managerOccurrence,
                    'manager_employee_id' => $resolved['manager']->id,
                    'recipient_user_id' => $userId,
                    'recipient_name' => (string) $resolved['user']->name,
                ];

                continue;
            }

            if ($step->recipient_role === DocumentRecipientRole::CompanySignatory) {
                $companyOccurrence++;
                $user = $step->targetUser;

                if (! $user instanceof User || ! $this->signerEligibility->isActionable($user, $companyId)) {
                    throw ValidationException::withMessages([
                        'document_signing_preset_id' => 'The configured company signatory is no longer eligible to sign.',
                    ]);
                }

                $userId = (int) $user->id;

                if (isset($seenRecipientUserIds[$userId])) {
                    throw ValidationException::withMessages([
                        'document_signing_preset_id' => 'This signing preset would assign the same internal signer to multiple stages.',
                    ]);
                }

                $seenRecipientUserIds[$userId] = true;
                $label = filled($step->step_label)
                    ? (string) $step->step_label
                    : DocumentSignatureSlot::defaultLabel(DocumentRecipientRole::CompanySignatory, $companyOccurrence);

                $steps[] = [
                    'sequence' => (int) $step->sequence,
                    'recipient_role' => DocumentRecipientRole::CompanySignatory->value,
                    'target_type' => DocumentSigningTargetType::SpecificUser->value,
                    'step_label' => $label,
                    'signature_slot_key' => DocumentSignatureSlot::forRoleOccurrence(
                        DocumentRecipientRole::CompanySignatory,
                        $companyOccurrence,
                    ),
                    'recipient_user_id' => $userId,
                    'recipient_name' => (string) $user->name,
                ];
            }
        }

        return [
            'schema_version' => 2,
            'steps' => $steps,
        ];
    }
}
