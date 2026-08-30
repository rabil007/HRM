<?php

namespace App\Support\Documents\Integrity;

use App\Enums\DocumentIntegrityIssueSeverity;

final readonly class DocumentIntegrityIssue
{
    public function __construct(
        public string $code,
        public DocumentIntegrityIssueSeverity $severity,
        public int $companyId,
        public string $entityType,
        public int $entityId,
        public ?int $relatedId,
        public bool $repairable,
        public string $summary,
    ) {}

    /**
     * @return array{
     *     code: string,
     *     severity: string,
     *     company_id: int,
     *     entity_type: string,
     *     entity_id: int,
     *     related_id: int|null,
     *     repairable: bool,
     *     summary: string
     * }
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity->value,
            'company_id' => $this->companyId,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'related_id' => $this->relatedId,
            'repairable' => $this->repairable,
            'summary' => $this->summary,
        ];
    }
}
