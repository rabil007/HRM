<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_sea_services', function (Blueprint $table) {
            $table->dropColumn(['vessel_name', 'rank_label']);
            $table->foreignId('vessel_id')->after('sort_order')->constrained('vessels')->restrictOnDelete();
            $table->foreignId('rank_id')->after('vessel_id')->constrained('ranks')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employee_sea_services', function (Blueprint $table) {
            $table->dropForeign(['vessel_id']);
            $table->dropForeign(['rank_id']);
            $table->dropColumn(['vessel_id', 'rank_id']);
            $table->string('vessel_name', 255);
            $table->string('rank_label', 255)->nullable();
        });
    }
};
