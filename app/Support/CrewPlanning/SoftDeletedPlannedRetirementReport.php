<?php

namespace App\Support\CrewPlanning;

/**
 * Aggregate dry-run / apply report for soft-deleted Planned tombstone retirement.
 */
final class SoftDeletedPlannedRetirementReport
{
    /**
     * @param  list<SoftDeletedPlannedRetirementCandidate>  $candidates
     * @param  list<int>  $companyIds
     */
    public function __construct(
        public readonly array $candidates,
        public readonly array $companyIds,
        public readonly bool $applied,
        public readonly bool $abortedDueToBlockers,
        public readonly int $retiredCount,
        public readonly int $remainingSoftDeletedPlannedCount,
    ) {}

    public function scanned(): int
    {
        return count($this->candidates);
    }

    public function convertible(): int
    {
        return count(array_filter(
            $this->candidates,
            fn (SoftDeletedPlannedRetirementCandidate $c): bool => in_array($c->migrationStatus, [
                SoftDeletedPlannedRetirementCandidate::STATUS_CONVERTIBLE,
                SoftDeletedPlannedRetirementCandidate::STATUS_RETIRED,
            ], true),
        ));
    }

    public function blocked(): int
    {
        return count(array_filter(
            $this->candidates,
            fn (SoftDeletedPlannedRetirementCandidate $c): bool => $c->migrationStatus === SoftDeletedPlannedRetirementCandidate::STATUS_BLOCKED,
        ));
    }

    public function retired(): int
    {
        return count(array_filter(
            $this->candidates,
            fn (SoftDeletedPlannedRetirementCandidate $c): bool => $c->migrationStatus === SoftDeletedPlannedRetirementCandidate::STATUS_RETIRED,
        ));
    }

    public function failed(): int
    {
        return count(array_filter(
            $this->candidates,
            fn (SoftDeletedPlannedRetirementCandidate $c): bool => $c->migrationStatus === SoftDeletedPlannedRetirementCandidate::STATUS_FAILED,
        ));
    }

    /**
     * @return array<string, int|bool>
     */
    public function summary(): array
    {
        return [
            'scanned' => $this->scanned(),
            'convertible' => $this->convertible(),
            'blocked' => $this->blocked(),
            'retired' => $this->retired(),
            'failed' => $this->failed(),
            'remaining_soft_deleted_planned' => $this->remainingSoftDeletedPlannedCount,
            'applied' => $this->applied,
            'aborted_due_to_blockers' => $this->abortedDueToBlockers,
        ];
    }
}
