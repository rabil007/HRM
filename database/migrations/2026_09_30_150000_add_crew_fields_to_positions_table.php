<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->boolean('is_crew_position')->default(true)->after('status');
            $table->unsignedSmallInteger('max_tour_of_duty_days')->nullable()->after('is_crew_position');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->dropColumn(['is_crew_position', 'max_tour_of_duty_days']);
        });
    }
};
