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
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'hire_date',
                'probation_end_date',
                'contract_type',
                'contract_end_date',
                'labor_contract_id',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->date('hire_date')->after('address');
            $table->date('probation_end_date')->nullable()->after('hire_date');
            $table->enum('contract_type', ['limited', 'unlimited', 'part_time', 'contract'])->default('unlimited')->after('probation_end_date');
            $table->date('contract_end_date')->nullable()->after('contract_type');
            $table->string('labor_contract_id', 100)->nullable()->after('contract_end_date');
        });
    }
};
