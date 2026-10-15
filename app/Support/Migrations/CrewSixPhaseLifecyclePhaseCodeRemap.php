<?php

namespace App\Support\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * One-shot remaps legacy stored crew phase codes to the six-phase lifecycle.
 *
 * Overlapping codes (legacy p3/p4 vs modern p3/p4) make blind retries unsafe.
 * This remapper classifies each dataset before mutating, refuses ambiguous or
 * mixed states, and records a durable completion marker so successful remaps
 * are never reapplied.
 *
 * Obsolete Travel In (p1) and Ready to Join (legacy p3) are never folded into p1a.
 */
final class CrewSixPhaseLifecyclePhaseCodeRemap
{
    public const TEMP_HOME = '__crew_phase_p4_home__';

    public const TEMP_DEMOB = '__crew_phase_p3_demob__';

    public const TEMP_READY = '__crew_phase_ready_to_join__';

    public const TEMP_TRAVEL = '__crew_phase_travel_in__';

    public const STATE_TABLE = 'crew_six_phase_lifecycle_state';

    public const STATE_KEY = 'phase_code_remap';

    public const STATE_VERSION = '1';

    /** @var list<string> */
    public const ALLOWED_PHASE_CODES = [
        'p0',
        'p1a',
        'p1b',
        'p2',
        'p3',
        'p4',
    ];

    /** Codes that existed only in the pre-refactor lifecycle. */
    private const LEGACY_EXCLUSIVE_CODES = [
        'p1',
        'p2a',
        'p2b',
        'p5',
        'p6',
    ];

    /** Codes introduced by the six-phase lifecycle (never stored pre-refactor). */
    private const MODERN_EXCLUSIVE_CODES = [
        'p1a',
        'p1b',
        'p2',
    ];

    /** @var list<string> */
    private const OBSOLETE_STAGING_CODES = [
        self::TEMP_TRAVEL,
        self::TEMP_READY,
    ];

    /** @var list<string> */
    private const FINALIZABLE_STAGING_CODES = [
        self::TEMP_HOME,
        self::TEMP_DEMOB,
    ];

    public function ensureStateTable(): void
    {
        if (Schema::hasTable(self::STATE_TABLE)) {
            return;
        }

        Schema::create(self::STATE_TABLE, function (Blueprint $table): void {
            $table->string('key', 64)->primary();
            $table->string('version', 16);
            $table->timestamp('completed_at');
        });
    }

    public function isComplete(): bool
    {
        if (! Schema::hasTable(self::STATE_TABLE)) {
            return false;
        }

        return DB::table(self::STATE_TABLE)
            ->where('key', self::STATE_KEY)
            ->exists();
    }

    public function markComplete(): void
    {
        $this->ensureStateTable();

        if ($this->isComplete()) {
            return;
        }

        DB::table(self::STATE_TABLE)->insert([
            'key' => self::STATE_KEY,
            'version' => self::STATE_VERSION,
            'completed_at' => now(),
        ]);
    }

    public function remapAllConfiguredTables(): void
    {
        $this->ensureStateTable();

        if ($this->isComplete()) {
            return;
        }

        $shape = $this->classifyAllConfiguredTables();

        match ($shape) {
            'empty', 'modern' => $this->markComplete(),
            'legacy' => $this->runLegacyRemapTransaction(),
            'staging_recoverable' => $this->runStagingFinalizeTransaction(),
            default => throw $this->ambiguousStateException($shape),
        };
    }

    /**
     * @return 'empty'|'legacy'|'modern'|'staging_recoverable'|'staging_blocked'|'mixed'|'ambiguous'
     */
    public function classifyAllConfiguredTables(): string
    {
        $shapes = [];

        foreach ($this->configuredColumns() as [$table, $column]) {
            $shapes[] = $this->classifyColumn($table, $column);
        }

        if ($shapes === []) {
            return 'empty';
        }

        if (in_array('mixed', $shapes, true) || in_array('staging_blocked', $shapes, true)) {
            return in_array('staging_blocked', $shapes, true) ? 'staging_blocked' : 'mixed';
        }

        if (in_array('ambiguous', $shapes, true)) {
            return 'ambiguous';
        }

        if (in_array('staging_recoverable', $shapes, true)) {
            if (in_array('legacy', $shapes, true)) {
                return 'mixed';
            }

            return 'staging_recoverable';
        }

        if (in_array('legacy', $shapes, true)) {
            if (in_array('modern', $shapes, true)) {
                return 'mixed';
            }

            return 'legacy';
        }

        if (in_array('modern', $shapes, true)) {
            return 'modern';
        }

        return 'empty';
    }

    /**
     * @return 'empty'|'legacy'|'modern'|'staging_recoverable'|'staging_blocked'|'mixed'|'ambiguous'
     */
    public function classifyColumn(string $table, string $column): string
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return 'empty';
        }

        $codes = DB::table($table)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->pluck($column)
            ->map(fn ($code): string => (string) $code)
            ->all();

        if ($codes === []) {
            return 'empty';
        }

        $hasObsoleteStaging = count(array_intersect($codes, self::OBSOLETE_STAGING_CODES)) > 0;
        $hasFinalizableStaging = count(array_intersect($codes, self::FINALIZABLE_STAGING_CODES)) > 0;
        $hasLegacyExclusive = count(array_intersect($codes, self::LEGACY_EXCLUSIVE_CODES)) > 0;
        $hasModernExclusive = count(array_intersect($codes, self::MODERN_EXCLUSIVE_CODES)) > 0;

        if ($hasObsoleteStaging) {
            return 'staging_blocked';
        }

        if ($hasFinalizableStaging) {
            if ($hasLegacyExclusive) {
                return 'mixed';
            }

            return 'staging_recoverable';
        }

        if ($hasLegacyExclusive && $hasModernExclusive) {
            return 'mixed';
        }

        if ($hasLegacyExclusive) {
            return 'legacy';
        }

        if ($hasModernExclusive) {
            return 'modern';
        }

        $onlyAllowedOrAmbiguous = collect($codes)->every(
            fn (string $code): bool => in_array($code, self::ALLOWED_PHASE_CODES, true),
        );

        if ($onlyAllowedOrAmbiguous) {
            // p0 / p3 / p4 alone cannot prove modern vs legacy meaning after overlap.
            return 'ambiguous';
        }

        return 'ambiguous';
    }

    public function remapColumn(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $shape = $this->classifyColumn($table, $column);

        match ($shape) {
            'empty', 'modern' => null,
            'legacy' => $this->applyLegacySteps($table, $column),
            'staging_recoverable' => $this->finalizeStagingTemps($table, $column),
            default => throw $this->ambiguousStateException($shape, "{$table}.{$column}"),
        };

        $this->abortIfObsoletePhaseCodesRemain($table, $column);
    }

    public function abortIfObsoletePhaseCodesRemain(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $obsoleteCounts = [];

        foreach ([...self::OBSOLETE_STAGING_CODES, 'p1'] as $code) {
            $count = DB::table($table)->where($column, $code)->count();

            if ($count > 0) {
                $obsoleteCounts[$code] = $count;
            }
        }

        if ($obsoleteCounts === []) {
            return;
        }

        $details = collect($obsoleteCounts)
            ->map(fn (int $count, string $code): string => "{$code}: {$count}")
            ->implode(', ');

        throw new RuntimeException(
            "Cannot complete crew phase lifecycle remap on {$table}.{$column}: obsolete Travel In / Ready to Join phase data remains ({$details}). "
            .'These rows must not be silently converted to Pre-Joining Standby (p1a). '
            .'For disposable local or development test data, verify the database name and host, then run '
            .'`php artisan crew:reset --dry-run` followed by `php artisan crew:reset` (local/development only). '
            .'Protected payroll and master data are outside crew:reset scope; resolve production-like rows manually.'
        );
    }

    public function assertOnlyAllowedPhaseCodes(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $invalid = DB::table($table)
            ->whereNotIn($column, self::ALLOWED_PHASE_CODES)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->count();

        if ($invalid > 0) {
            throw new RuntimeException(
                "Invalid crew phase codes remain on {$table}.{$column} after lifecycle remap ({$invalid} row(s)). "
                .'Allowed codes: '.implode(', ', self::ALLOWED_PHASE_CODES).'. '
                .'Use `php artisan crew:reset --dry-run` on verified disposable databases or correct rows manually.'
            );
        }
    }

    public function assertAllConfiguredTablesOnlyAllowedCodes(): void
    {
        foreach ($this->configuredColumns() as [$table, $column]) {
            $this->assertOnlyAllowedPhaseCodes($table, $column);
        }
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function configuredColumns(): array
    {
        $columns = [];

        foreach ([
            'crew_assignment_phases',
            'crew_timesheet_preparation_lines',
        ] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'phase_code')) {
                $columns[] = [$table, 'phase_code'];
            }
        }

        if (Schema::hasTable('crew_scheduled_movements')) {
            foreach (['expected_current_phase_code', 'expected_result_phase_code'] as $column) {
                if (Schema::hasColumn('crew_scheduled_movements', $column)) {
                    $columns[] = ['crew_scheduled_movements', $column];
                }
            }
        }

        return $columns;
    }

    private function runLegacyRemapTransaction(): void
    {
        DB::transaction(function (): void {
            foreach ($this->configuredColumns() as [$table, $column]) {
                $shape = $this->classifyColumn($table, $column);

                if ($shape === 'empty' || $shape === 'modern') {
                    continue;
                }

                if ($shape !== 'legacy') {
                    throw $this->ambiguousStateException($shape, "{$table}.{$column}");
                }

                $this->applyLegacySteps($table, $column);
                $this->abortIfObsoletePhaseCodesRemain($table, $column);
            }

            $this->assertAllConfiguredTablesOnlyAllowedCodes();
            $this->markComplete();
        });
    }

    private function runStagingFinalizeTransaction(): void
    {
        DB::transaction(function (): void {
            foreach ($this->configuredColumns() as [$table, $column]) {
                $shape = $this->classifyColumn($table, $column);

                if ($shape === 'empty' || $shape === 'modern') {
                    continue;
                }

                if ($shape !== 'staging_recoverable') {
                    throw $this->ambiguousStateException($shape, "{$table}.{$column}");
                }

                $this->finalizeStagingTemps($table, $column);
                $this->abortIfObsoletePhaseCodesRemain($table, $column);
            }

            $this->assertAllConfiguredTablesOnlyAllowedCodes();
            $this->markComplete();
        });
    }

    private function applyLegacySteps(string $table, string $column): void
    {
        $steps = [
            'p6' => self::TEMP_HOME,
            'p5' => self::TEMP_DEMOB,
            'p3' => self::TEMP_READY,
            'p4' => 'p2',
            'p2b' => 'p1b',
            'p2a' => 'p1a',
            'p1' => self::TEMP_TRAVEL,
        ];

        foreach ($steps as $from => $to) {
            DB::table($table)->where($column, $from)->update([$column => $to]);
        }

        $this->finalizeStagingTemps($table, $column);
    }

    private function finalizeStagingTemps(string $table, string $column): void
    {
        DB::table($table)->where($column, self::TEMP_DEMOB)->update([$column => 'p3']);
        DB::table($table)->where($column, self::TEMP_HOME)->update([$column => 'p4']);
    }

    private function ambiguousStateException(string $shape, ?string $scope = null): RuntimeException
    {
        $scopeText = $scope !== null ? " on {$scope}" : '';

        return new RuntimeException(
            "Cannot safely remap crew phase codes{$scopeText}: dataset shape is [{$shape}]. "
            .'Legacy and modern codes overlap (especially p3 and p4), so a partial or already-converted '
            .'dataset must not be remapped again. '
            .'If this is disposable local/development test data, verify the database identity and run '
            .'`php artisan crew:reset --dry-run` then `php artisan crew:reset`. '
            .'Do not delete employees, master data, or protected payroll. '
            .'If historical accuracy matters and obsolete Travel In / Ready to Join rows were previously '
            .'folded into p1a, that mapping is lossy and cannot be reconstructed automatically.'
        );
    }
}
