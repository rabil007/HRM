<?php

namespace App\Support\Documents\Actions;

use App\Enums\DocumentGenerationTemplateStatus;
use App\Enums\DocumentGenerationTemplateVersionStatus;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Support\Documents\DocumentGenerationTemplateReadiness;
use App\Support\Documents\DocumentTemplateLayoutPreflightResult;
use App\Support\Documents\DocumentTemplateMergeFields;
use App\Support\Documents\Lifecycle\DocumentLifecycleAutomationPolicy;
use App\Support\Documents\PdfOverlayLayoutPreflight;
use App\Support\Documents\PdfOverlayPlacementValidator;
use App\Support\Documents\RejectInvalidDocumentTemplateLayout;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Spatie\Activitylog\Models\Activity;

final class PublishDocumentGenerationTemplateVersion
{
    public function handle(DocumentGenerationTemplateVersion $version, ?int $userId = null): DocumentGenerationTemplateVersion
    {
        if (! $version->isDraft()) {
            throw new DomainException('Only draft template versions can be published.');
        }

        return DB::transaction(function () use ($version, $userId): DocumentGenerationTemplateVersion {
            /** @var DocumentGenerationTemplate $template */
            $template = DocumentGenerationTemplate::query()
                ->whereKey($version->document_generation_template_id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var DocumentGenerationTemplateVersion $lockedVersion */
            $lockedVersion = DocumentGenerationTemplateVersion::query()
                ->whereKey($version->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedVersion->isDraft()) {
                throw new DomainException('Only draft template versions can be published.');
            }

            if ($template->isPdfOverlay()) {
                if ($lockedVersion->placement_config === null) {
                    $lockedVersion->placement_config = PdfOverlayPlacementValidator::emptyConfig();
                }

                try {
                    PdfOverlayPlacementValidator::validate(
                        $lockedVersion->placement_config,
                        (int) ($lockedVersion->source_pdf_page_count ?? 0),
                    );
                } catch (InvalidArgumentException $exception) {
                    throw new DomainException($exception->getMessage(), 0, $exception);
                }

                $readiness = app(DocumentGenerationTemplateReadiness::class)
                    ->evaluate($lockedVersion, $template);

                $template->loadMissing('company');
                $layout = app(PdfOverlayLayoutPreflight::class)->evaluate(
                    $template,
                    $lockedVersion,
                    (int) $template->company_id,
                    DocumentTemplateMergeFields::sampleValues($template->company?->name),
                    is_array($lockedVersion->placement_config) ? $lockedVersion->placement_config : null,
                    allowDraft: true,
                );

                if (! $layout->valid) {
                    if ($readiness['blocking_count'] > 0) {
                        $this->throwCombinedPublishFailure($readiness, $layout);
                    }

                    RejectInvalidDocumentTemplateLayout::throw($layout);
                }

                app(DocumentGenerationTemplateReadiness::class)->evaluateForPublish($lockedVersion, $template);
            }

            app(DocumentLifecycleAutomationPolicy::class)->assertPublishable($lockedVersion, $template);

            // 1. Archive any previously published versions for this template
            DocumentGenerationTemplateVersion::query()
                ->where('document_generation_template_id', $template->id)
                ->where('status', DocumentGenerationTemplateVersionStatus::Published)
                ->update([
                    'status' => DocumentGenerationTemplateVersionStatus::Archived,
                    'updated_by' => $userId,
                ]);

            // 2. Publish this version
            $lockedVersion->status = DocumentGenerationTemplateVersionStatus::Published;
            $lockedVersion->published_at = now();
            $lockedVersion->updated_by = $userId;
            $lockedVersion->save();

            // 3. Update parent template with new published version pointer and active status
            $template->published_version_id = $lockedVersion->id;
            $template->status = DocumentGenerationTemplateStatus::Active;
            if ($lockedVersion->content !== null) {
                $template->content = $lockedVersion->content;
            }
            $template->updated_by = $userId;
            $template->save();

            $companyId = (int) $template->company_id;
            activity('document_templates')
                ->performedOn($template)
                ->causedBy($userId)
                ->tap(function (Activity $activity) use ($companyId): void {
                    $activity->company_id = $companyId;
                })
                ->withProperties([
                    'action' => 'template_version_published',
                    'template_id' => $template->id,
                    'version' => $lockedVersion->version,
                ])
                ->log("Published version {$lockedVersion->version} for template {$template->name}");

            return $lockedVersion;
        });
    }

    /**
     * @param  array{blocking_count: int, issues: list<array<string, mixed>>}  $readiness
     */
    private function throwCombinedPublishFailure(array $readiness, DocumentTemplateLayoutPreflightResult $layout): never
    {
        $errors = [];

        foreach ($readiness['issues'] as $issue) {
            if (! ($issue['blocking'] ?? false)) {
                continue;
            }

            $field = match ($issue['section'] ?? '') {
                'workflow' => 'document_workflow_mode',
                'signing' => 'document_signing_mode',
                'design' => 'placement_config',
                default => 'version',
            };

            $errors[$field][] = (string) $issue['message'];
        }

        $layoutIssues = $layout->blockingIssues();
        $layoutCount = count($layoutIssues);
        $readinessCount = (int) $readiness['blocking_count'];
        $total = $layoutCount + $readinessCount;
        $headline = "This template has {$total} issues that must be fixed before publishing.";
        $errors['layout'][] = $headline;

        $exception = ValidationException::withMessages($errors);
        $exception->response = response()->json([
            'code' => RejectInvalidDocumentTemplateLayout::CODE,
            'message' => $headline,
            'issues' => $layoutIssues,
            'errors' => $errors,
        ], 422);

        throw $exception;
    }
}
