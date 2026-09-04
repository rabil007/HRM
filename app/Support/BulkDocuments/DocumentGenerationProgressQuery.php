<?php

namespace App\Support\BulkDocuments;

use App\Models\BulkDocumentGenerationRun;
use App\Models\DocumentGenerationRun;
use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;

final class DocumentGenerationProgressQuery
{
    public function __construct(
        private DocumentGenerationRunPresenter $presenter = new DocumentGenerationRunPresenter,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function forCurrentUserCustomTemplate(
        int $companyId,
        int $userId,
        DocumentGenerationTemplate $template,
        ?DocumentGenerationTemplateVersion $publishedVersion = null,
    ): ?array {
        if ($userId < 1) {
            return null;
        }

        $activeRun = DocumentGenerationRun::query()
            ->forCompany($companyId)
            ->where('document_generation_template_id', $template->id)
            ->where('triggered_by', $userId)
            ->whereIn('status', ['queued', 'running'])
            ->latest('id')
            ->first();

        if ($activeRun !== null) {
            return $this->presenter->fromCompanyTemplateRun($activeRun);
        }

        $latestRun = DocumentGenerationRun::query()
            ->forCompany($companyId)
            ->where('document_generation_template_id', $template->id)
            ->where('triggered_by', $userId)
            ->when(
                $publishedVersion !== null,
                fn ($query) => $query->where('document_generation_template_version_id', $publishedVersion->id),
            )
            ->latest('id')
            ->first();

        if ($latestRun === null) {
            return null;
        }

        return $this->presenter->fromCompanyTemplateRun($latestRun);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forBuiltIn(int $companyId, string $documentTypeKey): ?array
    {
        $run = BulkDocumentGenerationRun::query()
            ->where('company_id', $companyId)
            ->where('document_type_key', $documentTypeKey)
            ->latest('id')
            ->first();

        if ($run === null) {
            return null;
        }

        return $this->presenter->fromBuiltInRun($run);
    }
}
