<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recruitment_requirements', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable()->after('opened_at');
            $table->foreignId('submitted_by')->nullable()->after('submitted_at')
                ->constrained('users', 'id', 'fk_req_submitted_by')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('submitted_by');
            $table->foreignId('approved_by')->nullable()->after('approved_at')
                ->constrained('users', 'id', 'fk_req_approved_by')->nullOnDelete();
            $table->timestamp('returned_at')->nullable()->after('approved_by');
            $table->foreignId('returned_by')->nullable()->after('returned_at')
                ->constrained('users', 'id', 'fk_req_returned_by')->nullOnDelete();
            $table->text('return_reason')->nullable()->after('returned_by');

            $table->index(['company_id', 'submitted_at'], 'idx_recruitment_req_company_submitted_at');
            $table->index(['company_id', 'approved_at'], 'idx_recruitment_req_company_approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('recruitment_requirements', function (Blueprint $table) {
            $table->dropIndex('idx_recruitment_req_company_submitted_at');
            $table->dropIndex('idx_recruitment_req_company_approved_at');
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('returned_by');
            $table->dropColumn([
                'submitted_at',
                'approved_at',
                'returned_at',
                'return_reason',
            ]);
        });
    }
};
