<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            if (Schema::hasColumn('leave_types', 'paid')) {
                $table->dropColumn('paid');
            }

            if (Schema::hasColumn('leave_types', 'uae_statutory')) {
                $table->dropColumn('uae_statutory');
            }

            if (Schema::hasColumn('leave_types', 'gender_specific')) {
                $table->dropColumn('gender_specific');
            }
        });
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            if (! Schema::hasColumn('leave_types', 'paid')) {
                $table->boolean('paid')->default(true)->after('max_days');
            }

            if (! Schema::hasColumn('leave_types', 'uae_statutory')) {
                $table->boolean('uae_statutory')->default(false)->after('paid');
            }

            if (! Schema::hasColumn('leave_types', 'gender_specific')) {
                $table->enum('gender_specific', ['all', 'male', 'female'])->default('all')->after('applicable_after');
            }
        });
    }
};
