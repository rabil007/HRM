<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('permissions')) {
            DB::table('permissions')
                ->whereIn('name', [
                    'payroll.payslips.view',
                    'payroll.payslips.generate',
                    'payroll.payslips.email',
                ])
                ->delete();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            $permissions = [
                'payroll.payslips.view',
                'payroll.payslips.generate',
                'payroll.payslips.email',
            ];

            foreach ($permissions as $name) {
                DB::table('permissions')->insertOrIgnore([
                    'name' => $name,
                    'guard_name' => 'web',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
};
