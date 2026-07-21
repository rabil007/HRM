<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records when payroll was successfully generated or regenerated. This is
 * distinct from payment_date, which records when employees were actually paid
 * and is only set during the Mark as Paid transition.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table): void {
            $table->timestamp('generated_at')->nullable()->after('payment_date');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table): void {
            $table->dropColumn('generated_at');
        });
    }
};
