<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $columns = ['requires_approval', 'min_days', 'max_days', 'applicable_after'];

            foreach ($columns as $column) {
                if (Schema::hasColumn('leave_types', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            if (! Schema::hasColumn('leave_types', 'requires_approval')) {
                $table->boolean('requires_approval')->default(true)->after('max_carry_days');
            }

            if (! Schema::hasColumn('leave_types', 'min_days')) {
                $table->decimal('min_days', 4, 2)->default(0.5)->after('requires_approval');
            }

            if (! Schema::hasColumn('leave_types', 'max_days')) {
                $table->unsignedInteger('max_days')->nullable()->after('min_days');
            }

            if (! Schema::hasColumn('leave_types', 'applicable_after')) {
                $table->unsignedInteger('applicable_after')->default(0)->after('max_days');
            }
        });
    }
};
