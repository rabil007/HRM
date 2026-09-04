<?php

namespace App\Support\Documents\Actions;

use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\DocumentTemplateLayoutValidationRun;
use App\Support\Documents\DocumentTemplateLayoutValidationFingerprint;

final class ResolveCurrentDocumentTemplateLayoutValidationRun
{
    public function __construct(
        private DocumentTemplateLayoutValidationFingerprint $fingerprint,
    ) {}

    public function handle(
        DocumentGenerationTemplate $template,
        DocumentGenerationTemplateVersion $version,
        int $companyId,
    ): ?DocumentTemplateLayoutValidationRun {
        if (! $template->isPdfOverlay()) {
            return null;
        }

        $fingerprint = $this->fingerprint->for(
            $template,
            $version,
            $companyId,
            is_array($version->placement_config) ? $version->placement_config : null,
            'sample',
            null,
            true,
        );

        return DocumentTemplateLayoutValidationRun::query()
            ->where('company_id', $companyId)
            ->where('document_generation_template_id', $template->id)
            ->where('document_generation_template_version_id', $version->id)
            ->where('fingerprint', $fingerprint)
            ->where('mode', 'sample')
            ->where('authoritative', true)
            ->orderByDesc('id')
            ->first();
    }
}
