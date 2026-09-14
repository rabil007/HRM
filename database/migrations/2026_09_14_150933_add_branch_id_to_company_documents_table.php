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
        Schema::table('company_documents', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('company_id')->constrained('branches')->nullOnDelete();

            $table->index(['company_id', 'branch_id', 'created_at'], 'idx_company_documents_branch_recent');
            $table->index(['company_id', 'branch_id', 'document_type_id'], 'idx_company_documents_branch_type');
            $table->index(['company_id', 'branch_id', 'expiry_date'], 'idx_company_documents_branch_expiry');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_documents', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropIndex('idx_company_documents_branch_recent');
            $table->dropIndex('idx_company_documents_branch_type');
            $table->dropIndex('idx_company_documents_branch_expiry');
            $table->dropColumn('branch_id');
        });
    }
};
