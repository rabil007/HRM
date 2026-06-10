<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('employee_deployments', 'financial_remarks')) {
            Schema::table('employee_deployments', function (Blueprint $table) {
                $table->dropColumn('financial_remarks');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('employee_deployments', 'financial_remarks')) {
            Schema::table('employee_deployments', function (Blueprint $table) {
                $table->text('financial_remarks')->nullable()->after('remarks');
            });
        }
    }
};
