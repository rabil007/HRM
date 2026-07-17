<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crew_movement_corrections', function (Blueprint $table) {
            $table->index(
                ['company_id', 'status', 'requested_at'],
                'crew_corrections_company_status_requested_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('crew_movement_corrections', function (Blueprint $table) {
            $table->dropIndex('crew_corrections_company_status_requested_index');
        });
    }
};
