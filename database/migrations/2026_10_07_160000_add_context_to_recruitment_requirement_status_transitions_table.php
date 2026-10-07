<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_requirement_status_transitions', function (Blueprint $table) {
            $table->json('context')->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('recruitment_requirement_status_transitions', function (Blueprint $table) {
            $table->dropColumn('context');
        });
    }
};
