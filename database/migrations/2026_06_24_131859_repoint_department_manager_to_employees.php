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
        DB::table('departments')
            ->whereNotNull('manager_id')
            ->orderBy('id')
            ->each(function (object $department): void {
                $employeeId = DB::table('employees')
                    ->where('user_id', $department->manager_id)
                    ->where('company_id', $department->company_id)
                    ->value('id');

                DB::table('departments')
                    ->where('id', $department->id)
                    ->update(['manager_id' => $employeeId]);
            });

        Schema::table('departments', function (Blueprint $table) {
            $table->foreign('manager_id')
                ->references('id')
                ->on('employees')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropForeign(['manager_id']);
        });

        DB::table('departments')
            ->whereNotNull('manager_id')
            ->orderBy('id')
            ->each(function (object $department): void {
                $userId = DB::table('employees')
                    ->where('id', $department->manager_id)
                    ->where('company_id', $department->company_id)
                    ->value('user_id');

                DB::table('departments')
                    ->where('id', $department->id)
                    ->update(['manager_id' => $userId]);
            });
    }
};
