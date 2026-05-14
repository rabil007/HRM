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
            $table->string('name', 200)->default('')->after('employee_no');
        });

        DB::table('employees')
            ->select(['id', 'first_name', 'last_name'])
            ->orderBy('id')
            ->chunkById(500, function ($employees) {
                foreach ($employees as $employee) {
                    DB::table('employees')
                        ->where('id', $employee->id)
                        ->update([
                            'name' => trim((string) $employee->first_name.' '.(string) $employee->last_name),
                        ]);
                }
            });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('first_name', 100)->default('')->after('employee_no');
            $table->string('last_name', 100)->default('')->after('first_name');
        });

        DB::table('employees')
            ->select(['id', 'name'])
            ->orderBy('id')
            ->chunkById(500, function ($employees) {
                foreach ($employees as $employee) {
                    [$firstName, $lastName] = array_pad(explode(' ', trim((string) $employee->name), 2), 2, '');

                    DB::table('employees')
                        ->where('id', $employee->id)
                        ->update([
                            'first_name' => $firstName,
                            'last_name' => $lastName,
                        ]);
                }
            });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
