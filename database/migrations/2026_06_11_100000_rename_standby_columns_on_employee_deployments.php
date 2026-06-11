<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('employee_deployments', function (Blueprint $table) {
            $table->date('join_standby_from')->nullable()->after('arrived_date');
            $table->date('join_standby_to')->nullable()->after('join_standby_from');
            $table->date('leave_standby_from')->nullable()->after('join_standby_to');
            $table->date('leave_standby_to')->nullable()->after('leave_standby_from');
        });

        if (Schema::hasColumn('employee_deployments', 'standby_from')) {
            DB::table('employee_deployments')->update([
                'join_standby_from' => DB::raw('standby_from'),
                'join_standby_to' => DB::raw('standby_to'),
            ]);

            Schema::table('employee_deployments', function (Blueprint $table) {
                $table->dropColumn(['standby_from', 'standby_to']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employee_deployments', function (Blueprint $table) {
            $table->date('standby_from')->nullable()->after('arrived_date');
            $table->date('standby_to')->nullable()->after('standby_from');
        });

        DB::table('employee_deployments')->update([
            'standby_from' => DB::raw('join_standby_from'),
            'standby_to' => DB::raw('join_standby_to'),
        ]);

        Schema::table('employee_deployments', function (Blueprint $table) {
            $table->dropColumn([
                'join_standby_from',
                'join_standby_to',
                'leave_standby_from',
                'leave_standby_to',
            ]);
        });
    }
};
