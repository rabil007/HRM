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
            if (! Schema::hasColumn('employee_documents', 'issue_date')) {
                $table->date('issue_date')->nullable()->after('file_path');
            }

            if (! Schema::hasColumn('employee_documents', 'document_number')) {
                $table->string('document_number', 120)->nullable()->after('issue_date');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employee_documents', function (Blueprint $table) {
            if (Schema::hasColumn('employee_documents', 'document_number')) {
                $table->dropColumn('document_number');
            }

            if (Schema::hasColumn('employee_documents', 'issue_date')) {
                $table->dropColumn('issue_date');
            }
        });
    }
};
