<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('crew_timesheets', 'sign_on_standby_from')) {
            Schema::table('crew_timesheets', function (Blueprint $table) {
                $table->date('sign_on_standby_from')->nullable()->after('standby_days');
                $table->date('sign_on_standby_to')->nullable()->after('sign_on_standby_from');
                $table->decimal('sign_on_standby_days', 8, 2)->nullable()->after('sign_on_standby_to');

                $table->date('sign_off_standby_from')->nullable()->after('onsite_days');
                $table->date('sign_off_standby_to')->nullable()->after('sign_off_standby_from');
                $table->decimal('sign_off_standby_days', 8, 2)->nullable()->after('sign_off_standby_to');

                $table->string('source', 32)->nullable()->after('remarks');
                $table->foreignId('crew_timesheet_preparation_id')
                    ->nullable()
                    ->after('source')
                    ->constrained('crew_timesheet_preparations')
                    ->nullOnDelete();
                $table->foreignId('operational_approved_by')
                    ->nullable()
                    ->after('crew_timesheet_preparation_id')
                    ->constrained('users')
                    ->nullOnDelete();
                $table->timestamp('operational_approved_at')->nullable()->after('operational_approved_by');
                $table->string('movement_source_hash', 64)->nullable()->after('operational_approved_at');

                $table->index(['company_id', 'source'], 'ct_company_source_idx');
                $table->index('crew_timesheet_preparation_id', 'ct_preparation_idx');
            });
        }

        $this->backfillDailyLegacyStandbyFields();
    }

    public function down(): void
    {
        if (! Schema::hasColumn('crew_timesheets', 'sign_on_standby_from')) {
            return;
        }

        Schema::table('crew_timesheets', function (Blueprint $table) {
            $table->dropForeign(['crew_timesheet_preparation_id']);
            $table->dropForeign(['operational_approved_by']);

            $table->dropIndex('ct_company_source_idx');
            $table->dropIndex('ct_preparation_idx');

            $table->dropColumn([
                'sign_on_standby_from',
                'sign_on_standby_to',
                'sign_on_standby_days',
                'sign_off_standby_from',
                'sign_off_standby_to',
                'sign_off_standby_days',
                'source',
                'crew_timesheet_preparation_id',
                'operational_approved_by',
                'operational_approved_at',
                'movement_source_hash',
            ]);
        });
    }

    private function backfillDailyLegacyStandbyFields(): void
    {
        if (! Schema::hasColumn('crew_timesheets', 'sign_on_standby_from')) {
            return;
        }

        DB::table('crew_timesheets')
            ->where(function ($query): void {
                $query->whereNotNull('standby_from')
                    ->orWhereNotNull('standby_to')
                    ->orWhereNotNull('standby_days');
            })
            ->whereNull('sign_on_standby_from')
            ->whereNull('sign_on_standby_to')
            ->whereNull('sign_on_standby_days')
            ->whereExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('employee_contracts')
                    ->whereColumn('employee_contracts.employee_id', 'crew_timesheets.employee_id')
                    ->whereColumn('employee_contracts.company_id', 'crew_timesheets.company_id')
                    ->where('employee_contracts.payroll_category', 'crew')
                    ->where('employee_contracts.salary_structure', 'daily');
            })
            ->update([
                'sign_on_standby_from' => DB::raw('standby_from'),
                'sign_on_standby_to' => DB::raw('standby_to'),
                'sign_on_standby_days' => DB::raw('standby_days'),
            ]);
    }
};
