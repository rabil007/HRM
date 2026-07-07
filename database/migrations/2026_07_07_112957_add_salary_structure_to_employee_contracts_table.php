<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_contracts', function (Blueprint $table) {
            $table->string('salary_structure', 20)
                ->default('daily')
                ->after('payroll_category');
        });

        DB::table('employee_contracts')
            ->where('payroll_category', 'office')
            ->update(['salary_structure' => 'monthly']);
    }

    public function down(): void
    {
        Schema::table('employee_contracts', function (Blueprint $table) {
            $table->dropColumn('salary_structure');
        });
    }
};
