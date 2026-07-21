<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payment date is no longer captured manually at period creation. It is now
 * stamped automatically when payroll is generated, so the column must allow
 * null for periods that have not yet been generated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table): void {
            $table->date('payment_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('payroll_periods')
            ->whereNull('payment_date')
            ->update(['payment_date' => DB::raw('end_date')]);

        Schema::table('payroll_periods', function (Blueprint $table): void {
            $table->date('payment_date')->nullable(false)->change();
        });
    }
};
