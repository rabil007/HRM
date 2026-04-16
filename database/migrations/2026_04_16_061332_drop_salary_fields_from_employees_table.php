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
                'basic_salary',
                'housing_allowance',
                'transport_allowance',
                'other_allowances',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->decimal('basic_salary', 12, 2)->default(0)->after('address');
            $table->decimal('housing_allowance', 12, 2)->default(0)->after('basic_salary');
            $table->decimal('transport_allowance', 12, 2)->default(0)->after('housing_allowance');
            $table->decimal('other_allowances', 12, 2)->default(0)->after('transport_allowance');
        });
    }
};
