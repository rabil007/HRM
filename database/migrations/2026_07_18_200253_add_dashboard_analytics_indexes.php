<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->index(['company_id', 'date'], 'idx_att_company_date');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->index(['company_id', 'created_at'], 'idx_emp_company_created');
        });

        Schema::table('employee_documents', function (Blueprint $table) {
            $table->index(['company_id', 'created_at'], 'idx_emp_docs_company_created');
            $table->index(['company_id', 'expiry_date'], 'idx_emp_docs_company_expiry');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropIndex('idx_att_company_date');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex('idx_emp_company_created');
        });

        Schema::table('employee_documents', function (Blueprint $table) {
            $table->dropIndex('idx_emp_docs_company_created');
            $table->dropIndex('idx_emp_docs_company_expiry');
        });
    }
};
