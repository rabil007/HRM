<?php

namespace App\Support\CrewPlanning;

/**
 * Aggregate Phase 4 dry-run / apply report for legacy Planned → Crew Planning migration.
 */
final class LegacyPlannedMigrationReport
{
    /**
     * @param  list<LegacyPlannedMigrationCandidate>  $candidates
     * @param  list<int>  $companyIds
     */
    public function __construct(
        public readonly array $candidates,
        public readonly array $companyIds,
        public readonly bool $applied,
        public readonly bool $abortedDueToBlockers,
        public readonly int $namedPlanningCreated,
        public readonly int $existingPlanningReused,
        public readonly int $legacyPlannedRetired,
        public readonly int $remainingPlannedCount,
    ) {}

    public function scanned(): int
    {
        return count($this->candidates);
    }

    public function convertible(): int
    {
        return count(array_filter(
            $this->candidates,
            fn (LegacyPlannedMigrationCandidate $c): bool => in_array($c->migrationStatus, [
                LegacyPlannedMigrationCandidate::STATUS_CONVERTIBLE,
                LegacyPlannedMigrationCandidate::STATUS_MIGRATED,
            ], true) && $c->disposition !== LegacyPlannedMigrationCandidate::DISPOSITION_REUSE_EQUIVALENT,
        ));
    }

    public function alreadyRepresented(): int
    {
        return count(array_filter(
            $this->candidates,
            fn (LegacyPlannedMigrationCandidate $c): bool => $c->disposition === LegacyPlannedMigrationCandidate::DISPOSITION_REUSE_EQUIVALENT
                || ($c->migrationStatus === LegacyPlannedMigrationCandidate::STATUS_ALREADY_REPRESENTED),
        ));
    }

    public function blocked(): int
    {
        return count(array_filter(
            $this->candidates,
            fn (LegacyPlannedMigrationCandidate $c): bool => $c->migrationStatus === LegacyPlannedMigrationCandidate::STATUS_BLOCKED,
        ));
    }

    public function migrated(): int
    {
        return count(array_filter(
            $this->candidates,
            fn (LegacyPlannedMigrationCandidate $c): bool => $c->migrationStatus === LegacyPlannedMigrationCandidate::STATUS_MIGRATED,
        ));
    }

    public function failed(): int
    {
        return count(array_filter(
            $this->candidates,
            fn (LegacyPlannedMigrationCandidate $c): bool => $c->migrationStatus === LegacyPlannedMigrationCandidate::STATUS_FAILED,
        ));
    }

    public function isComplete(): bool
    {
        return $this->applied
            && ! $this->abortedDueToBlockers
            && $this->remainingPlannedCount === 0
            && $this->failed() === 0
            && $this->blocked() === 0;
    }

    /**
     * @return array<string, int|bool>
     */
    public function summary(): array
    {
        return [
            'scanned' => $this->scanned(),
            'convertible' => $this->convertible(),
            'already_represented' => $this->alreadyRepresented(),
            'blocked' => $this->blocked(),
            'migrated' => $this->migrated(),
            'failed' => $this->failed(),
            'named_planning_created' => $this->namedPlanningCreated,
            'existing_planning_reused' => $this->existingPlanningReused,
            'legacy_planned_retired' => $this->legacyPlannedRetired,
            'remaining_planned' => $this->remainingPlannedCount,
            'applied' => $this->applied,
            'aborted_due_to_blockers' => $this->abortedDueToBlockers,
            'complete' => $this->isComplete(),
        ];
    }
}
