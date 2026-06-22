<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $crewContractIds = DB::table('employee_contracts')
            ->where('payroll_category', 'crew')
            ->whereNull('deleted_at')
            ->pluck('id');

        if ($crewContractIds->isEmpty()) {
            return;
        }

        $standbyComponents = DB::table('contract_salary_components')
            ->whereIn('contract_id', $crewContractIds)
            ->where('component_code', 'STANDBY_RATE')
            ->whereNull('deleted_at')
            ->get(['id', 'company_id', 'contract_id', 'component_name', 'amount', 'status']);

        foreach ($standbyComponents as $component) {
            $existingBasic = DB::table('contract_salary_components')
                ->where('company_id', $component->company_id)
                ->where('contract_id', $component->contract_id)
                ->where('component_code', 'BASIC')
                ->whereNull('deleted_at')
                ->first();

            if ($existingBasic !== null) {
                DB::table('contract_salary_components')
                    ->where('id', $component->id)
                    ->update([
                        'status' => 'inactive',
                        'updated_at' => now(),
                    ]);

                continue;
            }

            DB::table('contract_salary_components')
                ->where('id', $component->id)
                ->update([
                    'component_code' => 'BASIC',
                    'component_name' => 'Basic salary',
                    'rate_type' => 'daily',
                    'updated_at' => now(),
                ]);
        }

        DB::table('contract_salary_components')
            ->whereIn('contract_id', $crewContractIds)
            ->whereIn('component_code', ['STANDBY_RATE', 'ONSITE_RATE'])
            ->whereNull('deleted_at')
            ->update([
                'status' => 'inactive',
                'updated_at' => now(),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Irreversible data normalization.
    }
};
