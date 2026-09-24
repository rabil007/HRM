<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_approval_policies', function (Blueprint $table) {
            $table->string('approval_mode', 32)
                ->default('all_required')
                ->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('leave_approval_policies', function (Blueprint $table) {
            $table->dropColumn('approval_mode');
        });
    }
};
