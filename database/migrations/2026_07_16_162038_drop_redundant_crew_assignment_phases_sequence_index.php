<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crew_assignment_phases', function (Blueprint $table) {
            $table->dropIndex(['crew_assignment_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::table('crew_assignment_phases', function (Blueprint $table) {
            $table->index(['crew_assignment_id', 'sequence']);
        });
    }
};
