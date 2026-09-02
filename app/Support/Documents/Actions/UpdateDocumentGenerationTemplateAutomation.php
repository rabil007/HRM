<?php

namespace App\Support\Documents\Actions;

use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\User;
use App\Support\Documents\DocumentTemplateAutomationBindings;
use App\Support\Documents\Lifecycle\DocumentLifecycleAutomationPolicy;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * @deprecated Prefer saving workflow/signing from the Unified PDF Designer Save Draft action.
 * Kept for backward compatibility with the previous After generation endpoint.
 */
final class UpdateDocumentGenerationTemplateAutomation
{
    public function __construct(
        private BranchDocumentGenerationTemplateDraft $branchDraft = new BranchDocumentGenerationTemplateDraft,
        private DocumentLifecycleAutomationPolicy $policy = new DocumentLifecycleAutomationPolicy,
    ) {}

    /**
     * @param  array{document_workflow_preset_id?: int|null, document_signing_preset_id?: int|null}  $bindings
     */
    public function handle(
        DocumentGenerationTemplate $template,
        array $bindings,
        ?User $actor = null,
    ): DocumentGenerationTemplateVersion {
        return DB::transaction(function () use ($template, $bindings, $actor): DocumentGenerationTemplateVersion {
            /** @var DocumentGenerationTemplate $lockedTemplate */
            $lockedTemplate = DocumentGenerationTemplate::query()
                ->whereKey($template->id)
                ->lockForUpdate()
                ->firstOrFail();

            $companyId = (int) $lockedTemplate->company_id;
            $validated = DocumentTemplateAutomationBindings::validateForLegacyAutomationUpdate(
                $lockedTemplate,
                $bindings,
                $companyId,
                $this->policy,
            );

            $draft = $this->branchDraft->handle($lockedTemplate, $actor?->id);

            /** @var DocumentGenerationTemplateVersion $lockedDraft */
            $lockedDraft = DocumentGenerationTemplateVersion::query()
                ->whereKey($draft->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedDraft->assertEditable();

            $lockedDraft->document_workflow_mode = $validated['document_workflow_mode'];
            $lockedDraft->document_workflow_preset_id = $validated['document_workflow_preset_id'];
            $lockedDraft->document_signing_mode = $validated['document_signing_mode'];
            $lockedDraft->document_signing_preset_id = $validated['document_signing_preset_id'];
            $lockedDraft->updated_by = $actor?->id;
            $lockedDraft->save();

            activity('document_templates')
                ->performedOn($lockedTemplate)
                ->causedBy($actor)
                ->tap(function (Activity $activity) use ($companyId): void {
                    $activity->company_id = $companyId;
                })
                ->withProperties([
                    'action' => 'template_automation_updated',
                    'template_id' => $lockedTemplate->id,
                    'version' => $lockedDraft->version,
                    'document_workflow_mode' => $validated['document_workflow_mode']->value,
                    'document_workflow_preset_id' => $validated['document_workflow_preset_id'],
                    'document_signing_mode' => $validated['document_signing_mode']->value,
                    'document_signing_preset_id' => $validated['document_signing_preset_id'],
                    'behavior_summary' => $this->policy->behaviorSummary(
                        $validated['document_workflow_preset_id'],
                        $validated['document_signing_preset_id'],
                    ),
                ])
                ->log("Updated automation bindings for template {$lockedTemplate->name} (v{$lockedDraft->version})");

            return $lockedDraft->fresh() ?? $lockedDraft;
        });
    }
}
