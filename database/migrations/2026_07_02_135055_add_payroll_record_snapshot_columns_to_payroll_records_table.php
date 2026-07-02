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
        Schema::table('payroll_records', function (Blueprint $table) {
            $table->foreignId('contract_id')
                ->nullable()
                ->after('period_id')
                ->constrained('employee_contracts')
                ->restrictOnDelete();
            $table->foreignId('bank_id')
                ->nullable()
                ->after('contract_id')
                ->constrained('banks')
                ->restrictOnDelete();
            $table->foreignId('employee_bank_account_id')
                ->nullable()
                ->after('bank_id')
                ->constrained('employee_bank_accounts')
                ->restrictOnDelete();

            $table->index('contract_id', 'idx_pr_contract');
            $table->index('bank_id', 'idx_pr_bank');
            $table->index('employee_bank_account_id', 'idx_pr_employee_bank_account');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payroll_records', function (Blueprint $table) {
            $table->dropForeign(['contract_id']);
            $table->dropForeign(['bank_id']);
            $table->dropForeign(['employee_bank_account_id']);
            $table->dropIndex('idx_pr_contract');
            $table->dropIndex('idx_pr_bank');
            $table->dropIndex('idx_pr_employee_bank_account');
            $table->dropColumn(['contract_id', 'bank_id', 'employee_bank_account_id']);
        });
    }
};
