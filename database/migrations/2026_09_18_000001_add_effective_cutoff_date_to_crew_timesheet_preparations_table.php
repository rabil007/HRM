<?php

use App\Models\CrewTimesheetPreparation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crew_timesheet_preparations')) {
            return;
        }

        Schema::table('crew_timesheet_preparations', function (Blueprint $table): void {
            if (! Schema::hasColumn('crew_timesheet_preparations', 'effective_cutoff_date')) {
                $table->date('effective_cutoff_date')
                    ->nullable()
                    ->after('cutoff_date');
            }
        });

        if (class_exists(CrewTimesheetPreparation::class)) {
            CrewTimesheetPreparation::query()
                ->whereNull('effective_cutoff_date')
                ->with(['payrollPeriod', 'company'])
                ->chunkById(100, function ($preparations): void {
                    foreach ($preparations as $preparation) {
                        DB::table('crew_timesheet_preparations')
                            ->where('id', $preparation->id)
                            ->update([
                                'effective_cutoff_date' => $preparation
                                    ->resolveEffectiveCutoffDate()
                                    ->toDateString(),
                            ]);
                    }
                });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('crew_timesheet_preparations')) {
            return;
        }

        Schema::table('crew_timesheet_preparations', function (Blueprint $table): void {
            if (Schema::hasColumn('crew_timesheet_preparations', 'effective_cutoff_date')) {
                $table->dropColumn('effective_cutoff_date');
            }
        });
    }
};
