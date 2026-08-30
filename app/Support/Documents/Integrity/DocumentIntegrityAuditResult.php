<?php

namespace App\Support\Documents\Integrity;

use App\Enums\DocumentIntegrityIssueSeverity;

final class DocumentIntegrityAuditResult
{
    public const TABLE_LIMIT = 50;

    public const RETAINED_ISSUE_LIMIT = 100;

    /** @var list<DocumentIntegrityIssue> */
    private array $issues = [];

    private int $totalIssueCount = 0;

    private int $criticalCount = 0;

    private int $highCount = 0;

    private int $warningCount = 0;

    private int $repairableCount = 0;

    private int $repaired = 0;

    /** @var (callable(DocumentIntegrityIssue): void)|null */
    private $issueConsumer = null;

    /**
     * Optional streaming consumer invoked for every issue (including those not retained).
     *
     * @param  (callable(DocumentIntegrityIssue): void)|null  $consumer
     */
    public function setIssueConsumer(?callable $consumer): void
    {
        $this->issueConsumer = $consumer;
    }

    public function add(DocumentIntegrityIssue $issue): void
    {
        $this->totalIssueCount++;

        match ($issue->severity) {
            DocumentIntegrityIssueSeverity::Critical => $this->criticalCount++,
            DocumentIntegrityIssueSeverity::High => $this->highCount++,
            DocumentIntegrityIssueSeverity::Warning => $this->warningCount++,
        };

        if ($issue->repairable) {
            $this->repairableCount++;
        }

        if (count($this->issues) < self::RETAINED_ISSUE_LIMIT) {
            $this->issues[] = $issue;
        }

        if ($this->issueConsumer !== null) {
            ($this->issueConsumer)($issue);
        }
    }

    public function incrementRepaired(): void
    {
        $this->repaired++;
    }

    public function totalIssueCount(): int
    {
        return $this->totalIssueCount;
    }

    /**
     * Retained/sample issues only (bounded by RETAINED_ISSUE_LIMIT).
     *
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
        return $this->criticalCount;
    }

    public function highCount(): int
    {
        return $this->highCount;
    }

    public function warningCount(): int
    {
        return $this->warningCount;
    }

    public function repairableCount(): int
    {
        return $this->repairableCount;
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
     * Searches the retained sample only.
     *
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
        return $this->criticalCount > 0 || $this->highCount > 0;
    }
}
