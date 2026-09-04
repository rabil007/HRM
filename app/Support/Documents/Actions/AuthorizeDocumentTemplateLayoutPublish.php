<?php

namespace App\Support\Documents\Actions;

use App\Enums\DocumentTemplateLayoutValidationRunStatus;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\DocumentTemplateLayoutValidationRun;
use App\Support\Documents\DocumentTemplateLayoutPreflightResult;
use App\Support\Documents\DocumentTemplateLayoutValidationFingerprint;
use App\Support\Documents\RejectInvalidDocumentTemplateLayout;

final class AuthorizeDocumentTemplateLayoutPublish
{
    public function __construct(
        private DocumentTemplateLayoutValidationFingerprint $fingerprint,
    ) {}

    public function handle(
        DocumentGenerationTemplate $template,
        DocumentGenerationTemplateVersion $version,
        int $companyId,
    ): void {
        $fingerprint = $this->fingerprint->for(
            $template,
            $version,
            $companyId,
            is_array($version->placement_config) ? $version->placement_config : null,
            'sample',
            null,
            true,
        );

        /** @var DocumentTemplateLayoutValidationRun|null $run */
        $run = DocumentTemplateLayoutValidationRun::query()
            ->where('company_id', $companyId)
            ->where('document_generation_template_id', $template->id)
            ->where('document_generation_template_version_id', $version->id)
            ->where('fingerprint', $fingerprint)
            ->where('mode', 'sample')
            ->where('authoritative', true)
            ->orderByDesc('id')
            ->first();

        if ($run === null || $run->status === DocumentTemplateLayoutValidationRunStatus::Stale) {
            RejectInvalidDocumentTemplateLayout::throwRequired();
        }

        if ($run->status->isActive()) {
            RejectInvalidDocumentTemplateLayout::throwPending();
        }

        if ($run->status === DocumentTemplateLayoutValidationRunStatus::Unavailable) {
            RejectInvalidDocumentTemplateLayout::throwUnavailable($this->resultFromRun($run));
        }

        if ($run->status === DocumentTemplateLayoutValidationRunStatus::Invalid) {
            RejectInvalidDocumentTemplateLayout::throw($this->resultFromRun($run));
        }
    }

    private function resultFromRun(DocumentTemplateLayoutValidationRun $run): DocumentTemplateLayoutPreflightResult
    {
        $issues = is_array($run->issues) ? $run->issues : [];
        $sizes = is_array($run->effective_font_sizes) ? $run->effective_font_sizes : [];

        if ($run->status === DocumentTemplateLayoutValidationRunStatus::Unavailable) {
            return DocumentTemplateLayoutPreflightResult::unavailable($issues, $sizes, $run->reference);
        }

        if ($run->status === DocumentTemplateLayoutValidationRunStatus::Valid) {
            return DocumentTemplateLayoutPreflightResult::valid($sizes);
        }

        return DocumentTemplateLayoutPreflightResult::invalid($issues, $sizes);
    }
}
