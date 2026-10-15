<?php

namespace App\Support\Migrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * One-shot remaps legacy stored crew phase codes to the six-phase lifecycle.
 *
 * Obsolete Travel In (p1) and Ready to Join (legacy p3) are detected after staging
 * and must be cleared with crew:reset (disposable local data) or manual resolution —
 * they are never silently mapped to Pre-Joining Standby (p1a).
 */
final class CrewSixPhaseLifecyclePhaseCodeRemap
{
    public const TEMP_HOME = '__crew_phase_p4_home__';

    public const TEMP_DEMOB = '__crew_phase_p3_demob__';

    public const TEMP_READY = '__crew_phase_ready_to_join__';

    public const TEMP_TRAVEL = '__crew_phase_travel_in__';

    /** @var list<string> */
    public const ALLOWED_PHASE_CODES = [
        'p0',
        'p1a',
        'p1b',
        'p2',
        'p3',
        'p4',
    ];

    /** @var list<string> */
    private const OBSOLETE_STAGING_CODES = [
        self::TEMP_TRAVEL,
        self::TEMP_READY,
    ];

    public function remapAllConfiguredTables(): void
    {
        foreach ([
            'crew_assignment_phases',
            'crew_timesheet_preparation_lines',
        ] as $table) {
            $this->remapColumn($table, 'phase_code');
        }

        if (Schema::hasTable('crew_scheduled_movements')) {
            $this->remapColumn('crew_scheduled_movements', 'expected_current_phase_code');
            $this->remapColumn('crew_scheduled_movements', 'expected_result_phase_code');
        }
    }

    public function remapColumn(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

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

        DB::table($table)->where($column, self::TEMP_DEMOB)->update([$column => 'p3']);
        DB::table($table)->where($column, self::TEMP_HOME)->update([$column => 'p4']);

        $this->abortIfObsoletePhaseCodesRemain($table, $column);
    }

    public function abortIfObsoletePhaseCodesRemain(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $obsoleteCounts = [];

        foreach (self::OBSOLETE_STAGING_CODES as $code) {
            $count = DB::table($table)->where($column, $code)->count();

            if ($count > 0) {
                $obsoleteCounts[$code] = $count;
            }
        }

        $legacyTravelIn = DB::table($table)->where($column, 'p1')->count();

        if ($legacyTravelIn > 0) {
            $obsoleteCounts['p1'] = $legacyTravelIn;
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

    /**
     * Post-migration guard: ensure only finalized six-phase codes exist.
     */
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
        foreach ([
            'crew_assignment_phases',
            'crew_timesheet_preparation_lines',
        ] as $table) {
            $this->assertOnlyAllowedPhaseCodes($table, 'phase_code');
        }

        if (Schema::hasTable('crew_scheduled_movements')) {
            $this->assertOnlyAllowedPhaseCodes('crew_scheduled_movements', 'expected_current_phase_code');
            $this->assertOnlyAllowedPhaseCodes('crew_scheduled_movements', 'expected_result_phase_code');
        }
    }
}
