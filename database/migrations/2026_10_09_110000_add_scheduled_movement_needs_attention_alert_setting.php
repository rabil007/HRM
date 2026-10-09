<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crew_operations_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('crew_operations_settings', 'alert_scheduled_movement_needs_attention')) {
                $table->boolean('alert_scheduled_movement_needs_attention')
                    ->default(true)
                    ->after('alert_projected_manning_gap');
            }
        });
    }

    public function down(): void
    {
        Schema::table('crew_operations_settings', function (Blueprint $table): void {
            if (Schema::hasColumn('crew_operations_settings', 'alert_scheduled_movement_needs_attention')) {
                $table->dropColumn('alert_scheduled_movement_needs_attention');
            }
        });
    }
};
