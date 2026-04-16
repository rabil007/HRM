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
        Schema::table('employee_documents', function (Blueprint $table) {
            if (! Schema::hasColumn('employee_documents', 'document_type')) {
                $table->string('document_type', 200)->nullable()->after('type');
                $table->index('document_type', 'idx_emp_doc_type');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employee_documents', function (Blueprint $table) {
            if (Schema::hasColumn('employee_documents', 'document_type')) {
                $table->dropIndex('idx_emp_doc_type');
                $table->dropColumn('document_type');
            }
        });
    }
};
