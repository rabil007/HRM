<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->enum('payroll_category', ['office', 'crew'])->default('crew')->after('company_id');
            $table->unique(
                ['company_id', 'start_date', 'payroll_category'],
                'payroll_periods_company_start_category_unique',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->dropUnique('payroll_periods_company_start_category_unique');
            $table->dropColumn('payroll_category');
        });
    }
};
