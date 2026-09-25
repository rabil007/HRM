<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crew_operations_settings', function (Blueprint $table) {
            $table->boolean('allow_future_actual_movement_dates')
                ->default(false)
                ->after('sync_training_to_employee_training');
        });
    }

    public function down(): void
    {
        Schema::table('crew_operations_settings', function (Blueprint $table) {
            $table->dropColumn('allow_future_actual_movement_dates');
        });
    }
};
