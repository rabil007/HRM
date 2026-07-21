<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distinguishes automatically created rolling payroll periods from manually
 * created ones. `automatic_period_key` is a deterministic, globally unique key
 * (null for manual rows) that makes the rolling automation idempotent and safe
 * under concurrency without blocking manual duplicate periods.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table): void {
            $table->string('creation_source', 20)->default('manual')->after('status');
            $table->string('automatic_period_key')->nullable()->after('creation_source');
            $table->unique('automatic_period_key');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table): void {
            $table->dropUnique(['automatic_period_key']);
            $table->dropColumn(['creation_source', 'automatic_period_key']);
        });
    }
};
