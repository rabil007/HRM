<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_salary_revisions', function (Blueprint $table) {
            $table->boolean('apply_to_payroll')->default(true)->after('effective_from');
            $table->date('payroll_effective_from')->nullable()->after('apply_to_payroll');
            $table->index(
                ['company_id', 'contract_id', 'apply_to_payroll', 'payroll_effective_from'],
                'idx_contract_salary_revisions_payroll_effective',
            );
        });

        // Existing revisions always affected payroll. Preserve that behavior and
        // use each revision's effective month as its historical payroll month.
        DB::table('contract_salary_revisions')->update([
            'apply_to_payroll' => true,
            'payroll_effective_from' => DB::raw('effective_from'),
        ]);
    }

    public function down(): void
    {
        Schema::table('contract_salary_revisions', function (Blueprint $table) {
            $table->dropIndex('idx_contract_salary_revisions_payroll_effective');
            $table->dropColumn(['apply_to_payroll', 'payroll_effective_from']);
        });
    }
};
