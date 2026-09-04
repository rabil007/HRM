<?php

namespace App\Support\Documents\Actions;

use App\Models\DocumentGenerationTemplate;
use App\Models\DocumentGenerationTemplateVersion;
use App\Models\DocumentTemplateLayoutValidationRun;

final class ValidateDocumentGenerationTemplateDesign
{
    public function __construct(
        private QueueDocumentTemplateLayoutValidation $queueValidation,
    ) {}

    /**
     * @param  array<string, mixed>|null  $placementConfig
     */
    public function handle(
        DocumentGenerationTemplate $template,
        DocumentGenerationTemplateVersion $version,
        int $companyId,
        string $mode,
        ?array $placementConfig,
        ?int $employeeId,
        bool $canPreviewEmployee,
        ?int $requestedBy = null,
    ): DocumentTemplateLayoutValidationRun {
        return $this->queueValidation->handle(
            $template,
            $version,
            $companyId,
            $mode,
            $placementConfig,
            $employeeId,
            $canPreviewEmployee,
            $requestedBy,
        );
    }
}
