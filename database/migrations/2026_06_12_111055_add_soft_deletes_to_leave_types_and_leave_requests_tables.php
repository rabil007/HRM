<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('leave_types') && ! Schema::hasColumn('leave_types', 'deleted_at')) {
            Schema::table('leave_types', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        if (Schema::hasTable('leave_requests') && ! Schema::hasColumn('leave_requests', 'deleted_at')) {
            Schema::table('leave_requests', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('leave_requests') && Schema::hasColumn('leave_requests', 'deleted_at')) {
            Schema::table('leave_requests', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }

        if (Schema::hasTable('leave_types') && Schema::hasColumn('leave_types', 'deleted_at')) {
            Schema::table('leave_types', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
