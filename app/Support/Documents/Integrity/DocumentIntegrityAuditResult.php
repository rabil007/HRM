<?php

namespace App\Support\Documents\Integrity;

use App\Enums\DocumentIntegrityIssueSeverity;

final class DocumentIntegrityAuditResult
{
    public const TABLE_LIMIT = 50;

    /** @var list<DocumentIntegrityIssue> */
    private array $issues = [];

    private int $repaired = 0;

    public function add(DocumentIntegrityIssue $issue): void
    {
        $this->issues[] = $issue;
    }

    public function incrementRepaired(): void
    {
        $this->repaired++;
    }

    /**
     * @return list<DocumentIntegrityIssue>
     */
    public function issues(): array
    {
        return $this->issues;
    }

    public function repaired(): int
    {
        return $this->repaired;
    }

    public function criticalCount(): int
    {
        return $this->countSeverity(DocumentIntegrityIssueSeverity::Critical);
    }

    public function highCount(): int
    {
        return $this->countSeverity(DocumentIntegrityIssueSeverity::High);
    }

    public function warningCount(): int
    {
        return $this->countSeverity(DocumentIntegrityIssueSeverity::Warning);
    }

    public function repairableCount(): int
    {
        return count(array_filter(
            $this->issues,
            fn (DocumentIntegrityIssue $issue): bool => $issue->repairable,
        ));
    }

    /**
     * @return list<DocumentIntegrityIssue>
     */
    public function tableRows(?int $limit = self::TABLE_LIMIT): array
    {
        if ($limit === null) {
            return $this->issues;
        }

        return array_slice($this->issues, 0, $limit);
    }

    /**
     * @return list<DocumentIntegrityIssue>
     */
    public function issuesForEntity(string $entityType, int $entityId): array
    {
        return array_values(array_filter(
            $this->issues,
            fn (DocumentIntegrityIssue $issue): bool => $issue->entityType === $entityType
                && $issue->entityId === $entityId,
        ));
    }

    public function hasCriticalOrHigh(): bool
    {
        return $this->criticalCount() > 0 || $this->highCount() > 0;
    }

    private function countSeverity(DocumentIntegrityIssueSeverity $severity): int
    {
        return count(array_filter(
            $this->issues,
            fn (DocumentIntegrityIssue $issue): bool => $issue->severity === $severity,
        ));
    }
}
