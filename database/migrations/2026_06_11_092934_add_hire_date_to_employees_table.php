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
        Schema::table('employees', function (Blueprint $table) {
            $table->date('hire_date')->nullable()->after('date_of_birth');
        });

        $hireDates = DB::table('employee_contracts')
            ->select('employee_id', DB::raw('MIN(start_date) as hire_date'))
            ->groupBy('employee_id')
            ->get();

        foreach ($hireDates as $row) {
            DB::table('employees')
                ->where('id', $row->employee_id)
                ->whereNull('hire_date')
                ->update(['hire_date' => $row->hire_date]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('hire_date');
        });
    }
};
