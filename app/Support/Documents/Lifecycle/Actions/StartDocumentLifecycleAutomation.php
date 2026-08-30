<?php

namespace App\Support\Documents\Lifecycle\Actions;

use App\Enums\DocumentLifecycleAutomationStage;
use App\Enums\DocumentLifecycleAutomationStatus;
use App\Enums\DocumentSigningPresetStatus;
use App\Enums\DocumentWorkflowPresetStatus;
use App\Models\DocumentLifecycleAutomation;
use App\Models\DocumentSigningPreset;
use App\Models\DocumentWorkflowPreset;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Support\Documents\Lifecycle\DocumentLifecycleAutomationActivityLogger;
use App\Support\Documents\Lifecycle\DocumentLifecycleAutomationPolicy;
use App\Support\Documents\Signing\Actions\StartDocumentSigningFlow;
use App\Support\Documents\Workflow\Actions\CreateDocumentWorkflowRequestFromPreset;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class StartDocumentLifecycleAutomation
{
    public function __construct(
        private CreateDocumentWorkflowRequestFromPreset $createWorkflowFromPreset,
        private StartDocumentSigningFlow $startSigningFlow,
        private DocumentLifecycleAutomationActivityLogger $activityLogger = new DocumentLifecycleAutomationActivityLogger,
    ) {}

    public function handle(int $lifecycleId, int $companyId): DocumentLifecycleAutomation
    {
        try {
            return DB::transaction(function () use ($lifecycleId, $companyId): DocumentLifecycleAutomation {
                /** @var DocumentLifecycleAutomation|null $lifecycle */
                $lifecycle = DocumentLifecycleAutomation::query()
                    ->forCompany($companyId)
                    ->whereKey($lifecycleId)
                    ->lockForUpdate()
                    ->first();

                if (! $lifecycle instanceof DocumentLifecycleAutomation) {
                    abort(404);
                }

                if (in_array($lifecycle->status, [
                    DocumentLifecycleAutomationStatus::Active,
                    DocumentLifecycleAutomationStatus::Completed,
                    DocumentLifecycleAutomationStatus::Stopped,
                ], true)) {
                    return $lifecycle;
                }

                $initiator = $this->resolveInitiator($lifecycle);

                if ($initiator === null) {
                    return $this->markBlocked(
                        $lifecycle,
                        DocumentLifecycleAutomationPolicy::BLOCK_MISSING_INITIATOR,
                        'The user who initiated generation could not be resolved for lifecycle automation.',
                    );
                }

                $instance = $lifecycle->documentInstance()->lockForUpdate()->first();

                if ($instance === null || (int) $instance->company_id !== $companyId) {
                    return $this->markBlocked(
                        $lifecycle,
                        DocumentLifecycleAutomationPolicy::BLOCK_ROUTING_FAILED,
                        'The document instance for this lifecycle could not be found.',
                    );
                }

                $document = EmployeeDocument::query()
                    ->whereKey($instance->employee_document_id)
                    ->where('company_id', $companyId)
                    ->first();

                if (! $document instanceof EmployeeDocument) {
                    return $this->markBlocked(
                        $lifecycle,
                        DocumentLifecycleAutomationPolicy::BLOCK_ROUTING_FAILED,
                        'The employee document for this lifecycle could not be found.',
                    );
                }

                $document->setRelation('documentInstance', $instance);

                $workflowPresetId = $lifecycle->snapshottedWorkflowPresetId();
                $signingPresetId = $lifecycle->snapshottedSigningPresetId();

                if ($workflowPresetId !== null) {
                    return $this->startWorkflow(
                        $lifecycle,
                        $document,
                        $initiator,
                        $companyId,
                        $workflowPresetId,
                    );
                }

                if ($signingPresetId !== null) {
                    return $this->startSigning(
                        $lifecycle,
                        $document,
                        $initiator,
                        $companyId,
                        $signingPresetId,
                    );
                }

                $lifecycle->update([
                    'status' => DocumentLifecycleAutomationStatus::Completed,
                    'stage' => DocumentLifecycleAutomationStage::Done,
                    'blocked_code' => null,
                    'blocked_message' => null,
                    'blocked_at' => null,
                    'started_at' => $lifecycle->started_at ?? now(),
                    'completed_at' => now(),
                ]);

                $lifecycle = $lifecycle->fresh() ?? $lifecycle;

                $this->activityLogger->log(
                    description: 'Document lifecycle automation completed',
                    event: 'document_lifecycle_completed',
                    lifecycle: $lifecycle,
                    actor: $initiator,
                );

                return $lifecycle;
            });
        } catch (\Throwable $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }

            report($exception);

            return $this->markBlockedOutsideTransaction(
                $lifecycleId,
                $companyId,
                DocumentLifecycleAutomationPolicy::BLOCK_ROUTING_FAILED,
                $this->safeExceptionMessage($exception),
            );
        }
    }

    private function startWorkflow(
        DocumentLifecycleAutomation $lifecycle,
        EmployeeDocument $document,
        User $initiator,
        int $companyId,
        int $workflowPresetId,
    ): DocumentLifecycleAutomation {
        if ($lifecycle->document_workflow_request_id !== null) {
            $lifecycle->update([
                'status' => DocumentLifecycleAutomationStatus::Active,
                'stage' => DocumentLifecycleAutomationStage::Review,
                'blocked_code' => null,
                'blocked_message' => null,
                'blocked_at' => null,
                'started_at' => $lifecycle->started_at ?? now(),
            ]);

            return $lifecycle->fresh() ?? $lifecycle;
        }

        $preset = DocumentWorkflowPreset::query()
            ->forCompany($companyId)
            ->whereKey($workflowPresetId)
            ->first();

        if (! $preset instanceof DocumentWorkflowPreset || $preset->status !== DocumentWorkflowPresetStatus::Active) {
            return $this->markBlocked(
                $lifecycle,
                DocumentLifecycleAutomationPolicy::BLOCK_INACTIVE_WORKFLOW_PRESET,
                'The snapshotted workflow preset is missing or not active.',
            );
        }

        $document->loadMissing('employee');
        $subjectEmployee = $document->employee;

        if (! $subjectEmployee instanceof Employee || (int) $subjectEmployee->company_id !== $companyId) {
            return $this->markBlocked(
                $lifecycle,
                DocumentLifecycleAutomationPolicy::BLOCK_ROUTING_FAILED,
                'The subject employee for this document could not be resolved.',
            );
        }

        try {
            $request = $this->createWorkflowFromPreset->handle(
                requester: $initiator,
                companyId: $companyId,
                document: $document,
                presetId: $workflowPresetId,
                subjectEmployee: $subjectEmployee,
                skipLifecycleGuard: true,
            );
        } catch (ValidationException $exception) {
            return $this->markBlocked(
                $lifecycle,
                DocumentLifecycleAutomationPolicy::BLOCK_ROUTING_FAILED,
                $this->safeExceptionMessage($exception),
            );
        } catch (\Throwable $exception) {
            report($exception);

            return $this->markBlocked(
                $lifecycle,
                DocumentLifecycleAutomationPolicy::BLOCK_WORKFLOW_START_FAILED,
                $this->safeExceptionMessage($exception),
            );
        }

        $lifecycle->update([
            'document_workflow_request_id' => $request->id,
            'status' => DocumentLifecycleAutomationStatus::Active,
            'stage' => DocumentLifecycleAutomationStage::Review,
            'blocked_code' => null,
            'blocked_message' => null,
            'blocked_at' => null,
            'started_at' => now(),
        ]);

        $lifecycle = $lifecycle->fresh() ?? $lifecycle;

        $this->activityLogger->log(
            description: 'Document lifecycle review started',
            event: 'document_lifecycle_review_started',
            lifecycle: $lifecycle,
            actor: $initiator,
            metadata: [
                'document_workflow_request_id' => $request->id,
                'workflow_preset_id' => $workflowPresetId,
            ],
        );

        return $lifecycle;
    }

    private function startSigning(
        DocumentLifecycleAutomation $lifecycle,
        EmployeeDocument $document,
        User $initiator,
        int $companyId,
        int $signingPresetId,
    ): DocumentLifecycleAutomation {
        if ($lifecycle->document_signing_flow_id !== null) {
            $lifecycle->update([
                'status' => DocumentLifecycleAutomationStatus::Active,
                'stage' => DocumentLifecycleAutomationStage::Signing,
                'blocked_code' => null,
                'blocked_message' => null,
                'blocked_at' => null,
                'started_at' => $lifecycle->started_at ?? now(),
            ]);

            return $lifecycle->fresh() ?? $lifecycle;
        }

        $preset = DocumentSigningPreset::query()
            ->where('company_id', $companyId)
            ->whereKey($signingPresetId)
            ->first();

        if (! $preset instanceof DocumentSigningPreset || $preset->status !== DocumentSigningPresetStatus::Active) {
            return $this->markBlocked(
                $lifecycle,
                DocumentLifecycleAutomationPolicy::BLOCK_INACTIVE_SIGNING_PRESET,
                'The snapshotted signing preset is missing or not active.',
            );
        }

        $instance = $document->documentInstance;

        if ($instance === null || (int) $instance->current_version_id !== (int) $lifecycle->source_document_instance_version_id) {
            return $this->markBlocked(
                $lifecycle,
                DocumentLifecycleAutomationPolicy::BLOCK_SOURCE_VERSION_CHANGED,
                'The document current version no longer matches the lifecycle source version.',
            );
        }

        try {
            $result = $this->startSigningFlow->handle(
                $document,
                $initiator,
                $companyId,
                $signingPresetId,
                skipLifecycleGuard: true,
            );
        } catch (ValidationException $exception) {
            return $this->markBlocked(
                $lifecycle,
                DocumentLifecycleAutomationPolicy::BLOCK_ROUTING_FAILED,
                $this->safeExceptionMessage($exception),
            );
        } catch (\Throwable $exception) {
            report($exception);

            return $this->markBlocked(
                $lifecycle,
                DocumentLifecycleAutomationPolicy::BLOCK_SIGNING_START_FAILED,
                $this->safeExceptionMessage($exception),
            );
        }

        $lifecycle->update([
            'document_signing_flow_id' => $result['flow']->id,
            'status' => DocumentLifecycleAutomationStatus::Active,
            'stage' => DocumentLifecycleAutomationStage::Signing,
            'blocked_code' => null,
            'blocked_message' => null,
            'blocked_at' => null,
            'started_at' => $lifecycle->started_at ?? now(),
        ]);

        $lifecycle = $lifecycle->fresh() ?? $lifecycle;

        $this->activityLogger->log(
            description: 'Document lifecycle signing started',
            event: 'document_lifecycle_signing_started',
            lifecycle: $lifecycle,
            actor: $initiator,
            metadata: [
                'document_signing_flow_id' => $result['flow']->id,
                'signing_preset_id' => $signingPresetId,
            ],
        );

        return $lifecycle;
    }

    private function resolveInitiator(DocumentLifecycleAutomation $lifecycle): ?User
    {
        if ($lifecycle->initiated_by === null) {
            return null;
        }

        $user = User::query()->whereKey($lifecycle->initiated_by)->first();

        return $user instanceof User ? $user : null;
    }

    private function markBlocked(
        DocumentLifecycleAutomation $lifecycle,
        string $code,
        string $message,
    ): DocumentLifecycleAutomation {
        $lifecycle->update([
            'status' => DocumentLifecycleAutomationStatus::Blocked,
            'blocked_code' => $code,
            'blocked_message' => $message,
            'blocked_at' => now(),
        ]);

        $lifecycle = $lifecycle->fresh() ?? $lifecycle;

        $this->activityLogger->log(
            description: 'Document lifecycle automation blocked',
            event: 'document_lifecycle_blocked',
            lifecycle: $lifecycle,
            metadata: [
                'blocked_code' => $code,
                'blocked_message' => $message,
            ],
        );

        return $lifecycle;
    }

    private function markBlockedOutsideTransaction(
        int $lifecycleId,
        int $companyId,
        string $code,
        string $message,
    ): DocumentLifecycleAutomation {
        return DB::transaction(function () use ($lifecycleId, $companyId, $code, $message): DocumentLifecycleAutomation {
            /** @var DocumentLifecycleAutomation $lifecycle */
            $lifecycle = DocumentLifecycleAutomation::query()
                ->forCompany($companyId)
                ->whereKey($lifecycleId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lifecycle->status->isTerminal() || $lifecycle->status === DocumentLifecycleAutomationStatus::Active) {
                return $lifecycle;
            }

            return $this->markBlocked($lifecycle, $code, $message);
        });
    }

    private function safeExceptionMessage(\Throwable $exception): string
    {
        if ($exception instanceof ValidationException) {
            foreach ($exception->errors() as $messages) {
                if (is_array($messages) && isset($messages[0]) && is_string($messages[0]) && $messages[0] !== '') {
                    return $messages[0];
                }
            }
        }

        $message = trim($exception->getMessage());

        if ($message !== '' && ! str_contains(strtolower($message), 'sql') && strlen($message) <= 500) {
            return $message;
        }

        return 'Lifecycle automation could not be started.';
    }
}
