<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('leave_types', 'accrual_method')) {
            Schema::table('leave_types', function (Blueprint $table) {
                $table->dropColumn('accrual_method');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('leave_types', 'accrual_method')) {
            Schema::table('leave_types', function (Blueprint $table) {
                $table->enum('accrual_method', ['upfront', 'monthly', 'none'])
                    ->default('upfront')
                    ->after('days_per_year');
            });
        }
    }
};
