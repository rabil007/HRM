<?php

namespace App\Support\MasterData;

/**
 * Shared DB readiness assertions used by the artisan readiness command and the
 * destructive Rank-removal migration.
 */
final class RankRemovalGuards
{
    /**
     * @throws \RuntimeException when any check fails
     */
    public static function assertReadyOrFail(?int $companyId = null): void
    {
        $report = (new RankRemovalReadiness)->report($companyId);

        if ($report['ready']) {
            return;
        }

        $failures = collect($report['totals'])
            ->filter(fn (int $count): bool => $count > 0)
            ->map(fn (int $count, string $key): string => "{$key}={$count}")
            ->values()
            ->all();

        throw new \RuntimeException(
            'Rank removal blocked — unresolved Rank→Position coverage: '.implode(', ', $failures).'. '
            .'Run `php artisan master-data:rank-removal-readiness` and resolve all issues before migrating.'
        );
    }
}
