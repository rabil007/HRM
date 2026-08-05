<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Additive and reversible: adds a nullable foreign key so every
     * employment contract can preserve the visa company that applied
     * during its own effective period. No historical contract rows are
     * modified or deleted by this migration.
     */
    public function up(): void
    {
        Schema::table('employee_contracts', function (Blueprint $table) {
            $table->foreignId('company_visa_type_id')
                ->nullable()
                ->after('payroll_category')
                ->constrained('company_visa_types')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employee_contracts', function (Blueprint $table) {
            $table->dropForeign(['company_visa_type_id']);
            $table->dropColumn('company_visa_type_id');
        });
    }
};
