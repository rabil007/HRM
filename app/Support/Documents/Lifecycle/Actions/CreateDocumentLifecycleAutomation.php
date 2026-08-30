<?php

namespace App\Support\Documents\Lifecycle\Actions;

use App\Enums\DocumentLifecycleAutomationStatus;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\DocumentInstance;
use App\Models\DocumentInstanceVersion;
use App\Models\DocumentLifecycleAutomation;
use App\Support\Documents\Lifecycle\DocumentLifecycleAutomationActivityLogger;
use App\Support\Documents\Lifecycle\DocumentLifecycleAutomationPolicy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class CreateDocumentLifecycleAutomation
{
    public function __construct(
        private DocumentLifecycleAutomationPolicy $policy = new DocumentLifecycleAutomationPolicy,
        private DocumentLifecycleAutomationActivityLogger $activityLogger = new DocumentLifecycleAutomationActivityLogger,
    ) {}

    public function handle(
        DocumentInstance $instance,
        DocumentInstanceVersion $sourceVersion,
        DocumentGenerationTemplateVersion $templateVersion,
        ?int $initiatedByUserId,
    ): ?DocumentLifecycleAutomation {
        if (! $this->policy->templateVersionRequiresAutomation($templateVersion)) {
            return null;
        }

        $companyId = (int) $instance->company_id;

        $existing = DocumentLifecycleAutomation::query()
            ->forCompany($companyId)
            ->where('document_instance_id', $instance->id)
            ->first();

        if ($existing instanceof DocumentLifecycleAutomation) {
            return $existing;
        }

        $snapshot = $this->policy->snapshotFromTemplateVersion($templateVersion);

        try {
            $lifecycle = DocumentLifecycleAutomation::query()->create([
                'company_id' => $companyId,
                'document_instance_id' => $instance->id,
                'source_document_instance_version_id' => $sourceVersion->id,
                'document_generation_template_version_id' => $templateVersion->id,
                'document_workflow_preset_id' => $snapshot['workflow_preset_id'],
                'document_signing_preset_id' => $snapshot['signing_preset_id'],
                'policy_snapshot' => $snapshot,
                'status' => DocumentLifecycleAutomationStatus::Pending,
                'stage' => null,
                'initiated_by' => $initiatedByUserId,
            ]);
        } catch (UniqueConstraintViolationException) {
            return DocumentLifecycleAutomation::query()
                ->forCompany($companyId)
                ->where('document_instance_id', $instance->id)
                ->first();
        }

        $lifecycleId = (int) $lifecycle->id;

        DB::afterCommit(function () use ($lifecycleId, $companyId): void {
            try {
                app(StartDocumentLifecycleAutomation::class)->handle($lifecycleId, $companyId);
            } catch (\Throwable $exception) {
                report($exception);
            }
        });

        $this->activityLogger->log(
            description: 'Document lifecycle automation started',
            event: 'document_lifecycle_started',
            lifecycle: $lifecycle,
            metadata: [
                'workflow_preset_id' => $snapshot['workflow_preset_id'],
                'signing_preset_id' => $snapshot['signing_preset_id'],
            ],
        );

        return $lifecycle;
    }
}
