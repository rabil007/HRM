<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prevent deleting a payroll period that still has work allocations.
 * Allocations must be released/reversed (and records handled) before period deletion.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_work_allocations')
            || ! Schema::hasColumn('payroll_work_allocations', 'payroll_period_id')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite test schema rebuilds from migrations; recreate FK via table rebuild is heavy.
            // Fresh create migration still uses cascade for sqlite convenience; document restrict for MySQL.
            return;
        }

        try {
            Schema::table('payroll_work_allocations', function (Blueprint $table): void {
                $table->dropForeign('pwa_period_fk');
            });
        } catch (Throwable) {
            try {
                Schema::table('payroll_work_allocations', function (Blueprint $table): void {
                    $table->dropForeign(['payroll_period_id']);
                });
            } catch (Throwable) {
                // FK may already have been dropped or renamed.
            }
        }

        Schema::table('payroll_work_allocations', function (Blueprint $table): void {
            $table->foreign('payroll_period_id', 'pwa_period_fk')
                ->references('id')
                ->on('payroll_periods')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payroll_work_allocations')
            || ! Schema::hasColumn('payroll_work_allocations', 'payroll_period_id')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        try {
            Schema::table('payroll_work_allocations', function (Blueprint $table): void {
                $table->dropForeign('pwa_period_fk');
            });
        } catch (Throwable) {
            // ignore
        }

        Schema::table('payroll_work_allocations', function (Blueprint $table): void {
            $table->foreign('payroll_period_id', 'pwa_period_fk')
                ->references('id')
                ->on('payroll_periods')
                ->cascadeOnDelete();
        });
    }
};
