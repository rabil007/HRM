<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TEMP_HOME = '__crew_phase_p4_home__';

    private const TEMP_DEMOB = '__crew_phase_p3_demob__';

    private const TEMP_READY = '__crew_phase_ready_to_join__';

    private const TEMP_TRAVEL = '__crew_phase_travel_in__';

    /**
     * Remap stored crew phase codes to the six-phase lifecycle.
     *
     * Obsolete Travel In (p1) and Ready to Join (old p3) rows are folded into p1a where present.
     */
    public function up(): void
    {
        if (! Schema::hasTable('crew_assignment_phases')) {
            return;
        }

        DB::transaction(function (): void {
            foreach ([
                'crew_assignment_phases',
                'crew_timesheet_preparation_lines',
            ] as $table) {
                $this->remapPhaseCodes($table, 'phase_code');
            }

            if (Schema::hasTable('crew_scheduled_movements')) {
                $this->remapPhaseCodes('crew_scheduled_movements', 'expected_current_phase_code');
                $this->remapPhaseCodes('crew_scheduled_movements', 'expected_result_phase_code');
            }

            foreach ([
                'crew_assignment_phases',
                'crew_timesheet_preparation_lines',
                'crew_scheduled_movements',
            ] as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                $columns = $table === 'crew_scheduled_movements'
                    ? ['expected_current_phase_code', 'expected_result_phase_code']
                    : ['phase_code'];

                foreach ($columns as $column) {
                    if (! Schema::hasColumn($table, $column)) {
                        continue;
                    }

                    DB::table($table)->where($column, self::TEMP_TRAVEL)->update([$column => 'p1a']);
                    DB::table($table)->where($column, self::TEMP_READY)->update([$column => 'p1a']);
                }
            }
        });
    }

    private function remapPhaseCodes(string $table, string $column): void
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
    }

    public function down(): void
    {
        // Irreversible: remapped lifecycle codes cannot be restored safely.
    }
};
