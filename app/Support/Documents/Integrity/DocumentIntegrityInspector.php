<?php

namespace App\Support\Documents\Integrity;

use App\Enums\DocumentIntegrityIssueSeverity;
use App\Enums\DocumentLifecycleAutomationStage;
use App\Enums\DocumentLifecycleAutomationStatus;
use App\Enums\DocumentRecipientAction;
use App\Enums\DocumentRecipientRequestDeliveryPurpose;
use App\Enums\DocumentRecipientRequestDeliveryStatus;
use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentRecipientType;
use App\Enums\DocumentSigningFlowStatus;
use App\Enums\DocumentWorkflowRequestStatus;
use App\Enums\DocumentWorkflowStageStatus;
use App\Enums\DocumentWorkflowTaskStatus;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\DocumentInstance;
use App\Models\DocumentInstanceVersion;
use App\Models\DocumentLifecycleAutomation;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentRecipientRequestDelivery;
use App\Models\DocumentSigningFlow;
use App\Models\DocumentSigningPreset;
use App\Models\DocumentWorkflowPreset;
use App\Models\DocumentWorkflowRequest;
use App\Models\DocumentWorkflowStage;
use App\Models\DocumentWorkflowTask;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\Companies\ResolveCompanyAccess;
use App\Support\Documents\DocumentInstanceStorage;
use App\Support\EmployeeFiles\EmployeePrivateFile;
use App\Support\EmployeeFiles\EmployeePrivateFileKind;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class DocumentIntegrityInspector
{
    public const CHUNK_SIZE = 100;

    public function __construct(
        private ResolveCompanyAccess $companyAccess = new ResolveCompanyAccess,
    ) {}

    public function inspectCompany(int $companyId, bool $verifyFiles, DocumentIntegrityAuditResult $result): void
    {
        $this->inspectInstances($companyId, $verifyFiles, $result);
        $this->inspectVersions($companyId, $verifyFiles, $result);
        $this->inspectWorkflows($companyId, $result);
        $this->inspectLifecycles($companyId, $result);
        $this->inspectSigningFlows($companyId, $result);
        $this->inspectRecipientRequests($companyId, $result);
        $this->inspectDeliveries($companyId, $result);
    }

    private function inspectInstances(int $companyId, bool $verifyFiles, DocumentIntegrityAuditResult $result): void
    {
        DocumentInstance::query()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $instances) use ($verifyFiles, $result): void {
                /** @var Collection<int, DocumentInstance> $instances */
                $versionIds = $instances->pluck('current_version_id')->filter()->map(fn ($id): int => (int) $id)->unique()->all();
                $documentIds = $instances->pluck('employee_document_id')->filter()->map(fn ($id): int => (int) $id)->unique()->all();
                $templateVersionIds = $instances->pluck('document_generation_template_version_id')->filter()->map(fn ($id): int => (int) $id)->unique()->all();
                $instanceIds = $instances->pluck('id')->map(fn ($id): int => (int) $id)->all();

                $versions = $versionIds === []
                    ? collect()
                    : DocumentInstanceVersion::query()->whereIn('id', $versionIds)->get()->keyBy('id');
                $ownedVersions = DocumentInstanceVersion::query()
                    ->whereIn('document_instance_id', $instanceIds)
                    ->get()
                    ->groupBy('document_instance_id');
                $documents = $documentIds === []
                    ? collect()
                    : EmployeeDocument::query()->whereIn('id', $documentIds)->get()->keyBy('id');
                $templateVersions = $templateVersionIds === []
                    ? collect()
                    : DocumentGenerationTemplateVersion::query()->whereIn('id', $templateVersionIds)->get()->keyBy('id');

                foreach ($instances as $instance) {
                    $this->inspectInstance(
                        $instance,
                        $versions,
                        $ownedVersions->get($instance->id, collect()),
                        $documents,
                        $templateVersions,
                        $verifyFiles,
                        $result,
                    );
                }
            });
    }

    /**
     * @param  Collection<int, DocumentInstanceVersion>  $versions
     * @param  Collection<int, DocumentInstanceVersion>  $ownedVersions
     * @param  Collection<int, EmployeeDocument>  $documents
     * @param  Collection<int, DocumentGenerationTemplateVersion>  $templateVersions
     */
    private function inspectInstance(
        DocumentInstance $instance,
        Collection $versions,
        Collection $ownedVersions,
        Collection $documents,
        Collection $templateVersions,
        bool $verifyFiles,
        DocumentIntegrityAuditResult $result,
    ): void {
        $companyId = (int) $instance->company_id;
        $currentVersionId = $instance->current_version_id !== null ? (int) $instance->current_version_id : null;

        if ($currentVersionId === null) {
            $result->add($this->issue(
                'instance_missing_current_version',
                DocumentIntegrityIssueSeverity::High,
                $companyId,
                'document_instance',
                (int) $instance->id,
                null,
                false,
                'Document instance has no current version.',
            ));
        } else {
            $current = $versions->get($currentVersionId);

            if (! $current instanceof DocumentInstanceVersion) {
                $result->add($this->issue(
                    'instance_current_version_missing',
                    DocumentIntegrityIssueSeverity::High,
                    $companyId,
                    'document_instance',
                    (int) $instance->id,
                    $currentVersionId,
                    false,
                    'Current version reference is missing.',
                ));
            } else {
                if ((int) $current->document_instance_id !== (int) $instance->id) {
                    $result->add($this->issue(
                        'instance_current_version_wrong_instance',
                        DocumentIntegrityIssueSeverity::Critical,
                        $companyId,
                        'document_instance',
                        (int) $instance->id,
                        (int) $current->id,
                        false,
                        'Current version belongs to another document instance.',
                    ));
                }

                if ((int) $current->company_id !== $companyId) {
                    $result->add($this->issue(
                        'instance_current_version_cross_company',
                        DocumentIntegrityIssueSeverity::Critical,
                        $companyId,
                        'document_instance',
                        (int) $instance->id,
                        (int) $current->id,
                        false,
                        'Current version belongs to another company.',
                    ));
                }
            }
        }

        foreach ($ownedVersions as $ownedVersion) {
            if ((int) $ownedVersion->company_id !== $companyId) {
                $result->add($this->issue(
                    'version_company_mismatch',
                    DocumentIntegrityIssueSeverity::Critical,
                    $companyId,
                    'document_instance_version',
                    (int) $ownedVersion->id,
                    (int) $instance->id,
                    false,
                    'Version company does not match its document instance.',
                ));
            }
        }

        $employeeDocumentId = $instance->employee_document_id !== null ? (int) $instance->employee_document_id : null;

        if ($employeeDocumentId !== null) {
            $document = $documents->get($employeeDocumentId);

            if (! $document instanceof EmployeeDocument) {
                $result->add($this->issue(
                    'instance_employee_document_missing',
                    DocumentIntegrityIssueSeverity::High,
                    $companyId,
                    'document_instance',
                    (int) $instance->id,
                    $employeeDocumentId,
                    false,
                    'Linked library document is missing.',
                ));
            } else {
                if ((int) $document->company_id !== $companyId) {
                    $result->add($this->issue(
                        'instance_employee_document_cross_company',
                        DocumentIntegrityIssueSeverity::Critical,
                        $companyId,
                        'document_instance',
                        (int) $instance->id,
                        (int) $document->id,
                        false,
                        'Linked library document belongs to another company.',
                    ));
                }

                if ($instance->employee_id !== null && (int) $document->employee_id !== (int) $instance->employee_id) {
                    $result->add($this->issue(
                        'instance_employee_document_wrong_employee',
                        DocumentIntegrityIssueSeverity::High,
                        $companyId,
                        'document_instance',
                        (int) $instance->id,
                        (int) $document->id,
                        false,
                        'Linked library document belongs to a different employee.',
                    ));
                }

                if ($verifyFiles && (int) $document->company_id === $companyId) {
                    $this->inspectLibraryFile($document, $result);
                }
            }
        }

        $templateVersionId = (int) $instance->document_generation_template_version_id;
        $templateVersion = $templateVersions->get($templateVersionId);

        if (! $templateVersion instanceof DocumentGenerationTemplateVersion) {
            $result->add($this->issue(
                'instance_template_version_missing',
                DocumentIntegrityIssueSeverity::High,
                $companyId,
                'document_instance',
                (int) $instance->id,
                $templateVersionId,
                false,
                'Referenced generation template version is missing.',
            ));
        } elseif ((int) $templateVersion->company_id !== $companyId) {
            $result->add($this->issue(
                'instance_template_version_cross_company',
                DocumentIntegrityIssueSeverity::Critical,
                $companyId,
                'document_instance',
                (int) $instance->id,
                (int) $templateVersion->id,
                false,
                'Generation template version belongs to another company.',
            ));
        }
    }

    private function inspectVersions(int $companyId, bool $verifyFiles, DocumentIntegrityAuditResult $result): void
    {
        DocumentInstanceVersion::query()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $versions) use ($companyId, $verifyFiles, $result): void {
                /** @var Collection<int, DocumentInstanceVersion> $versions */
                $instanceIds = $versions->pluck('document_instance_id')->map(fn ($id): int => (int) $id)->unique()->all();
                $instances = $instanceIds === []
                    ? collect()
                    : DocumentInstance::query()->whereIn('id', $instanceIds)->get()->keyBy('id');

                $duplicateGroups = $versions
                    ->groupBy(fn (DocumentInstanceVersion $version): string => $version->document_instance_id.':'.$version->version)
                    ->filter(fn (Collection $group): bool => $group->count() > 1);

                foreach ($duplicateGroups as $group) {
                    /** @var DocumentInstanceVersion $first */
                    $first = $group->first();
                    $result->add($this->issue(
                        'version_number_duplicate',
                        DocumentIntegrityIssueSeverity::High,
                        $companyId,
                        'document_instance',
                        (int) $first->document_instance_id,
                        (int) $first->id,
                        false,
                        'Duplicate version number exists for a document instance.',
                    ));
                }

                foreach ($versions as $version) {
                    if ((int) $version->version <= 0) {
                        $result->add($this->issue(
                            'version_number_invalid',
                            DocumentIntegrityIssueSeverity::High,
                            $companyId,
                            'document_instance_version',
                            (int) $version->id,
                            null,
                            false,
                            'Version number is not positive.',
                        ));
                    }

                    $instance = $instances->get((int) $version->document_instance_id);

                    if (! $instance instanceof DocumentInstance) {
                        $result->add($this->issue(
                            'version_instance_missing',
                            DocumentIntegrityIssueSeverity::High,
                            $companyId,
                            'document_instance_version',
                            (int) $version->id,
                            (int) $version->document_instance_id,
                            false,
                            'Version is not attached to an existing document instance.',
                        ));
                    } elseif ((int) $instance->company_id !== $companyId) {
                        $result->add($this->issue(
                            'version_company_mismatch',
                            DocumentIntegrityIssueSeverity::Critical,
                            $companyId,
                            'document_instance_version',
                            (int) $version->id,
                            (int) $instance->id,
                            false,
                            'Version company does not match its document instance.',
                        ));
                    }

                    if ($verifyFiles) {
                        $this->inspectCanonicalFile($version, $result);
                    }
                }
            });
    }

    private function inspectWorkflows(int $companyId, DocumentIntegrityAuditResult $result): void
    {
        DocumentWorkflowRequest::query()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $requests) use ($companyId, $result): void {
                /** @var Collection<int, DocumentWorkflowRequest> $requests */
                $requestIds = $requests->pluck('id')->map(fn ($id): int => (int) $id)->all();
                $instanceIds = $requests->pluck('document_instance_id')->map(fn ($id): int => (int) $id)->unique()->all();
                $versionIds = $requests->pluck('document_instance_version_id')->filter()->map(fn ($id): int => (int) $id)->unique()->all();
                $presetIds = $requests->pluck('document_workflow_preset_id')->filter()->map(fn ($id): int => (int) $id)->unique()->all();

                $instances = $instanceIds === []
                    ? collect()
                    : DocumentInstance::query()->whereIn('id', $instanceIds)->get()->keyBy('id');
                $versions = $versionIds === []
                    ? collect()
                    : DocumentInstanceVersion::query()->whereIn('id', $versionIds)->get()->keyBy('id');
                $presets = $presetIds === []
                    ? collect()
                    : DocumentWorkflowPreset::query()->whereIn('id', $presetIds)->get()->keyBy('id');
                $stages = DocumentWorkflowStage::query()
                    ->whereIn('document_workflow_request_id', $requestIds)
                    ->get()
                    ->groupBy('document_workflow_request_id');
                $stageIds = $stages->flatten()->pluck('id')->map(fn ($id): int => (int) $id)->all();
                $tasks = $stageIds === []
                    ? collect()
                    : DocumentWorkflowTask::query()->whereIn('document_workflow_stage_id', $stageIds)->get()->groupBy('document_workflow_stage_id');

                $actionableAssigneeIds = [];
                foreach ($requests as $accessProbeRequest) {
                    if ($accessProbeRequest->status !== DocumentWorkflowRequestStatus::Pending) {
                        continue;
                    }

                    foreach ($stages->get($accessProbeRequest->id, collect()) as $accessProbeStage) {
                        /** @var DocumentWorkflowStage $accessProbeStage */
                        if ($accessProbeStage->status !== DocumentWorkflowStageStatus::Active) {
                            continue;
                        }

                        foreach ($tasks->get($accessProbeStage->id, collect()) as $accessProbeTask) {
                            /** @var DocumentWorkflowTask $accessProbeTask */
                            if ($accessProbeTask->status !== DocumentWorkflowTaskStatus::Pending) {
                                continue;
                            }

                            if ($accessProbeTask->assignee_user_id !== null) {
                                $actionableAssigneeIds[] = (int) $accessProbeTask->assignee_user_id;
                            }
                        }
                    }
                }

                $actionableAssigneeIds = array_values(array_unique($actionableAssigneeIds));
                $assigneeAccess = $actionableAssigneeIds === []
                    ? []
                    : $this->companyAccess->accessibleMembershipByUserId($companyId, $actionableAssigneeIds);

                foreach ($requests as $request) {
                    $instance = $instances->get((int) $request->document_instance_id);

                    if (! $instance instanceof DocumentInstance) {
                        $result->add($this->issue(
                            'workflow_instance_missing',
                            DocumentIntegrityIssueSeverity::High,
                            $companyId,
                            'document_workflow_request',
                            (int) $request->id,
                            (int) $request->document_instance_id,
                            false,
                            'Workflow is not attached to an existing document instance.',
                        ));
                    } elseif ((int) $instance->company_id !== $companyId) {
                        $result->add($this->issue(
                            'workflow_company_mismatch',
                            DocumentIntegrityIssueSeverity::Critical,
                            $companyId,
                            'document_workflow_request',
                            (int) $request->id,
                            (int) $instance->id,
                            false,
                            'Workflow company does not match its document instance.',
                        ));
                    }

                    $boundVersionId = $request->document_instance_version_id !== null
                        ? (int) $request->document_instance_version_id
                        : null;

                    if ($boundVersionId !== null) {
                        $boundVersion = $versions->get($boundVersionId);

                        if (! $boundVersion instanceof DocumentInstanceVersion) {
                            $result->add($this->issue(
                                'workflow_version_missing',
                                DocumentIntegrityIssueSeverity::High,
                                $companyId,
                                'document_workflow_request',
                                (int) $request->id,
                                $boundVersionId,
                                false,
                                'Workflow bound version is missing.',
                            ));
                        } else {
                            if ($instance instanceof DocumentInstance
                                && (int) $boundVersion->document_instance_id !== (int) $instance->id) {
                                $result->add($this->issue(
                                    'workflow_version_wrong_instance',
                                    DocumentIntegrityIssueSeverity::Critical,
                                    $companyId,
                                    'document_workflow_request',
                                    (int) $request->id,
                                    (int) $boundVersion->id,
                                    false,
                                    'Workflow bound version belongs to another document instance.',
                                ));
                            }

                            if ((int) $boundVersion->company_id !== $companyId) {
                                $result->add($this->issue(
                                    'workflow_version_cross_company',
                                    DocumentIntegrityIssueSeverity::Critical,
                                    $companyId,
                                    'document_workflow_request',
                                    (int) $request->id,
                                    (int) $boundVersion->id,
                                    false,
                                    'Workflow bound version belongs to another company.',
                                ));
                            }
                        }
                    }

                    $presetId = $request->document_workflow_preset_id !== null
                        ? (int) $request->document_workflow_preset_id
                        : null;

                    if ($presetId !== null) {
                        $preset = $presets->get($presetId);

                        if ($preset instanceof DocumentWorkflowPreset && (int) $preset->company_id !== $companyId) {
                            $result->add($this->issue(
                                'workflow_preset_cross_company',
                                DocumentIntegrityIssueSeverity::Critical,
                                $companyId,
                                'document_workflow_request',
                                (int) $request->id,
                                $presetId,
                                false,
                                'Workflow preset provenance belongs to another company.',
                            ));
                        }
                    }

                    $requestStages = $stages->get($request->id, collect());
                    $activeStages = 0;
                    $pendingStages = 0;

                    foreach ($requestStages as $stage) {
                        /** @var DocumentWorkflowStage $stage */
                        if ((int) $stage->company_id !== $companyId
                            || (int) $stage->document_workflow_request_id !== (int) $request->id) {
                            $result->add($this->issue(
                                'workflow_stage_mismatch',
                                DocumentIntegrityIssueSeverity::Critical,
                                $companyId,
                                'document_workflow_stage',
                                (int) $stage->id,
                                (int) $request->id,
                                false,
                                'Workflow stage does not belong to this request and company.',
                            ));
                        }

                        if ($stage->status === DocumentWorkflowStageStatus::Active) {
                            $activeStages++;
                        }

                        if ($stage->status === DocumentWorkflowStageStatus::Pending) {
                            $pendingStages++;
                        }

                        $stageTasks = $tasks->get($stage->id, collect());

                        foreach ($stageTasks as $task) {
                            /** @var DocumentWorkflowTask $task */
                            if ((int) $task->company_id !== $companyId
                                || (int) $task->document_workflow_stage_id !== (int) $stage->id) {
                                $result->add($this->issue(
                                    'workflow_task_mismatch',
                                    DocumentIntegrityIssueSeverity::Critical,
                                    $companyId,
                                    'document_workflow_task',
                                    (int) $task->id,
                                    (int) $stage->id,
                                    false,
                                    'Workflow task does not belong to this stage and company.',
                                ));
                            }

                            $assigneeId = $task->assignee_user_id !== null ? (int) $task->assignee_user_id : null;

                            $taskIsActionable = $request->status === DocumentWorkflowRequestStatus::Pending
                                && $stage->status === DocumentWorkflowStageStatus::Active
                                && $task->status === DocumentWorkflowTaskStatus::Pending;

                            if ($taskIsActionable
                                && ($assigneeId === null
                                    || ($assigneeAccess[$assigneeId] ?? false) !== true)) {
                                $result->add($this->issue(
                                    'workflow_task_assignee_unavailable',
                                    DocumentIntegrityIssueSeverity::High,
                                    $companyId,
                                    'document_workflow_task',
                                    (int) $task->id,
                                    $assigneeId,
                                    false,
                                    'Actionable workflow task has no currently available assignee.',
                                ));
                            }
                        }
                    }

                    if ($request->status === DocumentWorkflowRequestStatus::Pending
                        && $requestStages->isNotEmpty()
                        && $activeStages === 0
                        && $pendingStages > 0) {
                        $result->add($this->issue(
                            'workflow_status_contradiction',
                            DocumentIntegrityIssueSeverity::High,
                            $companyId,
                            'document_workflow_request',
                            (int) $request->id,
                            null,
                            false,
                            'Pending workflow has no active stage.',
                        ));
                    }

                    if ($request->status->isTerminal() && $activeStages > 0) {
                        $result->add($this->issue(
                            'workflow_status_contradiction',
                            DocumentIntegrityIssueSeverity::High,
                            $companyId,
                            'document_workflow_request',
                            (int) $request->id,
                            null,
                            false,
                            'Terminal workflow still has an active stage.',
                        ));
                    }
                }
            });
    }

    private function inspectLifecycles(int $companyId, DocumentIntegrityAuditResult $result): void
    {
        DocumentLifecycleAutomation::query()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $lifecycles) use ($companyId, $result): void {
                /** @var Collection<int, DocumentLifecycleAutomation> $lifecycles */
                $instanceIds = $lifecycles->pluck('document_instance_id')->map(fn ($id): int => (int) $id)->unique()->all();
                $sourceIds = $lifecycles->pluck('source_document_instance_version_id')->map(fn ($id): int => (int) $id)->unique()->all();
                $templateVersionIds = $lifecycles->pluck('document_generation_template_version_id')->map(fn ($id): int => (int) $id)->unique()->all();
                $workflowIds = $lifecycles->pluck('document_workflow_request_id')->filter()->map(fn ($id): int => (int) $id)->unique()->all();
                $flowIds = $lifecycles->pluck('document_signing_flow_id')->filter()->map(fn ($id): int => (int) $id)->unique()->all();

                $instances = $instanceIds === []
                    ? collect()
                    : DocumentInstance::query()->whereIn('id', $instanceIds)->get()->keyBy('id');
                $sources = $sourceIds === []
                    ? collect()
                    : DocumentInstanceVersion::query()->whereIn('id', $sourceIds)->get()->keyBy('id');
                $templateVersions = $templateVersionIds === []
                    ? collect()
                    : DocumentGenerationTemplateVersion::query()->whereIn('id', $templateVersionIds)->get()->keyBy('id');
                $workflows = $workflowIds === []
                    ? collect()
                    : DocumentWorkflowRequest::query()->whereIn('id', $workflowIds)->get()->keyBy('id');
                $flows = $flowIds === []
                    ? collect()
                    : DocumentSigningFlow::query()->whereIn('id', $flowIds)->get()->keyBy('id');

                foreach ($lifecycles as $lifecycle) {
                    $instance = $instances->get((int) $lifecycle->document_instance_id);

                    if (! $instance instanceof DocumentInstance) {
                        $result->add($this->issue(
                            'lifecycle_instance_missing',
                            DocumentIntegrityIssueSeverity::High,
                            $companyId,
                            'document_lifecycle_automation',
                            (int) $lifecycle->id,
                            (int) $lifecycle->document_instance_id,
                            false,
                            'Lifecycle is not attached to an existing document instance.',
                        ));
                    } elseif ((int) $instance->company_id !== $companyId) {
                        $result->add($this->issue(
                            'lifecycle_company_mismatch',
                            DocumentIntegrityIssueSeverity::Critical,
                            $companyId,
                            'document_lifecycle_automation',
                            (int) $lifecycle->id,
                            (int) $instance->id,
                            false,
                            'Lifecycle company does not match its document instance.',
                        ));
                    }

                    $source = $sources->get((int) $lifecycle->source_document_instance_version_id);

                    if (! $source instanceof DocumentInstanceVersion) {
                        $result->add($this->issue(
                            'lifecycle_source_version_missing',
                            DocumentIntegrityIssueSeverity::High,
                            $companyId,
                            'document_lifecycle_automation',
                            (int) $lifecycle->id,
                            (int) $lifecycle->source_document_instance_version_id,
                            false,
                            'Lifecycle source version is missing.',
                        ));
                    } else {
                        if ((int) $source->company_id !== $companyId) {
                            $result->add($this->issue(
                                'lifecycle_source_version_cross_company',
                                DocumentIntegrityIssueSeverity::Critical,
                                $companyId,
                                'document_lifecycle_automation',
                                (int) $lifecycle->id,
                                (int) $source->id,
                                false,
                                'Lifecycle source version belongs to another company.',
                            ));
                        }

                        if ($instance instanceof DocumentInstance
                            && (int) $source->document_instance_id !== (int) $instance->id) {
                            $result->add($this->issue(
                                'lifecycle_source_version_mismatch',
                                DocumentIntegrityIssueSeverity::High,
                                $companyId,
                                'document_lifecycle_automation',
                                (int) $lifecycle->id,
                                (int) $source->id,
                                false,
                                'Lifecycle source version does not belong to the same document instance.',
                            ));
                        }
                    }

                    $templateVersion = $templateVersions->get((int) $lifecycle->document_generation_template_version_id);

                    if ($templateVersion instanceof DocumentGenerationTemplateVersion
                        && (int) $templateVersion->company_id !== $companyId) {
                        $result->add($this->issue(
                            'lifecycle_template_version_cross_company',
                            DocumentIntegrityIssueSeverity::Critical,
                            $companyId,
                            'document_lifecycle_automation',
                            (int) $lifecycle->id,
                            (int) $templateVersion->id,
                            false,
                            'Lifecycle template version belongs to another company.',
                        ));
                    }

                    $workflowId = $lifecycle->document_workflow_request_id !== null
                        ? (int) $lifecycle->document_workflow_request_id
                        : null;

                    if ($workflowId !== null) {
                        $workflow = $workflows->get($workflowId);

                        if (! $workflow instanceof DocumentWorkflowRequest) {
                            $result->add($this->issue(
                                'lifecycle_workflow_missing',
                                DocumentIntegrityIssueSeverity::High,
                                $companyId,
                                'document_lifecycle_automation',
                                (int) $lifecycle->id,
                                $workflowId,
                                false,
                                'Linked workflow request is missing.',
                            ));
                        } else {
                            if ((int) $workflow->company_id !== $companyId
                                || ($instance instanceof DocumentInstance
                                    && (int) $workflow->document_instance_id !== (int) $instance->id)) {
                                $result->add($this->issue(
                                    'lifecycle_workflow_mismatch',
                                    DocumentIntegrityIssueSeverity::Critical,
                                    $companyId,
                                    'document_lifecycle_automation',
                                    (int) $lifecycle->id,
                                    (int) $workflow->id,
                                    false,
                                    'Linked workflow does not belong to the same instance and company.',
                                ));
                            }

                            $isActiveReview = $lifecycle->status === DocumentLifecycleAutomationStatus::Active
                                && $lifecycle->stage === DocumentLifecycleAutomationStage::Review;

                            if ($isActiveReview && $workflow->status->isTerminal()) {
                                $result->add($this->issue(
                                    'lifecycle_stale_workflow_state',
                                    DocumentIntegrityIssueSeverity::Warning,
                                    $companyId,
                                    'document_lifecycle_automation',
                                    (int) $lifecycle->id,
                                    (int) $workflow->id,
                                    true,
                                    'Lifecycle review state is stale relative to a terminal workflow.',
                                ));
                            }
                        }
                    }

                    $flowId = $lifecycle->document_signing_flow_id !== null
                        ? (int) $lifecycle->document_signing_flow_id
                        : null;

                    if ($flowId !== null) {
                        $flow = $flows->get($flowId);

                        if (! $flow instanceof DocumentSigningFlow) {
                            $result->add($this->issue(
                                'lifecycle_signing_flow_missing',
                                DocumentIntegrityIssueSeverity::High,
                                $companyId,
                                'document_lifecycle_automation',
                                (int) $lifecycle->id,
                                $flowId,
                                false,
                                'Linked signing flow is missing.',
                            ));
                        } else {
                            if ((int) $flow->company_id !== $companyId
                                || ($instance instanceof DocumentInstance
                                    && (int) $flow->document_instance_id !== (int) $instance->id)) {
                                $result->add($this->issue(
                                    'lifecycle_signing_flow_mismatch',
                                    DocumentIntegrityIssueSeverity::Critical,
                                    $companyId,
                                    'document_lifecycle_automation',
                                    (int) $lifecycle->id,
                                    (int) $flow->id,
                                    false,
                                    'Linked signing flow does not belong to the same instance and company.',
                                ));
                            }

                            $isOpenLifecycle = in_array($lifecycle->status, [
                                DocumentLifecycleAutomationStatus::Active,
                                DocumentLifecycleAutomationStatus::Blocked,
                            ], true) && $lifecycle->stage === DocumentLifecycleAutomationStage::Signing;

                            $stale = $isOpenLifecycle && (
                                in_array($flow->status, [
                                    DocumentSigningFlowStatus::Completed,
                                    DocumentSigningFlowStatus::Cancelled,
                                ], true)
                                || ($flow->status === DocumentSigningFlowStatus::Active
                                    && $lifecycle->status === DocumentLifecycleAutomationStatus::Blocked)
                                || ($flow->status === DocumentSigningFlowStatus::Blocked
                                    && $lifecycle->status === DocumentLifecycleAutomationStatus::Active)
                            );

                            if ($stale) {
                                $result->add($this->issue(
                                    'lifecycle_stale_signing_state',
                                    DocumentIntegrityIssueSeverity::Warning,
                                    $companyId,
                                    'document_lifecycle_automation',
                                    (int) $lifecycle->id,
                                    (int) $flow->id,
                                    true,
                                    'Lifecycle signing state is stale relative to the signing flow.',
                                ));
                            }
                        }
                    }
                }
            });
    }

    private function inspectSigningFlows(int $companyId, DocumentIntegrityAuditResult $result): void
    {
        DocumentSigningFlow::query()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $flows) use ($companyId, $result): void {
                /** @var Collection<int, DocumentSigningFlow> $flows */
                $flowIds = $flows->pluck('id')->map(fn ($id): int => (int) $id)->all();
                $instanceIds = $flows->pluck('document_instance_id')->map(fn ($id): int => (int) $id)->unique()->all();
                $versionIds = $flows->pluck('starting_document_instance_version_id')->map(fn ($id): int => (int) $id)->unique()->all();
                $presetIds = $flows->pluck('document_signing_preset_id')->filter()->map(fn ($id): int => (int) $id)->unique()->all();

                $instances = $instanceIds === []
                    ? collect()
                    : DocumentInstance::query()->whereIn('id', $instanceIds)->get()->keyBy('id');
                $versions = $versionIds === []
                    ? collect()
                    : DocumentInstanceVersion::query()->whereIn('id', $versionIds)->get()->keyBy('id');
                $presets = $presetIds === []
                    ? collect()
                    : DocumentSigningPreset::query()->whereIn('id', $presetIds)->get()->keyBy('id');
                $requests = DocumentRecipientRequest::query()
                    ->whereIn('document_signing_flow_id', $flowIds)
                    ->get()
                    ->groupBy('document_signing_flow_id');

                foreach ($flows as $flow) {
                    $instance = $instances->get((int) $flow->document_instance_id);

                    if (! $instance instanceof DocumentInstance) {
                        $result->add($this->issue(
                            'signing_flow_instance_missing',
                            DocumentIntegrityIssueSeverity::High,
                            $companyId,
                            'document_signing_flow',
                            (int) $flow->id,
                            (int) $flow->document_instance_id,
                            false,
                            'Signing flow is not attached to an existing document instance.',
                        ));
                    } elseif ((int) $instance->company_id !== $companyId) {
                        $result->add($this->issue(
                            'signing_flow_company_mismatch',
                            DocumentIntegrityIssueSeverity::Critical,
                            $companyId,
                            'document_signing_flow',
                            (int) $flow->id,
                            (int) $instance->id,
                            false,
                            'Signing flow company does not match its document instance.',
                        ));
                    }

                    $starting = $versions->get((int) $flow->starting_document_instance_version_id);

                    if (! $starting instanceof DocumentInstanceVersion) {
                        $result->add($this->issue(
                            'signing_flow_starting_version_missing',
                            DocumentIntegrityIssueSeverity::High,
                            $companyId,
                            'document_signing_flow',
                            (int) $flow->id,
                            (int) $flow->starting_document_instance_version_id,
                            false,
                            'Signing flow starting version is missing.',
                        ));
                    } else {
                        if ((int) $starting->company_id !== $companyId) {
                            $result->add($this->issue(
                                'signing_flow_starting_version_cross_company',
                                DocumentIntegrityIssueSeverity::Critical,
                                $companyId,
                                'document_signing_flow',
                                (int) $flow->id,
                                (int) $starting->id,
                                false,
                                'Signing flow starting version belongs to another company.',
                            ));
                        }

                        if ($instance instanceof DocumentInstance
                            && (int) $starting->document_instance_id !== (int) $instance->id) {
                            $result->add($this->issue(
                                'signing_flow_starting_version_mismatch',
                                DocumentIntegrityIssueSeverity::High,
                                $companyId,
                                'document_signing_flow',
                                (int) $flow->id,
                                (int) $starting->id,
                                false,
                                'Signing flow starting version does not belong to the same document instance.',
                            ));
                        }
                    }

                    $presetId = $flow->document_signing_preset_id !== null
                        ? (int) $flow->document_signing_preset_id
                        : null;

                    if ($presetId !== null) {
                        $preset = $presets->get($presetId);

                        if ($preset instanceof DocumentSigningPreset && (int) $preset->company_id !== $companyId) {
                            $result->add($this->issue(
                                'signing_flow_preset_cross_company',
                                DocumentIntegrityIssueSeverity::Critical,
                                $companyId,
                                'document_signing_flow',
                                (int) $flow->id,
                                $presetId,
                                false,
                                'Signing preset provenance belongs to another company.',
                            ));
                        }
                    }

                    $steps = collect($flow->routing_definition_snapshot['steps'] ?? []);
                    $validSequences = $steps
                        ->map(fn (mixed $step): int => (int) (is_array($step) ? ($step['sequence'] ?? 0) : 0))
                        ->filter(fn (int $sequence): bool => $sequence > 0)
                        ->unique()
                        ->values()
                        ->all();

                    $currentSequence = (int) $flow->current_step_sequence;

                    if ($validSequences !== [] && ! in_array($currentSequence, $validSequences, true)) {
                        $result->add($this->issue(
                            'signing_flow_step_sequence_invalid',
                            DocumentIntegrityIssueSeverity::High,
                            $companyId,
                            'document_signing_flow',
                            (int) $flow->id,
                            null,
                            false,
                            'Signing flow current step is not a configured step.',
                        ));
                    }

                    $flowRequests = $requests->get($flow->id, collect());
                    $activeByStep = [];

                    foreach ($flowRequests as $request) {
                        /** @var DocumentRecipientRequest $request */
                        if ((int) $request->company_id !== $companyId) {
                            $result->add($this->issue(
                                'signing_flow_recipient_cross_company',
                                DocumentIntegrityIssueSeverity::Critical,
                                $companyId,
                                'document_recipient_request',
                                (int) $request->id,
                                (int) $flow->id,
                                false,
                                'Signing-flow recipient request belongs to another company.',
                            ));
                        }

                        $stepSequence = $request->signing_step_sequence !== null
                            ? (int) $request->signing_step_sequence
                            : null;

                        if ($stepSequence !== null
                            && $validSequences !== []
                            && ! in_array($stepSequence, $validSequences, true)) {
                            $result->add($this->issue(
                                'signing_flow_recipient_step_invalid',
                                DocumentIntegrityIssueSeverity::High,
                                $companyId,
                                'document_recipient_request',
                                (int) $request->id,
                                (int) $flow->id,
                                false,
                                'Recipient request step does not match a configured signing step.',
                            ));
                        }

                        if ($request->status === DocumentRecipientRequestStatus::AwaitingAction
                            && $stepSequence !== null
                            && $stepSequence === $currentSequence) {
                            $activeByStep[$stepSequence] = ($activeByStep[$stepSequence] ?? 0) + 1;
                        }
                    }

                    foreach ($activeByStep as $count) {
                        if ($count > 1) {
                            $result->add($this->issue(
                                'signing_flow_duplicate_active_step',
                                DocumentIntegrityIssueSeverity::High,
                                $companyId,
                                'document_signing_flow',
                                (int) $flow->id,
                                null,
                                false,
                                'Current signing step has more than one active recipient request.',
                            ));
                        }
                    }
                }
            });
    }

    private function inspectRecipientRequests(int $companyId, DocumentIntegrityAuditResult $result): void
    {
        DocumentRecipientRequest::query()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $requests) use ($companyId, $result): void {
                /** @var Collection<int, DocumentRecipientRequest> $requests */
                $instanceIds = $requests->pluck('document_instance_id')->map(fn ($id): int => (int) $id)->unique()->all();
                $sourceIds = $requests->pluck('source_document_instance_version_id')->map(fn ($id): int => (int) $id)->unique()->all();
                $resultIds = $requests->pluck('result_document_instance_version_id')->filter()->map(fn ($id): int => (int) $id)->unique()->all();
                $flowIds = $requests->pluck('document_signing_flow_id')->filter()->map(fn ($id): int => (int) $id)->unique()->all();
                $employeeIds = $requests->pluck('employee_id')->filter()->map(fn ($id): int => (int) $id)->unique()->all();
                $userIds = $requests
                    ->filter(fn (DocumentRecipientRequest $request): bool => $request->recipient_type === DocumentRecipientType::CompanyUser
                        && $request->status === DocumentRecipientRequestStatus::AwaitingAction)
                    ->pluck('recipient_user_id')
                    ->filter()
                    ->map(fn ($id): int => (int) $id)
                    ->unique()
                    ->values()
                    ->all();

                $instances = $instanceIds === []
                    ? collect()
                    : DocumentInstance::query()->whereIn('id', $instanceIds)->get()->keyBy('id');
                $sources = $sourceIds === []
                    ? collect()
                    : DocumentInstanceVersion::query()->whereIn('id', $sourceIds)->get()->keyBy('id');
                $resultVersions = $resultIds === []
                    ? collect()
                    : DocumentInstanceVersion::query()->whereIn('id', $resultIds)->get()->keyBy('id');
                $flows = $flowIds === []
                    ? collect()
                    : DocumentSigningFlow::query()->whereIn('id', $flowIds)->get()->keyBy('id');
                $employees = $employeeIds === []
                    ? collect()
                    : Employee::query()->whereIn('id', $employeeIds)->get()->keyBy('id');
                $userAccess = $userIds === []
                    ? []
                    : $this->companyAccess->accessibleMembershipByUserId($companyId, $userIds);

                foreach ($requests as $request) {
                    $instance = $instances->get((int) $request->document_instance_id);

                    if (! $instance instanceof DocumentInstance) {
                        $result->add($this->issue(
                            'recipient_instance_missing',
                            DocumentIntegrityIssueSeverity::High,
                            $companyId,
                            'document_recipient_request',
                            (int) $request->id,
                            (int) $request->document_instance_id,
                            false,
                            'Recipient request is not attached to an existing document instance.',
                        ));
                    } elseif ((int) $instance->company_id !== $companyId) {
                        $result->add($this->issue(
                            'recipient_company_mismatch',
                            DocumentIntegrityIssueSeverity::Critical,
                            $companyId,
                            'document_recipient_request',
                            (int) $request->id,
                            (int) $instance->id,
                            false,
                            'Recipient request company does not match its document instance.',
                        ));
                    }

                    $source = $sources->get((int) $request->source_document_instance_version_id);

                    if (! $source instanceof DocumentInstanceVersion) {
                        $result->add($this->issue(
                            'recipient_source_version_missing',
                            DocumentIntegrityIssueSeverity::High,
                            $companyId,
                            'document_recipient_request',
                            (int) $request->id,
                            (int) $request->source_document_instance_version_id,
                            false,
                            'Recipient request source version is missing.',
                        ));
                    } else {
                        if ((int) $source->company_id !== $companyId) {
                            $result->add($this->issue(
                                'recipient_source_version_cross_company',
                                DocumentIntegrityIssueSeverity::Critical,
                                $companyId,
                                'document_recipient_request',
                                (int) $request->id,
                                (int) $source->id,
                                false,
                                'Recipient request source version belongs to another company.',
                            ));
                        }

                        if ($instance instanceof DocumentInstance
                            && (int) $source->document_instance_id !== (int) $instance->id) {
                            $result->add($this->issue(
                                'recipient_source_version_mismatch',
                                DocumentIntegrityIssueSeverity::High,
                                $companyId,
                                'document_recipient_request',
                                (int) $request->id,
                                (int) $source->id,
                                false,
                                'Recipient request source version does not belong to the same document instance.',
                            ));
                        }
                    }

                    $flowId = $request->document_signing_flow_id !== null
                        ? (int) $request->document_signing_flow_id
                        : null;

                    if ($flowId !== null) {
                        $flow = $flows->get($flowId);

                        if (! $flow instanceof DocumentSigningFlow) {
                            $result->add($this->issue(
                                'recipient_signing_flow_missing',
                                DocumentIntegrityIssueSeverity::High,
                                $companyId,
                                'document_recipient_request',
                                (int) $request->id,
                                $flowId,
                                false,
                                'Linked signing flow is missing.',
                            ));
                        } elseif ((int) $flow->company_id !== $companyId
                            || ($instance instanceof DocumentInstance
                                && (int) $flow->document_instance_id !== (int) $instance->id)) {
                            $result->add($this->issue(
                                'recipient_signing_flow_mismatch',
                                DocumentIntegrityIssueSeverity::Critical,
                                $companyId,
                                'document_recipient_request',
                                (int) $request->id,
                                (int) $flow->id,
                                false,
                                'Linked signing flow does not belong to the same instance and company.',
                            ));
                        }
                    }

                    $employee = $employees->get((int) $request->employee_id);

                    if ($employee instanceof Employee && (int) $employee->company_id !== $companyId) {
                        $result->add($this->issue(
                            'recipient_employee_cross_company',
                            DocumentIntegrityIssueSeverity::Critical,
                            $companyId,
                            'document_recipient_request',
                            (int) $request->id,
                            (int) $employee->id,
                            false,
                            'Recipient request employee belongs to another company.',
                        ));
                    } elseif ($employee instanceof Employee
                        && $instance instanceof DocumentInstance
                        && $instance->employee_id !== null
                        && (int) $request->employee_id !== (int) $instance->employee_id) {
                        $result->add($this->issue(
                            'recipient_employee_mismatch',
                            DocumentIntegrityIssueSeverity::High,
                            $companyId,
                            'document_recipient_request',
                            (int) $request->id,
                            (int) $employee->id,
                            false,
                            'Recipient request employee does not match the document instance subject employee.',
                        ));
                    }

                    if ($request->recipient_type === DocumentRecipientType::CompanyUser
                        && $request->status === DocumentRecipientRequestStatus::AwaitingAction) {
                        $userId = $request->recipient_user_id !== null ? (int) $request->recipient_user_id : null;

                        if ($userId === null || ($userAccess[$userId] ?? false) !== true) {
                            $result->add($this->issue(
                                'recipient_internal_assignee_unavailable',
                                DocumentIntegrityIssueSeverity::High,
                                $companyId,
                                'document_recipient_request',
                                (int) $request->id,
                                $userId,
                                false,
                                'Actionable internal recipient does not currently have company access.',
                            ));
                        }
                    }

                    $tokenHash = (string) $request->token_hash;

                    if ($request->isPublicTokenRecipient()
                        && $request->status === DocumentRecipientRequestStatus::AwaitingAction
                        && ($tokenHash === '' || ! preg_match('/^[a-f0-9]{64}$/', $tokenHash))) {
                        $result->add($this->issue(
                            'recipient_missing_token_hash',
                            DocumentIntegrityIssueSeverity::High,
                            $companyId,
                            'document_recipient_request',
                            (int) $request->id,
                            null,
                            false,
                            'Actionable public request is missing a hashed token.',
                        ));
                    }

                    if ($request->status === DocumentRecipientRequestStatus::Completed
                        && $request->action === DocumentRecipientAction::Sign) {
                        $resultVersionId = $request->result_document_instance_version_id !== null
                            ? (int) $request->result_document_instance_version_id
                            : null;

                        if ($resultVersionId === null) {
                            $result->add($this->issue(
                                'recipient_sign_completed_missing_result',
                                DocumentIntegrityIssueSeverity::High,
                                $companyId,
                                'document_recipient_request',
                                (int) $request->id,
                                null,
                                false,
                                'Completed SIGN request has no result version.',
                            ));
                        } else {
                            $resultVersion = $resultVersions->get($resultVersionId);

                            if (! $resultVersion instanceof DocumentInstanceVersion) {
                                $result->add($this->issue(
                                    'recipient_sign_result_missing',
                                    DocumentIntegrityIssueSeverity::High,
                                    $companyId,
                                    'document_recipient_request',
                                    (int) $request->id,
                                    $resultVersionId,
                                    false,
                                    'Completed SIGN result version is missing.',
                                ));
                            } else {
                                if ((int) $resultVersion->company_id !== $companyId) {
                                    $result->add($this->issue(
                                        'recipient_sign_result_cross_company',
                                        DocumentIntegrityIssueSeverity::Critical,
                                        $companyId,
                                        'document_recipient_request',
                                        (int) $request->id,
                                        (int) $resultVersion->id,
                                        false,
                                        'SIGN result version belongs to another company.',
                                    ));
                                }

                                if ($instance instanceof DocumentInstance
                                    && (int) $resultVersion->document_instance_id !== (int) $instance->id) {
                                    $result->add($this->issue(
                                        'recipient_sign_result_wrong_instance',
                                        DocumentIntegrityIssueSeverity::Critical,
                                        $companyId,
                                        'document_recipient_request',
                                        (int) $request->id,
                                        (int) $resultVersion->id,
                                        false,
                                        'SIGN result version belongs to another document instance.',
                                    ));
                                }

                                if ($source instanceof DocumentInstanceVersion
                                    && (int) $resultVersion->version <= (int) $source->version) {
                                    $result->add($this->issue(
                                        'recipient_sign_result_not_later',
                                        DocumentIntegrityIssueSeverity::High,
                                        $companyId,
                                        'document_recipient_request',
                                        (int) $request->id,
                                        (int) $resultVersion->id,
                                        false,
                                        'SIGN result version is not later than the source version.',
                                    ));
                                }
                            }
                        }
                    }

                    if ($request->status === DocumentRecipientRequestStatus::Completed
                        && $request->action === DocumentRecipientAction::Acknowledge
                        && $request->result_document_instance_version_id !== null) {
                        $result->add($this->issue(
                            'recipient_ack_completed_has_result',
                            DocumentIntegrityIssueSeverity::High,
                            $companyId,
                            'document_recipient_request',
                            (int) $request->id,
                            (int) $request->result_document_instance_version_id,
                            false,
                            'Completed ACK request has a result version.',
                        ));
                    }

                    if ($request->status->isTerminal() && $request->next_reminder_at !== null) {
                        $result->add($this->issue(
                            'recipient_terminal_has_reminder',
                            DocumentIntegrityIssueSeverity::Warning,
                            $companyId,
                            'document_recipient_request',
                            (int) $request->id,
                            null,
                            true,
                            'Terminal recipient request still has a reminder pointer.',
                        ));
                    }

                    if ($request->status === DocumentRecipientRequestStatus::AwaitingAction
                        && $request->expires_at === null) {
                        $result->add($this->issue(
                            'recipient_awaiting_missing_expiry',
                            DocumentIntegrityIssueSeverity::High,
                            $companyId,
                            'document_recipient_request',
                            (int) $request->id,
                            null,
                            false,
                            'Awaiting recipient request is missing an expiry.',
                        ));
                    }
                }
            });
    }

    private function inspectDeliveries(int $companyId, DocumentIntegrityAuditResult $result): void
    {
        DocumentRecipientRequestDelivery::query()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $deliveries) use ($companyId, $result): void {
                /** @var Collection<int, DocumentRecipientRequestDelivery> $deliveries */
                $requestIds = $deliveries->pluck('document_recipient_request_id')->map(fn ($id): int => (int) $id)->unique()->all();
                $requests = $requestIds === []
                    ? collect()
                    : DocumentRecipientRequest::query()->whereIn('id', $requestIds)->get()->keyBy('id');

                foreach ($deliveries as $delivery) {
                    $request = $requests->get((int) $delivery->document_recipient_request_id);

                    if (! $request instanceof DocumentRecipientRequest) {
                        $result->add($this->issue(
                            'delivery_request_missing',
                            DocumentIntegrityIssueSeverity::High,
                            $companyId,
                            'document_recipient_request_delivery',
                            (int) $delivery->id,
                            (int) $delivery->document_recipient_request_id,
                            false,
                            'Delivery ledger row is not attached to an existing recipient request.',
                        ));

                        continue;
                    }

                    if ((int) $request->company_id !== $companyId) {
                        $result->add($this->issue(
                            'delivery_request_cross_company',
                            DocumentIntegrityIssueSeverity::Critical,
                            $companyId,
                            'document_recipient_request_delivery',
                            (int) $delivery->id,
                            (int) $request->id,
                            false,
                            'Delivery ledger row belongs to another company from its request.',
                        ));
                    }

                    $isQueuedReminder = $delivery->purpose === DocumentRecipientRequestDeliveryPurpose::Reminder
                        && $delivery->status === DocumentRecipientRequestDeliveryStatus::Queued
                        && $delivery->revoked_at === null;

                    if ($request->status->isTerminal() && $isQueuedReminder) {
                        $result->add($this->issue(
                            'delivery_terminal_queued_reminder',
                            DocumentIntegrityIssueSeverity::Warning,
                            $companyId,
                            'document_recipient_request_delivery',
                            (int) $delivery->id,
                            (int) $request->id,
                            false,
                            'Terminal recipient request still has a queued reminder delivery.',
                        ));
                    }
                }
            });
    }

    private function inspectCanonicalFile(DocumentInstanceVersion $version, DocumentIntegrityAuditResult $result): void
    {
        $companyId = (int) $version->company_id;
        $exists = DocumentInstanceStorage::exists($version->file_path, $companyId);

        if (! $exists) {
            $result->add($this->issue(
                'file_canonical_missing',
                DocumentIntegrityIssueSeverity::High,
                $companyId,
                'document_instance_version',
                (int) $version->id,
                (int) $version->document_instance_id,
                false,
                'Canonical version file is missing.',
            ));

            return;
        }

        $relativePath = DocumentInstanceStorage::validatedRelativePath($version->file_path, $companyId);

        if ($relativePath === null) {
            return;
        }

        $this->inspectStoredFileMetadata(
            DocumentInstanceStorage::DISK,
            $relativePath,
            (int) $version->size_bytes,
            (string) $version->checksum,
            $companyId,
            'document_instance_version',
            (int) $version->id,
            (int) $version->document_instance_id,
            'file_canonical_size_mismatch',
            'file_canonical_checksum_mismatch',
            $result,
        );
    }

    private function inspectLibraryFile(EmployeeDocument $document, DocumentIntegrityAuditResult $result): void
    {
        $companyId = (int) $document->company_id;
        $resolved = EmployeePrivateFile::resolve(
            $document->file_path,
            $companyId,
            EmployeePrivateFileKind::Document,
        );

        if ($resolved === null) {
            $result->add($this->issue(
                'file_library_missing',
                DocumentIntegrityIssueSeverity::Warning,
                $companyId,
                'employee_document',
                (int) $document->id,
                null,
                false,
                'Linked library projection file is missing.',
            ));

            return;
        }

        $this->inspectStoredFileMetadata(
            $resolved->disk,
            $resolved->path,
            (int) $document->size_bytes,
            (string) $document->checksum,
            $companyId,
            'employee_document',
            (int) $document->id,
            null,
            'file_library_size_mismatch',
            'file_library_checksum_mismatch',
            $result,
        );
    }

    private function inspectStoredFileMetadata(
        string $disk,
        string $relativePath,
        int $expectedSize,
        string $expectedChecksum,
        int $companyId,
        string $entityType,
        int $entityId,
        ?int $relatedId,
        string $sizeCode,
        string $checksumCode,
        DocumentIntegrityAuditResult $result,
    ): void {
        try {
            $size = Storage::disk($disk)->size($relativePath);
        } catch (Throwable) {
            return;
        }

        if ($expectedSize > 0 && $size !== $expectedSize) {
            $result->add($this->issue(
                $sizeCode,
                DocumentIntegrityIssueSeverity::Warning,
                $companyId,
                $entityType,
                $entityId,
                $relatedId,
                false,
                'Stored file size does not match recorded metadata.',
            ));
        }

        if ($expectedChecksum === '' || ! preg_match('/^[a-f0-9]{64}$/', $expectedChecksum)) {
            return;
        }

        try {
            $absolutePath = Storage::disk($disk)->path($relativePath);
        } catch (Throwable) {
            return;
        }

        if (! is_string($absolutePath) || $absolutePath === '' || ! is_readable($absolutePath)) {
            return;
        }

        $hash = hash_file('sha256', $absolutePath);

        if (! is_string($hash) || $hash === $expectedChecksum) {
            return;
        }

        $result->add($this->issue(
            $checksumCode,
            DocumentIntegrityIssueSeverity::Warning,
            $companyId,
            $entityType,
            $entityId,
            $relatedId,
            false,
            'Stored file checksum does not match recorded metadata.',
        ));
    }

    private function issue(
        string $code,
        DocumentIntegrityIssueSeverity $severity,
        int $companyId,
        string $entityType,
        int $entityId,
        ?int $relatedId,
        bool $repairable,
        string $summary,
    ): DocumentIntegrityIssue {
        return new DocumentIntegrityIssue(
            code: $code,
            severity: $severity,
            companyId: $companyId,
            entityType: $entityType,
            entityId: $entityId,
            relatedId: $relatedId,
            repairable: $repairable,
            summary: $summary,
        );
    }
}
